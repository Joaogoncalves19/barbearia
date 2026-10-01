<?php

namespace App\Modules\Checkout\Services;

use App\Modules\Catalog\Enums\StockMovementKind;
use App\Modules\Catalog\Exceptions\StockRuleViolation;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Services\StockLedger;
use App\Modules\Checkout\Enums\AttendanceSource;
use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Checkout\Exceptions\CheckoutRuleViolation;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Checkout\Models\AttendanceConsumption;
use App\Modules\Checkout\Models\AttendanceDiscount;
use App\Modules\Checkout\Models\AttendanceEvent;
use App\Modules\Checkout\Models\AttendanceItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Finance\Enums\AmountSource;
use App\Modules\Finance\Enums\PaymentKind;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Exceptions\CashRuleViolation;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\CashRegister;
use App\Modules\Finance\Services\ProfessionalLedger;
use App\Modules\Loyalty\Enums\GiftCardStatus;
use App\Modules\Loyalty\Enums\RedemptionStatus;
use App\Modules\Loyalty\Exceptions\PromotionRejected;
use App\Modules\Loyalty\Models\CouponRedemption;
use App\Modules\Loyalty\Models\LoyaltyRedemption;
use App\Modules\Loyalty\Pricing\PromotionCandidate;
use App\Modules\Loyalty\Pricing\PromotionEngine;
use App\Modules\Loyalty\Pricing\PromotionRequest;
use App\Modules\Loyalty\Services\GiftCards;
use App\Modules\Loyalty\Services\LoyaltyLedger;
use App\Modules\Loyalty\Services\PromotionService;
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Enums\DiscountType;
use App\Modules\Scheduling\Enums\AdjustmentKind;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Enums\ItemType;
use App\Modules\Scheduling\Enums\PriceSource;
use App\Modules\Scheduling\Exceptions\BookingRuleViolation;
use App\Modules\Scheduling\Exceptions\SlotUnavailable;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\BookingRequest;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\Shared\Pricing\Discount;
use App\Modules\Shared\Support\Money;
use App\Modules\Team\Models\Professional;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * As UNICAS regras do atendimento (atendimento.md): abrir (do agendamento ou
 * encaixe, que tambem e um agendamento e ocupa a agenda), iniciar, itens, consumo, desconto, profissional, cancelar e
 * CONCLUIR. Nenhuma tela grava atendimento, pagamento, caixa ou estoque por
 * conta propria.
 *
 * Transacoes e travas: toda operacao roda numa transacao cuja PRIMEIRA
 * escrita e a linha do atendimento (version + 1). A conclusao trava, nesta
 * ordem, o atendimento, o caixa aberto e os produtos (id crescente); o
 * estorno trava atendimento e caixa. Ordem fixa = sem impasse.
 *
 * Conclusao (tudo ou nada): valida estado e itens, congela descontos e
 * totais, confere que os pagamentos fecham exatamente com o total, baixa o
 * estoque (venda e consumo, vinculados ao atendimento), grava pagamentos e
 * as entradas no caixa, conclui o agendamento de origem e registra o
 * historico, e lanca a comissao e a gorjeta do profissional (Fase 7).
 * Qualquer falha desfaz tudo.
 *
 * Idempotencia: a conclusao carrega a chave do formulario (completion_key).
 * Repetir a mesma requisicao (duplo clique) devolve o atendimento ja
 * concluido, sem segundo pagamento, entrada no caixa ou baixa de estoque.
 */
final class AttendanceService
{
    /** Gancho SO para testes: roda depois de pagamentos e caixa, antes de concluir. */
    public static ?Closure $beforeFinish = null;

    public const MAX_QUANTITY = 99;

    public function __construct(
        private readonly AttendancePricing $pricing,
        private readonly StockLedger $stock,
        private readonly CashRegister $cash,
        private readonly BookingService $booking,
        private readonly ProfessionalLedger $ledger,
        private readonly PromotionService $promotions,
        private readonly LoyaltyLedger $loyalty,
        private readonly GiftCards $giftCards,
    ) {}

    /**
     * Abre o atendimento de um agendamento (cliente chegou). Repetir devolve o
     * atendimento ja aberto.
     */
    public function openFromAppointment(Appointment $appointment, User $actor): Attendance
    {
        $existente = $appointment->attendance()->first();
        if ($existente !== null) {
            return $existente;
        }

        try {
            return DB::transaction(function () use ($appointment, $actor): Attendance {
                DB::table('appointments')->where('id', $appointment->id)->update(['updated_at' => now()]);
                $a = Appointment::query()->with(['items', 'adjustments'])->findOrFail($appointment->id);

                if (! in_array($a->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], true)) {
                    throw new CheckoutRuleViolation('appointment_not_open');
                }
                if ($a->starts_at === null || BusinessTime::dateOf($a->starts_at) !== BusinessTime::today()) {
                    throw new CheckoutRuleViolation('appointment_not_today');
                }
                if ($a->status === AppointmentStatus::Pending) {
                    $this->booking->confirm($a, $actor);
                }

                return $this->createFromAppointment($a, AttendanceSource::Appointment, 'Atendimento aberto a partir do agendamento '.$a->code.'.', $actor);
            });
        } catch (QueryException $e) {
            // Dois cliques simultaneos: a sentinela active_appointment_id barra o segundo.
            $existente = $appointment->attendance()->first();
            if ($existente !== null) {
                return $existente;
            }
            throw $e;
        }
    }

    /**
     * Encaixe: cliente chegou sem hora marcada (cadastrado ou so nome e
     * telefone). O encaixe e um AGENDAMENTO de origem "encaixe", reservado
     * pelo mesmo BookingService::book da agenda (mesma regra de
     * disponibilidade, mesmo conflito, mesma trava da agenda do
     * profissional), comecando no proximo ponto da grade
     * (BusinessTime::nextStart) e com a duracao do servico. O atendimento
     * nasce dele na MESMA transacao. Horario ocupado, fora do expediente,
     * folga, pausa ou bloqueio: recusado, nada gravado.
     *
     * @throws CheckoutRuleViolation|SlotUnavailable|BookingRuleViolation
     */
    public function openWalkIn(Service $service, Professional $professional, ?Customer $customer, ?string $contactName, ?string $contactPhone, User $actor): Attendance
    {
        if ($customer === null && mb_strlen(trim((string) $contactName)) < 2) {
            throw new CheckoutRuleViolation('contact_required');
        }

        return DB::transaction(function () use ($service, $professional, $customer, $contactName, $contactPhone, $actor): Attendance {
            $a = $this->booking->book(new BookingRequest(
                service: $service,
                professional: $professional,
                start: BusinessTime::nextStart(),
                channel: Channel::Staff,
                source: AppointmentSource::WalkIn,
                customer: $customer,
                contactName: $customer === null ? trim((string) $contactName) : null,
                contactPhone: $customer === null && $contactPhone !== null && trim($contactPhone) !== '' ? mb_substr(trim($contactPhone), 0, 32) : null,
                actor: $actor,
            ));
            $a->load(['items', 'adjustments']);

            return $this->createFromAppointment($a, AttendanceSource::WalkIn, 'Atendimento aberto (encaixe '.$a->code.').', $actor);
        });
    }

    /**
     * O atendimento nasce do agendamento: mesmo cliente, mesmo profissional,
     * mesmos itens com o preco fotografado, mesmos descontos.
     */
    private function createFromAppointment(Appointment $a, AttendanceSource $source, string $description, User $actor): Attendance
    {
        $pro = $a->professional_id !== null ? Professional::withTrashed()->find($a->professional_id) : null;
        if ($pro === null) {
            throw new CheckoutRuleViolation('appointment_without_professional');
        }

        $at = Attendance::query()->create([
            'source' => $source,
            'appointment_id' => $a->id,
            'active_appointment_id' => $a->id,
            'customer_id' => $a->customer_id,
            'customer_name' => $a->customer_name,
            'customer_phone' => $a->customer_phone,
            'professional_id' => $pro->id,
            'professional_name' => $pro->display_name,
            'status' => AttendanceStatus::Open,
            'opened_at' => BusinessTime::now(),
            'opened_by_user_id' => $actor->id,
        ]);

        foreach ($a->items as $item) {
            $at->items()->create([
                'item_type' => $item->item_type,
                'service_id' => $item->service_id,
                'package_id' => $item->package_id,
                'product_id' => $item->product_id,
                'appointment_item_id' => $item->id,
                'name' => $item->name,
                'quantity' => $item->quantity ?? 1,
                'unit_price_cents' => $item->unit_price_cents,
                'duration_minutes' => $item->duration_minutes,
                'price_source' => $item->price_source ?? PriceSource::CatalogAtBooking,
                'cost_cents' => $item->cost_cents,
                'added_by_user_id' => $actor->id,
            ]);
        }
        foreach ($a->adjustments as $adj) {
            // Fase 8: copia a REGRA (percentual ou valor) e a reserva de cupom/
            // pontos; reserva ja liberada (desconto trocado num atendimento
            // cancelado) nao volta.
            if ($adj->amount_cents <= 0 || $this->promotionReleased($adj->coupon_redemption_id, $adj->loyalty_redemption_id)) {
                continue;
            }
            $tipo = $adj->discount_type ?? DiscountType::Fixed;
            $at->discounts()->create([
                'kind' => $adj->kind,
                'type' => $tipo,
                'percent_bp' => $tipo === DiscountType::Percent ? $adj->percent_bp : null,
                'fixed_cents' => $tipo === DiscountType::Fixed ? ($adj->fixed_cents ?? $adj->amount_cents) : null,
                'base_cents' => 0,
                'amount_cents' => 0,
                'reason' => $adj->description ?? 'Desconto do agendamento',
                'coupon_redemption_id' => $adj->coupon_redemption_id,
                'loyalty_redemption_id' => $adj->loyalty_redemption_id,
            ]);
        }
        $this->pricing->refreshDiscounts($at);

        $this->event($at, 'opened', $description, $actor);

        return $at;
    }

    /** Aberto -> em atendimento (cliente na cadeira). */
    public function start(Attendance $attendance, User $actor): Attendance
    {
        return $this->mutate($attendance, function (Attendance $at) use ($actor): void {
            if (! $at->status->canTransitionTo(AttendanceStatus::InProgress)) {
                throw new CheckoutRuleViolation('invalid_status');
            }
            $at->status = AttendanceStatus::InProgress;
            $at->forceFill(['started_at' => BusinessTime::now()]);
            $at->save();
            $this->event($at, 'started', 'Atendimento iniciado.', $actor);
        });
    }

    public function addService(Attendance $attendance, Service $service, User $actor): Attendance
    {
        return $this->mutate($attendance, function (Attendance $at) use ($service, $actor): void {
            $this->assertEditable($at);
            $srv = Service::query()->findOrFail($service->id);
            $pro = Professional::withTrashed()->findOrFail($at->professional_id);
            $this->assertCanPerform($pro, $srv);
            $this->createServiceItem($at, $srv, $actor);
            $this->pricing->refreshDiscounts($at);
            $this->event($at, 'item_added', 'Serviço incluído: '.$srv->name.'.', $actor, ['preco_cents' => $srv->price_cents]);
        });
    }

    /** Produto VENDIDO ao cliente (entra na conta e baixa o estoque na conclusao). */
    public function addProduct(Attendance $attendance, Product $product, int $quantity, User $actor): Attendance
    {
        $this->assertQuantity($quantity);

        return $this->mutate($attendance, function (Attendance $at) use ($product, $quantity, $actor): void {
            $this->assertEditable($at);
            $p = Product::query()->findOrFail($product->id);
            if (! $p->is_active) {
                throw new CheckoutRuleViolation('product_inactive');
            }
            if (! $p->isForSale()) {
                throw new CheckoutRuleViolation('not_for_sale');
            }
            $at->items()->create([
                'item_type' => ItemType::Product,
                'product_id' => $p->id,
                'name' => $p->name,
                'quantity' => $quantity,
                'unit_price_cents' => $p->price_cents,
                'price_source' => PriceSource::CatalogAtAttendance,
                'cost_cents' => $p->cost_cents,
                'added_by_user_id' => $actor->id,
            ]);
            $this->pricing->refreshDiscounts($at);
            $this->event($at, 'item_added', "Produto incluído: {$quantity} × {$p->name}.", $actor, ['preco_cents' => $p->price_cents]);
        });
    }

    public function removeItem(Attendance $attendance, AttendanceItem $item, User $actor): Attendance
    {
        return $this->mutate($attendance, function (Attendance $at) use ($item, $actor): void {
            $this->assertEditable($at);
            $linha = AttendanceItem::query()->whereKey($item->id)->where('attendance_id', $at->id)->first()
                ?? throw new CheckoutRuleViolation('not_in_attendance');
            $linha->delete();
            $this->pricing->refreshDiscounts($at);
            $this->event($at, 'item_removed', 'Item retirado: '.$linha->name.'.', $actor);
        });
    }

    /** Material USADO no servico (nao cobrado; baixa o estoque na conclusao). */
    public function addConsumption(Attendance $attendance, Product $product, int $quantity, User $actor): Attendance
    {
        $this->assertQuantity($quantity);

        return $this->mutate($attendance, function (Attendance $at) use ($product, $quantity, $actor): void {
            $this->assertEditable($at);
            $p = Product::query()->findOrFail($product->id);
            if (! $p->is_active) {
                throw new CheckoutRuleViolation('product_inactive');
            }
            $at->consumptions()->create([
                'product_id' => $p->id,
                'product_name' => $p->name,
                'quantity' => $quantity,
                'unit_cost_cents' => $p->cost_cents,
                'added_by_user_id' => $actor->id,
            ]);
            $this->event($at, 'consumption_added', "Consumo registrado: {$quantity} × {$p->name}.", $actor);
        });
    }

    public function removeConsumption(Attendance $attendance, AttendanceConsumption $consumption, User $actor): Attendance
    {
        return $this->mutate($attendance, function (Attendance $at) use ($consumption, $actor): void {
            $this->assertEditable($at);
            $linha = AttendanceConsumption::query()->whereKey($consumption->id)->where('attendance_id', $at->id)->first()
                ?? throw new CheckoutRuleViolation('not_in_attendance');
            $linha->delete();
            $this->event($at, 'consumption_removed', 'Consumo retirado: '.$linha->product_name.'.', $actor);
        });
    }

    /**
     * Desconto manual (um por atendimento: aplicar de novo substitui).
     * Motivo obrigatorio; o valor e calculado pelo PriceBreakdown.
     */
    public function applyDiscount(Attendance $attendance, Discount $rule, string $reason, User $actor): Attendance
    {
        $motivo = trim($reason);
        if (mb_strlen($motivo) < 3) {
            throw new CheckoutRuleViolation('reason_required');
        }

        return $this->mutate($attendance, function (Attendance $at) use ($rule, $motivo, $actor): void {
            $this->assertEditable($at);
            $atuais = $at->discounts()->get();
            if ($atuais->every(fn (AttendanceDiscount $x) => $x->kind === AdjustmentKind::Manual)) {
                // Corrigir o proprio desconto manual: substitui sempre.
                $atuais->each->delete();
                $at->discounts()->create([
                    'kind' => AdjustmentKind::Manual,
                    'type' => $rule->type,
                    'percent_bp' => $rule->type === DiscountType::Percent ? $rule->value : null,
                    'fixed_cents' => $rule->type === DiscountType::Fixed ? $rule->value : null,
                    'base_cents' => 0,
                    'amount_cents' => 0,
                    'reason' => mb_substr($motivo, 0, 255),
                    'applied_by_user_id' => $actor->id,
                ]);
            } else {
                // Ha promocao (cupom, pontos, aniversario...): um desconto so,
                // vale o maior (R-10, decisao do dono). Manual maior substitui e
                // libera a reserva; menor ou igual e recusado.
                $linhas = PromotionEngine::linesFrom($at->items()->get());
                $manual = new PromotionCandidate(AdjustmentKind::Manual, $rule, PromotionEngine::amountFor($rule, $linhas), 'Desconto manual ('.$rule->label().')');
                try {
                    $this->promotions->applyToAttendance($at, PromotionRequest::none(), $manual, $actor->id, mb_substr($motivo, 0, 255));
                } catch (PromotionRejected $e) {
                    throw new CheckoutRuleViolation('promotion', $e->getMessage());
                }
            }
            $this->pricing->refreshDiscounts($at);
            $d = $at->discounts()->sole();
            $this->event($at, 'discount_applied', 'Desconto de '.$rule->label().' aplicado.', $actor, [
                'antes_cents' => $d->base_cents, 'desconto_cents' => $d->amount_cents,
                'depois_cents' => $d->base_cents - $d->amount_cents, 'motivo' => $motivo,
            ]);
        });
    }

    /**
     * Cupom ou pontos no balcao (Fase 8). Um desconto so, vale o maior: se o
     * pedido for maior que o atual, substitui (e libera a reserva do atual);
     * senao, e recusado com o motivo.
     *
     * @throws CheckoutRuleViolation
     */
    public function applyPromotion(Attendance $attendance, PromotionRequest $request, User $actor): Attendance
    {
        if ($request->isEmpty()) {
            throw new CheckoutRuleViolation('promotion', 'Informe o cupom ou marque o uso de pontos.');
        }

        return $this->mutate($attendance, function (Attendance $at) use ($request, $actor): void {
            $this->assertEditable($at);
            if ($at->customer_id === null) {
                throw new CheckoutRuleViolation('customer_required');
            }
            try {
                $c = $this->promotions->applyToAttendance($at, $request, null, $actor->id);
            } catch (PromotionRejected $e) {
                throw new CheckoutRuleViolation('promotion', $e->getMessage());
            }
            $this->pricing->refreshDiscounts($at);
            $this->promotions->audit($at, $c, $actor);
            $this->event($at, 'promotion_applied', $c->label.' aplicado.', $actor, ['desconto_cents' => $at->discounts()->sum('amount_cents')]);
        });
    }

    public function removeDiscount(Attendance $attendance, AttendanceDiscount $discount, User $actor): Attendance
    {
        return $this->mutate($attendance, function (Attendance $at) use ($discount, $actor): void {
            $this->assertEditable($at);
            $linha = AttendanceDiscount::query()->whereKey($discount->id)->where('attendance_id', $at->id)->first()
                ?? throw new CheckoutRuleViolation('not_in_attendance');
            $this->promotions->releaseFor($linha->coupon_redemption_id, $linha->loyalty_redemption_id, 'Desconto retirado no atendimento '.$at->code);
            $linha->delete();
            $this->pricing->refreshDiscounts($at);
            $this->event($at, 'discount_removed', 'Desconto retirado.', $actor, ['desconto_cents' => $linha->amount_cents]);
        });
    }

    /**
     * Quem efetivamente atende (pode nao ser quem estava agendado). A agenda
     * acompanha: o agendamento de origem e remarcado para o novo profissional
     * pelo BookingService (mesma regra de disponibilidade), no horario
     * combinado ou, se ele ja passou, a partir do proximo ponto da grade. Se
     * o novo profissional nao estiver livre, nada muda.
     *
     * @throws CheckoutRuleViolation|SlotUnavailable|BookingRuleViolation
     */
    public function changeProfessional(Attendance $attendance, Professional $professional, User $actor): Attendance
    {
        return $this->mutate($attendance, function (Attendance $at) use ($professional, $actor): void {
            $this->assertEditable($at);
            $pro = Professional::query()->findOrFail($professional->id);
            if (! $pro->is_active) {
                throw new CheckoutRuleViolation('professional_inactive');
            }
            if ($pro->id === $at->professional_id) {
                return;
            }
            $ag = $at->appointment_id !== null ? Appointment::query()->find($at->appointment_id) : null;
            if ($ag !== null && $ag->status->isOpen() && $ag->professional_id !== $pro->id) {
                $inicio = CarbonImmutable::instance($ag->starts_at)->max(BusinessTime::nextStart());
                $this->booking->reschedule($ag, $inicio, $pro, Channel::Staff, $actor, byAttendance: true);
            }
            $de = $at->professional_name;
            $at->professional_id = $pro->id;
            $at->professional_name = $pro->display_name;
            $at->save();
            $this->event($at, 'professional_changed', 'Profissional alterado.', $actor, ['de' => $de, 'para' => $pro->display_name]);
        });
    }

    public function updateNotes(Attendance $attendance, ?string $notes, User $actor): Attendance
    {
        return $this->mutate($attendance, function (Attendance $at) use ($notes): void {
            $this->assertEditable($at);
            $at->notes = $notes !== null && trim($notes) !== '' ? mb_substr(trim($notes), 0, 1000) : null;
            $at->save();
        });
    }

    /**
     * Cancela antes da conclusao (desistencia). Nada foi cobrado nem baixado
     * do estoque (isso so acontece na conclusao). Agendamento de origem:
     * continua como esta (a equipe decide se foi falta ou cancelamento).
     * Encaixe: o agendamento existia so por causa do atendimento e e
     * cancelado junto, liberando o horario na agenda.
     */
    public function cancel(Attendance $attendance, string $reason, User $actor): Attendance
    {
        $motivo = trim($reason);
        if (mb_strlen($motivo) < 3) {
            throw new CheckoutRuleViolation('reason_required');
        }

        return $this->mutate($attendance, function (Attendance $at) use ($motivo, $actor): void {
            if (! $at->status->canTransitionTo(AttendanceStatus::Cancelled)) {
                throw new CheckoutRuleViolation('invalid_status');
            }
            $at->status = AttendanceStatus::Cancelled;
            $at->active_appointment_id = null;
            $at->forceFill(['cancelled_at' => BusinessTime::now()]);
            $at->cancelled_by_user_id = $actor->id;
            $at->cancellation_reason = mb_substr($motivo, 0, 255);
            $at->save();
            $this->event($at, 'cancelled', 'Atendimento cancelado.', $actor, ['motivo' => $motivo]);

            if ($at->source === AttendanceSource::WalkIn && $at->appointment_id !== null) {
                $ag = Appointment::query()->find($at->appointment_id);
                if ($ag !== null && $ag->status->canTransitionTo(AppointmentStatus::Cancelled)) {
                    $this->booking->cancel($ag, Channel::Staff, $actor, 'Encaixe cancelado: '.$motivo);
                }
            }
        });
    }

    /**
     * CONCLUI o atendimento (ver o cabecalho da classe).
     *
     * @param  list<PaymentLine>  $payments
     *
     * @throws CheckoutRuleViolation|CashRuleViolation|StockRuleViolation
     */
    public function complete(Attendance $attendance, array $payments, string $key, User $actor): Attendance
    {
        foreach ($payments as $p) {
            if ($p->method === PaymentMethod::Unknown || $p->amountCents < 0 || $p->tipCents < 0 || $p->amountCents + $p->tipCents <= 0) {
                throw new CheckoutRuleViolation('invalid_payment');
            }
            if ($p->isGiftCard() && ($p->tipCents > 0 || trim((string) $p->giftCardCode) === '')) {
                throw new CheckoutRuleViolation('invalid_gift_card_line');
            }
        }

        return DB::transaction(function () use ($attendance, $payments, $key, $actor): Attendance {
            $at = $this->lock($attendance->id);

            if ($at->status === AttendanceStatus::Completed && $at->completion_key === $key) {
                return $at; // repeticao da mesma conclusao (duplo clique)
            }
            if ($at->status !== AttendanceStatus::InProgress) {
                throw new CheckoutRuleViolation($at->status === AttendanceStatus::Open ? 'not_started' : 'invalid_status');
            }

            $itens = $at->items()->get();
            $consumos = $at->consumptions()->get();
            if ($itens->isEmpty()) {
                throw new CheckoutRuleViolation('no_items');
            }

            // 1) Valores: descontos recalculados e congelados.
            $b = $this->pricing->refreshDiscounts($at);
            if ($b->total === null || $b->subtotal === null || $b->discount === null) {
                throw new CheckoutRuleViolation('unknown_price');
            }
            $pago = array_sum(array_map(fn (PaymentLine $p) => $p->amountCents, $payments));
            if ($pago !== $b->total->cents) {
                throw new CheckoutRuleViolation('payment_mismatch', 'Total: '.$b->total->format().'; informado: '.Money::fromCents($pago)->format().'.');
            }
            $gorjeta = array_sum(array_map(fn (PaymentLine $p) => $p->tipCents, $payments));

            // 2) Caixa: todo pagamento entra no caixa aberto, menos o vale-
            //    presente (o dinheiro dele entrou na venda; Fase 8).
            $emDinheiro = array_filter($payments, fn (PaymentLine $p) => ! $p->isGiftCard());
            $caixa = $emDinheiro !== [] ? $this->cash->lockOpen() : null;

            // 3) Estoque: venda e consumo, travando os produtos em ordem de id.
            $baixas = [];
            foreach ($itens as $i) {
                if ($i->item_type === ItemType::Product && $i->product_id !== null) {
                    $baixas[] = [$i->product_id, $i->quantity, StockMovementKind::Sale, $i->cost_cents];
                }
            }
            foreach ($consumos as $c) {
                $baixas[] = [$c->product_id, $c->quantity, StockMovementKind::Consumption, $c->unit_cost_cents];
            }
            $this->stock->lock(array_map(fn ($x) => $x[0], $baixas));
            foreach ($baixas as [$produtoId, $qtd, $tipo, $custo]) {
                $this->stock->write(Product::withTrashed()->findOrFail($produtoId), -$qtd, $tipo, 'Atendimento '.$at->code, $actor, [
                    'attendance_id' => $at->id,
                    'unit_cost_cents' => $custo,
                ]);
            }

            // 4) Pagamentos e entradas no caixa. Vale-presente: uso unico,
            //    travado e conferido aqui; nao entra na gaveta.
            $gravados = [];
            foreach ($payments as $linha) {
                $vale = null;
                if ($linha->isGiftCard()) {
                    try {
                        $vale = $this->giftCards->checkForPayment((string) $linha->giftCardCode, $linha->amountCents, $b->total->cents);
                    } catch (PromotionRejected $e) {
                        throw new CheckoutRuleViolation('promotion', $e->getMessage());
                    }
                }
                $gravados[] = $pg = Payment::query()->create([
                    'attendance_id' => $at->id,
                    'customer_id' => $at->customer_id,
                    'cash_session_id' => $vale === null ? $caixa?->id : null,
                    'kind' => PaymentKind::Payment,
                    'method' => $linha->method,
                    'amount_cents' => $linha->amountCents,
                    'tip_cents' => $linha->tipCents,
                    'amount_source' => AmountSource::Recorded,
                    'paid_at' => BusinessTime::now(),
                    'received_by_user_id' => $actor->id,
                    'received_by_label' => $actor->name,
                    'gift_card_id' => $vale?->id,
                ]);
                if ($vale !== null) {
                    $vale->forceFill(['status' => GiftCardStatus::Redeemed, 'redeemed_at' => BusinessTime::now(), 'redeemed_attendance_id' => $at->id, 'redeemed_appointment_id' => $at->appointment_id])->save();
                } elseif ($caixa !== null) {
                    $this->cash->recordPayment($caixa, $pg, 'Atendimento '.$at->code.' · '.$at->customer_name, $actor);
                }
            }

            if (self::$beforeFinish !== null) {
                (self::$beforeFinish)();
            }

            // 5) Atendimento concluido com os valores congelados.
            $at->status = AttendanceStatus::Completed;
            $at->forceFill(['completed_at' => BusinessTime::now()]);
            $at->completed_by_user_id = $actor->id;
            $at->completion_key = $key;
            $at->subtotal_cents = $b->subtotal->cents;
            $at->discount_cents = $b->discount->cents;
            $at->total_cents = $b->total->cents;
            $at->tip_cents = $gorjeta;
            $at->save();

            // 6) Agendamento de origem: concluido.
            if ($at->appointment_id !== null) {
                $this->booking->complete(Appointment::query()->findOrFail($at->appointment_id), $actor, $at->code);
            }

            // 7) Comissao (por item, regra fotografada) e gorjeta (por pagamento)
            //    do profissional que atendeu. Mesma transacao: tudo ou nada.
            $this->ledger->recordCompletion($at, $gravados);

            // 8) Promocoes (Fase 8): cupom conta o uso, pontos do resgate saem
            //    do saldo, reservas nao usadas sao liberadas; pontos ganhos e
            //    bonus de indicacao. Mesma transacao.
            try {
                $this->promotions->finalizeForAttendance($at);
            } catch (PromotionRejected $e) {
                throw new CheckoutRuleViolation('promotion', $e->getMessage());
            }
            $this->loyalty->recordCompletion($at);

            $this->event($at, 'completed', 'Atendimento concluído.', $actor, [
                'subtotal_cents' => $b->subtotal->cents,
                'desconto_cents' => $b->discount->cents,
                'total_cents' => $b->total->cents,
                'gorjeta_cents' => $gorjeta,
                'pagamentos' => implode(', ', array_map(fn (PaymentLine $p) => $p->method->label().' '.Money::fromCents($p->amountCents)->format(), $payments)),
            ]);

            return $at;
        });
    }

    /**
     * Registra um fato na linha do tempo do atendimento (usado tambem pelo
     * estorno e pela devolucao ao estoque).
     *
     * @param  array<string, scalar|null>  $data
     */
    public function event(Attendance $at, string $type, string $description, ?User $actor, array $data = []): void
    {
        AttendanceEvent::query()->create([
            'attendance_id' => $at->id,
            'type' => $type,
            'description' => mb_substr($description, 0, 255),
            'actor_label' => $actor !== null ? $actor->name.' (equipe)' : 'Sistema',
            'data' => $data ?: null,
            'occurred_at' => BusinessTime::now(),
        ]);
    }

    /**
     * Primeira escrita da transacao: trava a linha do atendimento e o relê.
     */
    public function lock(int $attendanceId): Attendance
    {
        DB::table('attendances')->where('id', $attendanceId)->increment('version');

        return Attendance::query()->findOrFail($attendanceId);
    }

    /**
     * @param  Closure(Attendance): void  $work
     */
    private function mutate(Attendance $attendance, Closure $work): Attendance
    {
        return DB::transaction(function () use ($attendance, $work): Attendance {
            $at = $this->lock($attendance->id);
            $work($at);

            return $at->refresh();
        });
    }

    private function createServiceItem(Attendance $at, Service $srv, User $actor): void
    {
        $at->items()->create([
            'item_type' => ItemType::Service,
            'service_id' => $srv->id,
            'name' => $srv->name,
            'quantity' => 1,
            'unit_price_cents' => $srv->price_cents,
            'duration_minutes' => $srv->duration_minutes,
            'price_source' => PriceSource::CatalogAtAttendance,
            'added_by_user_id' => $actor->id,
        ]);
    }

    private function assertCanPerform(Professional $pro, Service $srv): void
    {
        if (! $pro->is_active) {
            throw new CheckoutRuleViolation('professional_inactive');
        }
        if (! $srv->isBookable() || ! $pro->services()->whereKey($srv->id)->exists()) {
            throw new CheckoutRuleViolation('service_unavailable');
        }
    }

    /** A reserva (cupom/pontos) que deu origem ao desconto ja foi liberada? */
    private function promotionReleased(?int $couponRedemptionId, ?int $loyaltyRedemptionId): bool
    {
        return ($couponRedemptionId !== null && CouponRedemption::query()->whereKey($couponRedemptionId)->where('status', RedemptionStatus::Released)->exists())
            || ($loyaltyRedemptionId !== null && LoyaltyRedemption::query()->whereKey($loyaltyRedemptionId)->where('status', RedemptionStatus::Released)->exists());
    }

    private function assertEditable(Attendance $at): void
    {
        if (! $at->status->isEditable()) {
            throw new CheckoutRuleViolation('invalid_status');
        }
    }

    private function assertQuantity(int $quantity): void
    {
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new CheckoutRuleViolation('invalid_quantity');
        }
    }
}
