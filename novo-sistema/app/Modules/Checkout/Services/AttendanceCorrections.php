<?php

namespace App\Modules\Checkout\Services;

use App\Modules\Catalog\Exceptions\StockRuleViolation;
use App\Modules\Catalog\Models\StockMovement;
use App\Modules\Catalog\Services\StockLedger;
use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Finance\Enums\AmountSource;
use App\Modules\Finance\Enums\PaymentKind;
use App\Modules\Finance\Exceptions\CashRuleViolation;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\CashRegister;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Shared\Support\Money;
use App\Modules\System\Services\AuditTrail;
use Illuminate\Support\Facades\DB;

/**
 * Correcoes de um atendimento CONCLUIDO, sem reescrever o passado
 * (pagamentos.md, "Estorno"; estoque.md, "Reversao"):
 *
 * - estorno de pagamento: novo registro kind=refund que aponta o original,
 *   com saida no caixa aberto. O pagamento original nunca muda;
 * - devolucao ao estoque: movimento inverso que aponta a venda/consumo.
 *
 * O atendimento continua concluido, com os valores do dia; o historico
 * mostra o que foi corrigido, por quem e por que.
 */
final class AttendanceCorrections
{
    public function __construct(
        private readonly AttendanceService $attendances,
        private readonly CashRegister $cash,
        private readonly StockLedger $stock,
    ) {}

    /** Quanto deste pagamento ainda pode ser estornado (valor + gorjeta - estornos). */
    public function refundable(Payment $payment): int
    {
        $estornado = (int) Payment::query()->where('refunds_payment_id', $payment->id)->sum('amount_cents');

        return $payment->amount_cents + (int) $payment->tip_cents - $estornado;
    }

    public function refund(Payment $payment, int $amountCents, string $reason, User $actor, string $key): Payment
    {
        $motivo = trim($reason);
        if ($amountCents < 1) {
            throw new CashRuleViolation('invalid_amount');
        }
        if (mb_strlen($motivo) < 3) {
            throw new CashRuleViolation('reason_required');
        }
        if ($payment->kind !== PaymentKind::Payment || $payment->attendance_id === null) {
            throw new CashRuleViolation('not_refundable');
        }

        return DB::transaction(function () use ($payment, $amountCents, $motivo, $actor, $key): Payment {
            $at = $this->attendances->lock((int) $payment->attendance_id);

            $existente = Payment::query()->where('request_key', $key)->first();
            if ($existente !== null) {
                return $existente; // repeticao da mesma requisicao
            }
            if ($at->status !== AttendanceStatus::Completed) {
                throw new CashRuleViolation('not_refundable');
            }
            if ($amountCents > $this->refundable($payment)) {
                throw new CashRuleViolation('refund_exceeds');
            }

            $caixa = $this->cash->lockOpen();
            $estorno = Payment::query()->create([
                'attendance_id' => $at->id,
                'customer_id' => $payment->customer_id,
                'cash_session_id' => $caixa->id,
                'kind' => PaymentKind::Refund,
                'refunds_payment_id' => $payment->id,
                'method' => $payment->method,
                'amount_cents' => $amountCents,
                'tip_cents' => 0,
                'amount_source' => AmountSource::Recorded,
                'paid_at' => BusinessTime::now(),
                'reason' => mb_substr($motivo, 0, 255),
                'request_key' => $key,
                'received_by_user_id' => $actor->id,
                'received_by_label' => $actor->name,
            ]);
            $this->cash->recordPayment($caixa, $estorno, 'Estorno do atendimento '.$at->code.' · '.$motivo, $actor);

            $this->attendances->event($at, 'refunded', 'Estorno de '.Money::fromCents($amountCents)->format().' ('.$payment->method->label().').', $actor, [
                'pagamento_id' => $payment->id, 'valor_cents' => $amountCents, 'motivo' => $motivo,
            ]);
            AuditTrail::record('payment.refunded', $estorno, $actor, 'Estorno de pagamento.', [
                'pagamento_original' => $payment->id, 'valor_cents' => $amountCents, 'motivo' => $motivo,
            ]);

            return $estorno;
        });
    }

    /** Devolve ao estoque um produto vendido ou consumido no atendimento. */
    public function returnToStock(StockMovement $movement, string $reason, User $actor, string $key): StockMovement
    {
        if ($movement->attendance_id === null || ! $movement->kind->comesFromAttendance()) {
            throw new StockRuleViolation('not_reversible');
        }

        return DB::transaction(function () use ($movement, $reason, $actor, $key): StockMovement {
            $at = $this->attendances->lock((int) $movement->attendance_id);
            $jaFeito = StockMovement::query()->where('request_key', $key)->exists();

            $reversao = $this->stock->reverse($movement, $reason, $actor, $key);
            if (! $jaFeito) {
                $this->attendances->event($at, 'stock_returned', 'Devolvido ao estoque: '.abs($movement->quantity).' × '.($movement->product->name ?? 'produto').'.', $actor, [
                    'movimento_id' => $movement->id, 'motivo' => trim($reason),
                ]);
            }

            return $reversao;
        });
    }
}
