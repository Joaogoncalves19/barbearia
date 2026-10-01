<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Enums\CashMovementType;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Exceptions\CashRuleViolation;
use App\Modules\Finance\Exceptions\CommissionRuleViolation;
use App\Modules\Finance\Models\Advance;
use App\Modules\Finance\Models\CommissionEntry;
use App\Modules\Finance\Models\CommissionPayout;
use App\Modules\Finance\Models\TipEntry;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Shared\Support\Money;
use App\Modules\System\Services\AuditTrail;
use App\Modules\Team\Models\Professional;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Repasse ao profissional (repasses.md §5): o UNICO lugar que fecha e paga os
 * valores devidos, e que estorna um repasse.
 *
 * Fechar = numa transacao que PRIMEIRO trava o saldo do profissional
 * (ledger_version), juntar TODOS os lancamentos em aberto ate o instante de
 * corte (comissao, gorjeta, vales), gravar o repasse com a fotografia do que
 * entrou e marcar esses lancamentos (so os que ainda estavam em aberto: um
 * lancamento nunca entra em dois repasses). Em dinheiro, a saida vai para o
 * caixa aberto (decisao do dono, D-37); Pix/outro so ficam registrados.
 * Trava: profissional e depois caixa (mesma ordem no vale).
 *
 * Estornar = devolver os lancamentos ao saldo em aberto (o repasse fica, com
 * o motivo e a fotografia) e, se foi em dinheiro, o dinheiro volta ao caixa.
 */
final class Payouts
{
    /** Gancho SO para o teste de concorrencia (depois de somar, antes de gravar). */
    public static ?Closure $afterSum = null;

    public const METHODS = [PaymentMethod::Cash, PaymentMethod::Pix, PaymentMethod::Other];

    public function __construct(
        private readonly ProfessionalLedger $ledger,
        private readonly CashRegister $cash,
    ) {}

    /**
     * @throws CommissionRuleViolation|CashRuleViolation
     */
    public function pay(Professional $professional, PaymentMethod $method, ?string $notes, User $actor, string $key, ?CarbonInterface $cutoff = null): CommissionPayout
    {
        if (! in_array($method, self::METHODS, true)) {
            throw new CommissionRuleViolation('invalid_method');
        }

        return DB::transaction(function () use ($professional, $method, $notes, $actor, $key, $cutoff): CommissionPayout {
            $this->ledger->lock($professional->id);

            $existente = CommissionPayout::query()->where('request_key', $key)->first();
            if ($existente !== null) {
                return $existente; // repeticao da mesma requisicao
            }

            $corte = $cutoff ?? BusinessTime::now();
            $comissoes = $this->ledger->openCommission($professional->id, $corte)->orderBy('id')->get(['id', 'amount_cents', 'occurred_at']);
            $gorjetas = $this->ledger->openTips($professional->id, $corte)->orderBy('id')->get(['id', 'amount_cents', 'occurred_at']);
            $vales = $this->ledger->openAdvances($professional->id, $corte)->orderBy('id')->get(['id', 'amount_cents', 'occurred_at']);
            if ($comissoes->isEmpty() && $gorjetas->isEmpty() && $vales->isEmpty()) {
                throw new CommissionRuleViolation('nothing_to_pay');
            }

            $c = (int) $comissoes->sum('amount_cents');
            $t = (int) $gorjetas->sum('amount_cents');
            $a = (int) $vales->sum('amount_cents');
            $liquido = $c + $t - $a;
            if ($liquido < 0) {
                throw new CommissionRuleViolation('negative_balance', 'Comissão: '.Money::fromCents($c)->format().'; gorjeta: '.Money::fromCents($t)->format().'; vales: '.Money::fromCents($a)->format().'.');
            }

            if (self::$afterSum !== null) {
                (self::$afterSum)();
            }

            $caixa = $method === PaymentMethod::Cash && $liquido > 0 ? $this->cash->lockOpen() : null;
            $datas = $comissoes->pluck('occurred_at')->merge($gorjetas->pluck('occurred_at'))->merge($vales->pluck('occurred_at'))->filter()->sort()->values();

            $repasse = CommissionPayout::query()->create([
                'professional_id' => $professional->id,
                'amount_cents' => $liquido,
                'commission_cents' => $c,
                'tip_cents' => $t,
                'advances_cents' => $a,
                'cutoff_at' => $corte,
                'period_start' => $datas->first() !== null ? BusinessTime::dateOf($datas->first()) : null,
                'period_end' => BusinessTime::dateOf($corte),
                'paid_on' => BusinessTime::today(),
                'method' => $method,
                'cash_session_id' => $caixa?->id,
                'snapshot' => [
                    'comissoes' => $comissoes->map(fn ($e) => [$e->id, (int) $e->amount_cents])->all(),
                    'gorjetas' => $gorjetas->map(fn ($e) => [$e->id, (int) $e->amount_cents])->all(),
                    'vales' => $vales->map(fn ($e) => [$e->id, (int) $e->amount_cents])->all(),
                ],
                'notes' => $notes !== null && trim($notes) !== '' ? mb_substr(trim($notes), 0, 1000) : null,
                'created_by_user_id' => $actor->id,
                'request_key' => $key,
            ]);

            // So os que ainda estavam em aberto; a contagem confere.
            foreach ([[CommissionEntry::class, $comissoes], [TipEntry::class, $gorjetas], [Advance::class, $vales]] as [$classe, $lista]) {
                $n = $classe::query()->whereIn('id', $lista->pluck('id'))->whereNull('commission_payout_id')->toBase()->update(['commission_payout_id' => $repasse->id]);
                if ($n !== $lista->count()) {
                    throw new CommissionRuleViolation('nothing_to_pay', 'Os valores mudaram durante o fechamento; tente de novo.');
                }
            }

            if ($caixa !== null) {
                $this->cash->recordProfessionalMovement($caixa, CashMovementType::Payout, $liquido, 'Repasse a '.$professional->display_name, $actor, ['commission_payout_id' => $repasse->id]);
            }

            AuditTrail::record('payout.created', $repasse, $actor, 'Repasse de '.Money::fromCents($liquido)->format().' a '.$professional->display_name.'.', [
                'comissao_cents' => $c, 'gorjeta_cents' => $t, 'vales_cents' => $a, 'liquido_cents' => $liquido, 'forma' => $method->value,
            ]);

            return $repasse;
        });
    }

    /**
     * @throws CommissionRuleViolation|CashRuleViolation
     */
    public function reverse(CommissionPayout $payout, string $reason, User $actor, string $key): CommissionPayout
    {
        $motivo = trim($reason);
        if (mb_strlen($motivo) < 3) {
            throw new CommissionRuleViolation('reason_required');
        }

        return DB::transaction(function () use ($payout, $motivo, $actor, $key): CommissionPayout {
            $this->ledger->lock($payout->professional_id);
            $p = CommissionPayout::query()->findOrFail($payout->id);

            if ($p->reversal_request_key === $key) {
                return $p; // repeticao da mesma requisicao
            }
            if ($p->isLegacy()) {
                throw new CommissionRuleViolation('legacy_payout');
            }
            if ($p->isReversed()) {
                throw new CommissionRuleViolation('already_reversed');
            }

            foreach ([CommissionEntry::class, TipEntry::class, Advance::class] as $classe) {
                $classe::query()->where('commission_payout_id', $p->id)->toBase()->update(['commission_payout_id' => null]);
            }

            $caixa = $p->cash_session_id !== null ? $this->cash->lockOpen() : null;
            $p->forceFill(['reversed_at' => BusinessTime::now()]);
            $p->reversed_by_user_id = $actor->id;
            $p->reversal_reason = mb_substr($motivo, 0, 255);
            $p->reversal_cash_session_id = $caixa?->id;
            $p->reversal_request_key = $key;
            $p->save();

            if ($caixa !== null) {
                $this->cash->recordProfessionalMovement($caixa, CashMovementType::PayoutReversal, $p->amount_cents, 'Estorno do repasse #'.$p->id.' · '.$motivo, $actor, ['commission_payout_id' => $p->id]);
            }

            AuditTrail::record('payout.reversed', $p, $actor, 'Repasse #'.$p->id.' estornado.', [
                'liquido_cents' => $p->amount_cents, 'motivo' => $motivo,
            ]);

            return $p;
        });
    }
}
