<?php

namespace App\Modules\Finance\Services;

use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Finance\Enums\LedgerEntryKind;
use App\Modules\Finance\Enums\PaymentKind;
use App\Modules\Finance\Exceptions\CommissionRuleViolation;
use App\Modules\Finance\Models\Advance;
use App\Modules\Finance\Models\CommissionEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\TipEntry;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Shared\Support\Money;
use App\Modules\System\Services\AuditTrail;
use App\Modules\Team\Models\Professional;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * O que o profissional tem a receber (repasses.md): os razoes de COMISSAO e
 * de GORJETA, separados, e os vales. O UNICO lugar que lanca nesses razoes.
 *
 * - Conclusao do atendimento (chamado DENTRO da transacao da conclusao,
 *   AttendanceService::complete): uma comissao por item (CommissionCalculator)
 *   e uma gorjeta por pagamento com gorjeta. Falhou aqui, a conclusao inteira
 *   e desfeita: nunca fica pagamento sem comissao, nem o contrario.
 * - Estorno de pagamento (dentro da transacao do estorno): a parte da
 *   gorjeta vira gorjeta negativa; o resto reduz a comissao na mesma
 *   proporcao do valor estornado (decisao do dono, D-35).
 * - Ajuste manual: com motivo, autor e chave (idempotente).
 *
 * Nada e recalculado nem editado: mudar preco, regra, profissional ou
 * configuracao depois nao muda nenhum valor ja lancado.
 */
final class ProfessionalLedger
{
    public function __construct(private readonly CommissionCalculator $calculator) {}

    /**
     * Comissao e gorjeta do atendimento que ACABOU de ser concluido.
     *
     * @param  iterable<Payment>  $payments
     */
    public function recordCompletion(Attendance $attendance, iterable $payments): void
    {
        if ($attendance->status !== AttendanceStatus::Completed || $attendance->professional_id === null) {
            return;
        }
        $quando = $attendance->completed_at ?? BusinessTime::now();

        foreach ($this->calculator->forAttendance($attendance, $quando) as $linha) {
            $regra = $linha['rule'];
            CommissionEntry::query()->create([
                'professional_id' => $attendance->professional_id,
                'kind' => LedgerEntryKind::Earned,
                'attendance_id' => $attendance->id,
                'attendance_item_id' => $linha['item']->id,
                'commission_rule_id' => $regra?->id,
                'item_name' => $linha['item']->name,
                'quantity' => $linha['item']->quantity,
                'base_cents' => $linha['base'],
                'rate_bp' => $regra?->rate_bp,
                'amount_cents' => $linha['amount'],
                'rule' => [...($regra !== null ? $regra->snapshot() : ['tipo' => 'none', 'descricao' => 'Sem regra de comissão para este item']), ...($linha['note'] !== null ? ['observacao' => $linha['note']] : [])],
                'occurred_at' => $quando,
            ]);
        }

        foreach ($payments as $p) {
            if ((int) $p->tip_cents > 0) {
                TipEntry::query()->create([
                    'professional_id' => $attendance->professional_id,
                    'attendance_id' => $attendance->id,
                    'payment_id' => $p->id,
                    'kind' => LedgerEntryKind::Earned,
                    'amount_cents' => (int) $p->tip_cents,
                    'occurred_at' => $quando,
                ]);
            }
        }
    }

    /**
     * Efeito do estorno $refund (ja gravado) na comissao e na gorjeta.
     */
    public function recordRefund(Attendance $attendance, Payment $refund, User $actor): void
    {
        if ($attendance->professional_id === null || $refund->kind !== PaymentKind::Refund) {
            return;
        }
        $quando = BusinessTime::now();

        if ((int) $refund->tip_cents > 0) {
            TipEntry::query()->create([
                'professional_id' => $attendance->professional_id,
                'attendance_id' => $attendance->id,
                'payment_id' => $refund->id,
                'kind' => LedgerEntryKind::Refund,
                'amount_cents' => -(int) $refund->tip_cents,
                'reason' => $refund->reason,
                'created_by_user_id' => $actor->id,
                'occurred_at' => $quando,
            ]);
        }

        $total = (int) $attendance->total_cents;
        if ($refund->amount_cents <= 0 || $total <= 0) {
            return;
        }

        // Proporcao ACUMULADA (todos os estornos do atendimento): varios
        // estornos parciais nunca somam centavos a mais nem a menos que um
        // estorno unico do mesmo valor; estorno total zera a comissao.
        $calculada = (int) CommissionEntry::query()->where('attendance_id', $attendance->id)->where('kind', LedgerEntryKind::Earned)->sum('amount_cents');
        $estornadoPago = (int) Payment::query()->where('attendance_id', $attendance->id)->where('kind', PaymentKind::Refund)->sum('amount_cents');
        $jaRevertido = -(int) CommissionEntry::query()->where('attendance_id', $attendance->id)->where('kind', LedgerEntryKind::Refund)->sum('amount_cents');

        $proporcao = min($estornadoPago, $total);
        $alvo = intdiv(2 * $calculada * $proporcao + $total, 2 * $total); // meio centavo para cima
        $delta = $alvo - $jaRevertido;
        if ($delta <= 0) {
            return;
        }

        CommissionEntry::query()->create([
            'professional_id' => $attendance->professional_id,
            'kind' => LedgerEntryKind::Refund,
            'attendance_id' => $attendance->id,
            'payment_id' => $refund->id,
            'base_cents' => (int) $refund->amount_cents,
            'amount_cents' => -$delta,
            'rule' => [
                'tipo' => 'estorno_proporcional',
                'descricao' => 'Estorno de '.Money::fromCents((int) $refund->amount_cents)->format().' sobre '.Money::fromCents($total)->format()
                    .' cobrados: comissão reduzida na mesma proporção.',
                'comissao_calculada_cents' => $calculada,
                'estornado_acumulado_cents' => $estornadoPago,
            ],
            'reason' => $refund->reason,
            'created_by_user_id' => $actor->id,
            'occurred_at' => $quando,
        ]);
    }

    /**
     * Correcao manual de comissao ou de gorjeta (positiva ou negativa).
     *
     * @param  'commission'|'tip'  $ledger
     *
     * @throws CommissionRuleViolation
     */
    public function adjust(Professional $professional, string $ledger, int $amountCents, string $reason, ?Attendance $attendance, User $actor, string $key): CommissionEntry|TipEntry
    {
        $motivo = trim($reason);
        if ($amountCents === 0 || abs($amountCents) > 100_000_00) {
            throw new CommissionRuleViolation('invalid_amount');
        }
        if (mb_strlen($motivo) < 3) {
            throw new CommissionRuleViolation('reason_required');
        }
        if ($attendance !== null && ($attendance->status !== AttendanceStatus::Completed)) {
            throw new CommissionRuleViolation('unknown_attendance');
        }
        if ($attendance !== null && $attendance->professional_id !== $professional->id) {
            throw new CommissionRuleViolation('professional_mismatch');
        }

        return DB::transaction(function () use ($professional, $ledger, $amountCents, $motivo, $attendance, $actor, $key): CommissionEntry|TipEntry {
            $this->lock($professional->id);
            $existente = CommissionEntry::query()->where('request_key', $key)->first() ?? TipEntry::query()->where('request_key', $key)->first();
            if ($existente !== null) {
                return $existente; // repeticao da mesma requisicao
            }

            $dados = [
                'professional_id' => $professional->id,
                'kind' => LedgerEntryKind::Adjustment,
                'attendance_id' => $attendance?->id,
                'amount_cents' => $amountCents,
                'reason' => mb_substr($motivo, 0, 255),
                'created_by_user_id' => $actor->id,
                'request_key' => $key,
                'occurred_at' => BusinessTime::now(),
            ];
            $lancamento = $ledger === 'tip'
                ? TipEntry::query()->create($dados)
                : CommissionEntry::query()->create([...$dados, 'base_cents' => 0, 'rule' => ['tipo' => 'ajuste', 'descricao' => 'Ajuste manual']]);

            AuditTrail::record($ledger === 'tip' ? 'tip.adjusted' : 'commission.adjusted', $professional, $actor,
                'Ajuste de '.($ledger === 'tip' ? 'gorjeta' : 'comissão').' de '.Money::fromCents($amountCents)->format().'.', [
                    'valor_cents' => $amountCents, 'motivo' => $motivo, 'atendimento' => $attendance?->code, 'lancamento' => $lancamento->id,
                ]);

            return $lancamento;
        });
    }

    /**
     * Saldo EM ABERTO (ainda nao repassado) ate o instante: comissao,
     * gorjeta, vales e o liquido (comissao + gorjeta - vales).
     *
     * @return array{commission: int, tips: int, advances: int, net: int}
     */
    public function open(Professional|int $professional, ?CarbonInterface $until = null): array
    {
        $id = $professional instanceof Professional ? $professional->id : $professional;
        $ate = $until ?? BusinessTime::now();

        $comissao = (int) $this->openCommission($id, $ate)->sum('amount_cents');
        $gorjeta = (int) $this->openTips($id, $ate)->sum('amount_cents');
        $vales = (int) $this->openAdvances($id, $ate)->sum('amount_cents');

        return ['commission' => $comissao, 'tips' => $gorjeta, 'advances' => $vales, 'net' => $comissao + $gorjeta - $vales];
    }

    /**
     * Extrato de um mes (AAAA-MM) do profissional: os lancamentos como foram
     * gravados, sem recalcular nada. Mesma leitura no extrato do painel e em
     * "Ganhos" da area do profissional.
     *
     * @return array{commissions: Collection<int, CommissionEntry>, tips: Collection<int, TipEntry>, advances: Collection<int, Advance>}
     */
    public function month(Professional $professional, string $month): array
    {
        $inicio = BusinessTime::at($month.'-01', '00:00');
        $fim = BusinessTime::at(CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->addMonth()->toDateString(), '00:00');
        $doMes = fn ($q) => $q->where('professional_id', $professional->id)->where('occurred_at', '>=', $inicio)->where('occurred_at', '<', $fim);

        return [
            'commissions' => CommissionEntry::query()->tap($doMes)->with(['attendance', 'createdBy'])->orderBy('occurred_at')->orderBy('id')->get(),
            'tips' => TipEntry::query()->tap($doMes)->with(['attendance', 'payment', 'createdBy'])->orderBy('occurred_at')->orderBy('id')->get(),
            'advances' => Advance::query()->where('professional_id', $professional->id)
                ->where(fn ($q) => $q->where(fn ($a) => $a->where('occurred_at', '>=', $inicio)->where('occurred_at', '<', $fim))
                    ->orWhere(fn ($b) => $b->whereNull('occurred_at')->where('reference_month', $month)))
                ->with(['createdBy', 'reversal'])->orderBy('id')->get(),
        ];
    }

    /**
     * Comissao e gorjeta LANCADAS num periodo [inicio, fim), repassadas ou
     * nao (inclui estornos e ajustes, com sinal).
     *
     * @return array{commission: int, tips: int}
     */
    public function earnedBetween(Professional $professional, CarbonInterface $from, CarbonInterface $to): array
    {
        $periodo = fn ($q) => $q->where('professional_id', $professional->id)->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to);

        return [
            'commission' => (int) CommissionEntry::query()->tap($periodo)->sum('amount_cents'),
            'tips' => (int) TipEntry::query()->tap($periodo)->sum('amount_cents'),
        ];
    }

    /**
     * @return Builder<CommissionEntry>
     */
    public function openCommission(int $professionalId, CarbonInterface $until): Builder
    {
        return CommissionEntry::query()->where('professional_id', $professionalId)->whereNull('commission_payout_id')->where('occurred_at', '<=', $until);
    }

    /**
     * @return Builder<TipEntry>
     */
    public function openTips(int $professionalId, CarbonInterface $until): Builder
    {
        return TipEntry::query()->where('professional_id', $professionalId)->whereNull('commission_payout_id')->where('occurred_at', '<=', $until);
    }

    /**
     * @return Builder<Advance>
     */
    public function openAdvances(int $professionalId, CarbonInterface $until): Builder
    {
        return Advance::query()->where('professional_id', $professionalId)->whereNull('commission_payout_id')
            ->where('is_legacy', false)->where('occurred_at', '<=', $until);
    }

    /**
     * Primeira escrita da transacao: trava o saldo do profissional (repasse,
     * vale e ajuste do mesmo profissional ficam em fila).
     */
    public function lock(int $professionalId): void
    {
        DB::table('professionals')->where('id', $professionalId)->increment('ledger_version');
    }
}
