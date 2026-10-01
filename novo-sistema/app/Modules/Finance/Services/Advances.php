<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Enums\AdvanceKind;
use App\Modules\Finance\Enums\CashMovementType;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Exceptions\CashRuleViolation;
use App\Modules\Finance\Exceptions\CommissionRuleViolation;
use App\Modules\Finance\Models\Advance;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Shared\Support\Money;
use App\Modules\System\Services\AuditTrail;
use App\Modules\Team\Models\Professional;
use Illuminate\Support\Facades\DB;

/**
 * Vales (adiantamentos; repasses.md §4, decisao do dono D-34): lancar e
 * estornar. O vale e abatido no proximo repasse. Em dinheiro, sai do caixa
 * aberto (e o estorno devolve). Nada e editado nem apagado.
 */
final class Advances
{
    public function __construct(
        private readonly ProfessionalLedger $ledger,
        private readonly CashRegister $cash,
    ) {}

    /**
     * @throws CommissionRuleViolation|CashRuleViolation
     */
    public function issue(Professional $professional, int $amountCents, PaymentMethod $method, string $description, User $actor, string $key): Advance
    {
        $motivo = trim($description);
        if ($amountCents < 1 || $amountCents > 100_000_00) {
            throw new CommissionRuleViolation('invalid_amount');
        }
        if (mb_strlen($motivo) < 3) {
            throw new CommissionRuleViolation('reason_required');
        }
        if (! in_array($method, Payouts::METHODS, true)) {
            throw new CommissionRuleViolation('invalid_method');
        }

        return DB::transaction(function () use ($professional, $amountCents, $method, $motivo, $actor, $key): Advance {
            $this->ledger->lock($professional->id);
            $existente = Advance::query()->where('request_key', $key)->first();
            if ($existente !== null) {
                return $existente; // repeticao da mesma requisicao
            }

            $caixa = $method === PaymentMethod::Cash ? $this->cash->lockOpen() : null;
            $vale = Advance::query()->create([
                'professional_id' => $professional->id,
                'kind' => AdvanceKind::Advance,
                'amount_cents' => $amountCents,
                'issued_on' => BusinessTime::today(),
                'reference_month' => substr(BusinessTime::today(), 0, 7),
                'description' => mb_substr($motivo, 0, 255),
                'method' => $method,
                'cash_session_id' => $caixa?->id,
                'created_by_user_id' => $actor->id,
                'request_key' => $key,
                'occurred_at' => BusinessTime::now(),
            ]);
            if ($caixa !== null) {
                $this->cash->recordProfessionalMovement($caixa, CashMovementType::Advance, $amountCents, 'Vale a '.$professional->display_name.' · '.$motivo, $actor, ['advance_id' => $vale->id]);
            }

            AuditTrail::record('advance.issued', $vale, $actor, 'Vale de '.Money::fromCents($amountCents)->format().' a '.$professional->display_name.'.', [
                'valor_cents' => $amountCents, 'forma' => $method->value, 'motivo' => $motivo,
            ]);

            return $vale;
        });
    }

    /**
     * @throws CommissionRuleViolation|CashRuleViolation
     */
    public function reverse(Advance $advance, string $reason, User $actor, string $key): Advance
    {
        $motivo = trim($reason);
        if (mb_strlen($motivo) < 3) {
            throw new CommissionRuleViolation('reason_required');
        }

        return DB::transaction(function () use ($advance, $motivo, $actor, $key): Advance {
            $this->ledger->lock($advance->professional_id);
            $existente = Advance::query()->where('request_key', $key)->first();
            if ($existente !== null) {
                return $existente;
            }

            $original = Advance::query()->findOrFail($advance->id);
            if ($original->kind !== AdvanceKind::Advance || $original->is_legacy) {
                throw new CommissionRuleViolation('not_reversible');
            }
            if (Advance::query()->where('reverses_advance_id', $original->id)->exists()) {
                throw new CommissionRuleViolation('already_reversed');
            }

            $caixa = $original->method === PaymentMethod::Cash ? $this->cash->lockOpen() : null;
            $estorno = Advance::query()->create([
                'professional_id' => $original->professional_id,
                'kind' => AdvanceKind::Reversal,
                'amount_cents' => -$original->amount_cents,
                'reverses_advance_id' => $original->id,
                'issued_on' => BusinessTime::today(),
                'reference_month' => substr(BusinessTime::today(), 0, 7),
                'description' => mb_substr($motivo, 0, 255),
                'method' => $original->method,
                'cash_session_id' => $caixa?->id,
                'created_by_user_id' => $actor->id,
                'request_key' => $key,
                'occurred_at' => BusinessTime::now(),
            ]);
            if ($caixa !== null) {
                $this->cash->recordProfessionalMovement($caixa, CashMovementType::AdvanceReversal, $original->amount_cents, 'Estorno do vale #'.$original->id.' · '.$motivo, $actor, ['advance_id' => $estorno->id]);
            }

            AuditTrail::record('advance.reversed', $estorno, $actor, 'Vale #'.$original->id.' estornado.', [
                'valor_cents' => $original->amount_cents, 'motivo' => $motivo,
            ]);

            return $estorno;
        });
    }
}
