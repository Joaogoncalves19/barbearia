<?php

namespace App\Modules\Loyalty\Pricing;

use App\Modules\Customers\Models\Customer;
use App\Modules\Loyalty\Enums\DiscountType;
use App\Modules\Loyalty\Enums\RedemptionStatus;
use App\Modules\Loyalty\Models\Coupon;
use App\Modules\Loyalty\Models\CouponRedemption;
use App\Modules\Loyalty\Services\LoyaltyLedger;
use App\Modules\Loyalty\Support\PromotionPolicy;
use App\Modules\Scheduling\Enums\AdjustmentKind;
use App\Modules\Scheduling\Enums\ItemType;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Shared\Pricing\Discount;
use App\Modules\Shared\Pricing\PriceBreakdown;
use App\Modules\Shared\Support\Money;
use App\Modules\Subscriptions\Services\SubscriptionBenefits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * O UNICO motor de promocoes (promocoes.md §2). Responde "qual desconto vale
 * aqui e quanto", sem gravar nada; quem grava e o PromotionService, que
 * chama este mesmo metodo dentro da transacao. A previa (tela de
 * confirmacao, balcao) e a gravacao usam a MESMA funcao: orcamento exibido =
 * valor gravado = valor cobrado (criterio de aceite da Fase 8).
 *
 * Regras:
 * - R-10 / decisao do dono: UM desconto por agendamento/atendimento; entre
 *   os possiveis (cupom, pontos, aniversario, indicacao, manual e o ja
 *   aplicado) vale o MAIOR. Empate: o ja aplicado, depois o que nao gasta
 *   nada (aniversario, indicacao), cupom, pontos, manual.
 * - O valor de cada um e calculado pelo PriceBreakdown (o mesmo dos totais):
 *   so sobre servicos e combos, nunca sobre produtos; nunca passa da base.
 * - Cupom (R-12): ativo, dentro da validade, limite de usos, cliente
 *   cadastrado, 1 uso por cliente.
 * - Pontos (R-18): saldo disponivel (saldo - pontos reservados em outros
 *   agendamentos) >= pontos do resgate.
 * - Aniversario (R-14): data do atendimento no mes do aniversario; uma vez
 *   no mes (o primeiro agendamento/atendimento que usar).
 * - Indicacao (R-15): cliente indicado, ainda sem atendimento concluido e
 *   sem outro agendamento em aberto com o desconto de indicacao.
 * - Assinatura (Fase 9, D-44/D-45): com direito na data (SubscriptionBenefits),
 *   os servicos incluidos na versao contratada saem de graca; e mais um
 *   candidato (vale o maior) e nao gasta nada.
 */
final class PromotionEngine
{
    public function __construct(
        private readonly LoyaltyLedger $ledger,
        private readonly SubscriptionBenefits $benefits,
    ) {}

    /**
     * @param  iterable<array{total: ?int, discountable: bool, unit: ?int}>  $lines
     * @param  string  $localDate  data (AAAA-MM-DD, fuso da barbearia) do atendimento
     * @param  int|null  $appointmentId  o proprio agendamento (suas reservas nao contam contra ele)
     * @param  PromotionCandidate|null  $current  desconto ja aplicado (balcao)
     * @param  PromotionCandidate|null  $manual  desconto manual pedido (balcao)
     */
    public function quote(?Customer $customer, iterable $lines, string $localDate, PromotionRequest $request, ?int $appointmentId = null, ?PromotionCandidate $current = null, ?PromotionCandidate $manual = null): PromotionQuote
    {
        $linhas = is_array($lines) ? array_values($lines) : iterator_to_array($lines, false);
        $politica = PromotionPolicy::current();
        $candidatos = [];
        $problemas = [];
        $notas = [];

        if ($current !== null) {
            $candidatos[] = $current;
        }
        if ($manual !== null) {
            $candidatos[] = $manual;
        }

        if ($request->couponCode !== null) {
            $r = $this->couponCandidate($request->couponCode, $customer, $linhas, $appointmentId);
            is_string($r) ? $problemas['coupon'] = $r : $candidatos[] = $r;
        }
        if ($request->useLoyalty) {
            $r = $this->loyaltyCandidate($customer, $linhas, $appointmentId, $politica);
            is_string($r) ? $problemas['loyalty'] = $r : $candidatos[] = $r;
        }
        if ($customer !== null) {
            if (($b = $this->birthdayCandidate($customer, $linhas, $localDate, $appointmentId, $politica)) !== null) {
                $candidatos[] = $b;
            }
            if (($i = $this->referralCandidate($customer, $linhas, $appointmentId, $politica)) !== null) {
                $candidatos[] = $i;
            }
            if (($s = $this->subscriptionCandidate($customer, $linhas, $localDate)) !== null) {
                $candidatos[] = $s;
            }
        }

        $validos = array_values(array_filter($candidatos, fn (PromotionCandidate $c) => $c->amountCents > 0));
        usort($validos, fn (PromotionCandidate $a, PromotionCandidate $b) => [$b->amountCents, $a->priority()] <=> [$a->amountCents, $b->priority()]);
        $escolhido = $validos[0] ?? null;

        foreach ($validos as $c) {
            if ($escolhido !== null && $c !== $escolhido && ! $c->isCurrent && in_array($c->kind, [AdjustmentKind::Coupon, AdjustmentKind::Loyalty, AdjustmentKind::Manual], true)) {
                $notas[] = $c->label.' vale '.Money::fromCents($c->amountCents)->format().'; '.$escolhido->label
                    .' ('.Money::fromCents($escolhido->amountCents)->format().') é maior ou igual e é o que vale: só um desconto por atendimento.';
            }
        }
        foreach ($candidatos as $c) {
            if ($c->amountCents <= 0 && in_array($c->kind, [AdjustmentKind::Coupon, AdjustmentKind::Loyalty], true)) {
                $problemas[$c->kind === AdjustmentKind::Coupon ? 'coupon' : 'loyalty'] = $c->label.' não dá desconto nestes itens (desconto vale só para serviços).';
            }
        }

        $breakdown = PriceBreakdown::calculate($linhas, $escolhido !== null ? [$escolhido->rule] : []);

        return new PromotionQuote($escolhido, $candidatos, $problemas, $notas, $breakdown);
    }

    /**
     * Fase 12: o que ESTE cliente tem direito de usar num atendimento na data
     * (tela "Benefícios" da conta). As mesmas regras do orcamento, sem itens:
     * so diz se o beneficio vale, nao quanto (o valor depende dos servicos e
     * e calculado na confirmacao, que continua escolhendo UM desconto, o maior).
     * Cupom nao entra: e um codigo divulgado, conferido quando informado.
     *
     * @return list<array{kind: AdjustmentKind, label: string}>
     */
    public function entitlements(Customer $customer, string $localDate): array
    {
        $politica = PromotionPolicy::current();
        $direitos = [];

        if (($s = $this->benefits->rightOn($customer->id, $localDate)) !== null) {
            $direitos[] = ['kind' => AdjustmentKind::Subscription, 'label' => 'Assinatura '.$s->planName().': os serviços incluídos no plano saem de graça.'];
        }
        if ($this->birthdayCandidate($customer, [], $localDate, null, $politica) !== null) {
            $direitos[] = ['kind' => AdjustmentKind::Birthday, 'label' => 'Mês do seu aniversário: '.Discount::percent($politica->int('birthday_percent_bp'))->label().' de desconto em um atendimento este mês.'];
        }
        if ($this->referralCandidate($customer, [], null, $politica) !== null) {
            $direitos[] = ['kind' => AdjustmentKind::Referral, 'label' => 'Você veio por indicação: '.Discount::percent($politica->int('referral_percent_bp'))->label().' de desconto no primeiro atendimento.'];
        }
        if ($politica->bool('loyalty_enabled') && $this->ledger->available($customer) >= $politica->int('loyalty_points_required')) {
            $direitos[] = ['kind' => AdjustmentKind::Loyalty, 'label' => 'Você já tem '.$politica->int('loyalty_points_required').' pontos para trocar por um desconto.'];
        }

        return $direitos;
    }

    /**
     * Linhas para o motor a partir dos itens (agendamento ou atendimento).
     *
     * @param  iterable<object>  $items  com item_type, total_cents, unit_price_cents, service_id
     * @return list<array{total: ?int, discountable: bool, unit: ?int, service: ?int}>
     */
    public static function linesFrom(iterable $items): array
    {
        $linhas = [];
        foreach ($items as $i) {
            $linhas[] = [
                'total' => $i->total_cents !== null ? (int) $i->total_cents : null,
                'discountable' => $i->item_type !== ItemType::Product,
                'unit' => $i->unit_price_cents !== null ? (int) $i->unit_price_cents : null,
                'service' => isset($i->service_id) ? (int) $i->service_id : null,
            ];
        }

        return $linhas;
    }

    /**
     * Beneficio da assinatura: soma dos servicos incluidos (ou nulo).
     *
     * @param  list<array{total: ?int, discountable: bool, unit: ?int, service?: ?int}>  $lines
     */
    private function subscriptionCandidate(Customer $customer, array $lines, string $localDate): ?PromotionCandidate
    {
        $assinatura = $this->benefits->rightOn($customer->id, $localDate);
        if ($assinatura === null) {
            return null;
        }
        $valor = $this->benefits->coveredAmount($assinatura, $lines);
        if ($valor <= 0) {
            return null;
        }

        return new PromotionCandidate(AdjustmentKind::Subscription, Discount::fixed($valor), $valor, 'Assinatura ('.$assinatura->planName().')', subscriptionId: $assinatura->id);
    }

    /**
     * Quanto a regra vale sobre os itens (o mesmo calculo dos totais).
     *
     * @param  list<array{total: ?int, discountable: bool, unit: ?int}>  $lines
     */
    public static function amountFor(Discount $rule, array $lines): int
    {
        return (int) PriceBreakdown::calculate($lines, [$rule])->discount?->cents;
    }

    /**
     * Motivo pelo qual o cupom nao pode ser usado, ou nulo.
     */
    public function couponProblem(?Coupon $coupon, ?Customer $customer, ?int $appointmentId): ?string
    {
        if ($coupon === null || $coupon->trashed()) {
            return 'Cupom não encontrado.';
        }
        if (! $coupon->is_active) {
            return 'Este cupom não está ativo.';
        }
        if ($coupon->expires_on !== null && $coupon->expires_on->toDateString() < BusinessTime::today()) {
            return 'Este cupom venceu em '.$coupon->expires_on->format('d/m/Y').'.';
        }
        if ($customer === null) {
            return 'O cupom exige cliente cadastrado (é um uso por cliente).';
        }
        $proprio = $appointmentId !== null && CouponRedemption::query()->where('coupon_id', $coupon->id)->where('appointment_id', $appointmentId)
            ->where('status', RedemptionStatus::Reserved)->exists();
        if ($proprio) {
            return null; // ja reservado para este mesmo agendamento
        }
        if (CouponRedemption::query()->where('active_key', CouponRedemption::activeKey($coupon->id, $customer->id))->exists()) {
            return 'Este cliente já usou este cupom (vale um uso por cliente).';
        }
        if ($coupon->max_uses !== null && $coupon->uses_count >= $coupon->max_uses) {
            return 'Este cupom atingiu o limite de usos.';
        }

        return null;
    }

    /**
     * @param  list<array{total: ?int, discountable: bool, unit: ?int}>  $lines
     */
    private function couponCandidate(string $code, ?Customer $customer, array $lines, ?int $appointmentId): PromotionCandidate|string
    {
        $cupom = Coupon::query()->where('code', $code)->first();
        $problema = $this->couponProblem($cupom, $customer, $appointmentId);
        if ($problema !== null || $cupom === null) {
            return $problema ?? 'Cupom não encontrado.';
        }

        return new PromotionCandidate(AdjustmentKind::Coupon, $cupom->rule(), self::amountFor($cupom->rule(), $lines), 'Cupom '.$cupom->code.' ('.$cupom->describe().')', coupon: $cupom);
    }

    /**
     * Recompensa do resgate de pontos (R-18) sobre os itens, ou nulo se nao
     * houver servico para descontar.
     *
     * @param  list<array{total: ?int, discountable: bool, unit: ?int}>  $lines
     * @return array{rule: Discount, reward: array<string, int|string|null>}|null
     */
    public static function loyaltyReward(array $lines, PromotionPolicy $policy): ?array
    {
        $unitarios = array_values(array_filter(array_map(fn ($l) => $l['discountable'] ? $l['unit'] : null, $lines), fn ($v) => $v !== null && $v > 0));
        $tipo = $policy->string('loyalty_reward_type');
        $base = $policy->string('loyalty_reward_base');
        $pct = $policy->int('loyalty_reward_percent_bp');

        $regra = null;
        $descricao = '';
        if ($tipo === 'fixed') {
            $regra = Discount::fixed($policy->int('loyalty_reward_fixed_cents'));
            $descricao = Money::fromCents($policy->int('loyalty_reward_fixed_cents'))->format().' de desconto';
        } elseif ($tipo === 'free_service') {
            $valor = $unitarios !== [] ? max($unitarios) : 0;
            $regra = $valor > 0 ? Discount::fixed($valor) : null;
            $descricao = 'serviço mais caro grátis';
        } elseif ($base === 'total') {
            $regra = Discount::percent($pct);
            $descricao = Discount::percent($pct)->label().' no total dos serviços';
        } else {
            $alvo = $unitarios === [] ? 0 : ($base === 'most_expensive' ? max($unitarios) : min($unitarios));
            $valor = Money::fromCents($alvo)->percentOf($pct)->cents;
            $regra = $valor > 0 ? Discount::fixed($valor) : null;
            $descricao = Discount::percent($pct)->label().' no serviço '.($base === 'most_expensive' ? 'mais caro' : 'mais barato');
        }
        if ($regra === null) {
            return null;
        }

        return ['rule' => $regra, 'reward' => [
            'pontos' => $policy->int('loyalty_points_required'),
            'tipo' => $tipo,
            'base' => $base,
            'percentual_bp' => $tipo === 'percent' ? $pct : null,
            'valor_cents' => $regra->type === DiscountType::Fixed ? $regra->value : null,
            'descricao' => $descricao,
        ]];
    }

    /**
     * @param  list<array{total: ?int, discountable: bool, unit: ?int}>  $lines
     */
    private function loyaltyCandidate(?Customer $customer, array $lines, ?int $appointmentId, PromotionPolicy $policy): PromotionCandidate|string
    {
        if (! $policy->bool('loyalty_enabled')) {
            return 'O programa de fidelidade não está ativo.';
        }
        if ($customer === null) {
            return 'Pontos de fidelidade exigem cliente cadastrado.';
        }
        $precisa = $policy->int('loyalty_points_required');
        $disponivel = $this->ledger->available($customer, $appointmentId);
        if ($disponivel < $precisa) {
            return "Pontos insuficientes: o resgate pede {$precisa} e há {$disponivel} disponíveis.";
        }
        $r = self::loyaltyReward($lines, $policy);
        if ($r === null) {
            return 'Não há serviço para aplicar o resgate de pontos.';
        }

        return new PromotionCandidate(AdjustmentKind::Loyalty, $r['rule'], self::amountFor($r['rule'], $lines),
            "Resgate de {$precisa} pontos (".$r['reward']['descricao'].')', loyaltyReward: $r['reward']);
    }

    /**
     * @param  list<array{total: ?int, discountable: bool, unit: ?int}>  $lines
     */
    private function birthdayCandidate(Customer $customer, array $lines, string $localDate, ?int $appointmentId, PromotionPolicy $policy): ?PromotionCandidate
    {
        if (! $policy->bool('birthday_enabled') || $customer->birth_date === null
            || $customer->birth_date->format('m') !== substr($localDate, 5, 2)) {
            return null;
        }
        $inicio = BusinessTime::at(substr($localDate, 0, 7).'-01', '00:00');
        $fim = BusinessTime::at(CarbonImmutable::createFromFormat('Y-m-d', substr($localDate, 0, 7).'-01')->addMonth()->toDateString(), '00:00');

        $noAgendamento = DB::table('appointment_adjustments')->join('appointments', 'appointments.id', '=', 'appointment_adjustments.appointment_id')
            ->where('appointment_adjustments.kind', AdjustmentKind::Birthday->value)->where('appointments.customer_id', $customer->id)
            ->whereNotIn('appointments.status', ['cancelled', 'no_show'])
            ->where('appointments.starts_at', '>=', $inicio)->where('appointments.starts_at', '<', $fim)
            ->when($appointmentId, fn ($q) => $q->where('appointments.id', '<>', $appointmentId))->exists();
        $noAtendimento = DB::table('attendance_discounts')->join('attendances', 'attendances.id', '=', 'attendance_discounts.attendance_id')
            ->where('attendance_discounts.kind', AdjustmentKind::Birthday->value)->where('attendances.customer_id', $customer->id)
            ->where('attendances.status', '<>', 'cancelled')
            ->where('attendances.opened_at', '>=', $inicio)->where('attendances.opened_at', '<', $fim)
            ->when($appointmentId, fn ($q) => $q->where(fn ($x) => $x->whereNull('attendances.appointment_id')->orWhere('attendances.appointment_id', '<>', $appointmentId)))->exists();
        if ($noAgendamento || $noAtendimento) {
            return null;
        }

        $regra = Discount::percent($policy->int('birthday_percent_bp'));

        return new PromotionCandidate(AdjustmentKind::Birthday, $regra, self::amountFor($regra, $lines), 'Aniversário ('.$regra->label().')');
    }

    /**
     * @param  list<array{total: ?int, discountable: bool, unit: ?int}>  $lines
     */
    private function referralCandidate(Customer $customer, array $lines, ?int $appointmentId, PromotionPolicy $policy): ?PromotionCandidate
    {
        if (! $policy->bool('referral_enabled') || $customer->referred_by_customer_id === null) {
            return null;
        }
        $jaAtendido = DB::table('attendances')->where('customer_id', $customer->id)->where('status', 'completed')->exists()
            || DB::table('appointments')->where('customer_id', $customer->id)->where('status', 'completed')->exists();
        $outroComIndicacao = DB::table('appointment_adjustments')->join('appointments', 'appointments.id', '=', 'appointment_adjustments.appointment_id')
            ->where('appointment_adjustments.kind', AdjustmentKind::Referral->value)->where('appointments.customer_id', $customer->id)
            ->whereIn('appointments.status', ['pending', 'confirmed'])
            ->when($appointmentId, fn ($q) => $q->where('appointments.id', '<>', $appointmentId))->exists();
        if ($jaAtendido || $outroComIndicacao) {
            return null;
        }

        $regra = Discount::percent($policy->int('referral_percent_bp'));

        return new PromotionCandidate(AdjustmentKind::Referral, $regra, self::amountFor($regra, $lines), 'Indicação ('.$regra->label().' no primeiro atendimento)');
    }
}
