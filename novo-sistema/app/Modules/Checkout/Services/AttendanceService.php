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
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Enums\DiscountType;
use App\Modules\Scheduling\Enums\AdjustmentKind;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Enums\ItemType;
use App\Modules\Scheduling\Enums\PriceSource;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Shared\Pricing\Discount;
use App\Modules\Shared\Support\Money;
use App\Modules\Team\Models\Professional;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * As UNICAS regras do atendimento (atendimento.md): abrir (do agendamento ou
 * encaixe), iniciar, itens, consumo, desconto, profissional, cancelar e
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
 * historico. Qualquer falha desfaz tudo.
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
                $pro = $a->professional_id !== null ? Professional::withTrashed()->find($a->professional_id) : null;
                if ($pro === null) {
                    throw new CheckoutRuleViolation('appointment_without_professional');
                }
                if ($a->status === AppointmentStatus::Pending) {
                    $this->booking->confirm($a, $actor);
                }

                $at = Attendance::query()->create([
                    'source' => AttendanceSource::Appointment,
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

                // O combinado no agendamento vale no atendimento: mesmo item,
                // mesmo preco fotografado, mesmos descontos.
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
                    if ($adj->amount_cents > 0) {
                        $at->discounts()->create([
                            'kind' => $adj->kind,
                            'type' => DiscountType::Fixed,
                            'fixed_cents' => $adj->amount_cents,
                            'base_cents' => 0,
                            'amount_cents' => 0,
                            'reason' => $adj->description ?? 'Desconto do agendamento',
                        ]);
                    }
                }
                $this->pricing->refreshDiscounts($at);

                $this->event($at, 'opened', 'Atendimento aberto a partir do agendamento '.$a->code.'.', $actor);

                return $at;
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

    /** Encaixe: atendimento sem agendamento (cliente cadastrado ou so nome e telefone). */
    public function openWalkIn(Service $service, Professional $professional, ?Customer $customer, ?string $contactName, ?string $contactPhone, User $actor): Attendance
    {
        if ($customer === null && mb_strlen(trim((string) $contactName)) < 2) {
            throw new CheckoutRuleViolation('contact_required');
        }

        return DB::transaction(function () use ($service, $professional, $customer, $contactName, $contactPhone, $actor): Attendance {
            $pro = Professional::query()->findOrFail($professional->id);
            $srv = Service::query()->findOrFail($service->id);
            $this->assertCanPerform($pro, $srv);

            $at = Attendance::query()->create([
                'source' => AttendanceSource::WalkIn,
                'customer_id' => $customer?->id,
                'customer_name' => $customer !== null ? $customer->name : trim((string) $contactName),
                'customer_phone' => $customer !== null ? $customer->phone : ($contactPhone !== null ? mb_substr(trim($contactPhone), 0, 32) : null),
                'professional_id' => $pro->id,
                'professional_name' => $pro->display_name,
                'status' => AttendanceStatus::Open,
                'opened_at' => BusinessTime::now(),
                'opened_by_user_id' => $actor->id,
            ]);
            $this->createServiceItem($at, $srv, $actor);
            $this->event($at, 'opened', 'Atendimento aberto (encaixe, sem agendamento).', $actor, ['servico' => $srv->name]);

            return $at;
        });
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
            $at->discounts()->where('kind', AdjustmentKind::Manual->value)->get()->each->delete();
            $d = $at->discounts()->create([
                'kind' => AdjustmentKind::Manual,
                'type' => $rule->type,
                'percent_bp' => $rule->type === DiscountType::Percent ? $rule->value : null,
                'fixed_cents' => $rule->type === DiscountType::Fixed ? $rule->value : null,
                'base_cents' => 0,
                'amount_cents' => 0,
                'reason' => mb_substr($motivo, 0, 255),
                'applied_by_user_id' => $actor->id,
            ]);
            $this->pricing->refreshDiscounts($at);
            $d->refresh();
            $this->event($at, 'discount_applied', 'Desconto de '.$rule->label().' aplicado.', $actor, [
                'antes_cents' => $d->base_cents, 'desconto_cents' => $d->amount_cents,
                'depois_cents' => $d->base_cents - $d->amount_cents, 'motivo' => $motivo,
            ]);
        });
    }

    public function removeDiscount(Attendance $attendance, AttendanceDiscount $discount, User $actor): Attendance
    {
        return $this->mutate($attendance, function (Attendance $at) use ($discount, $actor): void {
            $this->assertEditable($at);
            $linha = AttendanceDiscount::query()->whereKey($discount->id)->where('attendance_id', $at->id)->first()
                ?? throw new CheckoutRuleViolation('not_in_attendance');
            $linha->delete();
            $this->pricing->refreshDiscounts($at);
            $this->event($at, 'discount_removed', 'Desconto retirado.', $actor, ['desconto_cents' => $linha->amount_cents]);
        });
    }

    /** Quem efetivamente atende (pode nao ser quem estava agendado). */
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
     * do estoque (isso so acontece na conclusao); o agendamento de origem
     * continua como esta (a equipe decide se foi falta ou cancelamento).
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

            // 2) Caixa: todo pagamento entra no caixa aberto.
            $caixa = $payments !== [] ? $this->cash->lockOpen() : null;

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

            // 4) Pagamentos e entradas no caixa.
            foreach ($payments as $linha) {
                $pg = Payment::query()->create([
                    'attendance_id' => $at->id,
                    'customer_id' => $at->customer_id,
                    'cash_session_id' => $caixa?->id,
                    'kind' => PaymentKind::Payment,
                    'method' => $linha->method,
                    'amount_cents' => $linha->amountCents,
                    'tip_cents' => $linha->tipCents,
                    'amount_source' => AmountSource::Recorded,
                    'paid_at' => BusinessTime::now(),
                    'received_by_user_id' => $actor->id,
                    'received_by_label' => $actor->name,
                ]);
                if ($caixa !== null) {
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
