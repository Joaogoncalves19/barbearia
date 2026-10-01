<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Enums\CashMovementType;
use App\Modules\Finance\Enums\CashSessionStatus;
use App\Modules\Finance\Enums\PaymentKind;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Exceptions\CashRuleViolation;
use App\Modules\Finance\Models\CashMovement;
use App\Modules\Finance\Models\CashSession;
use App\Modules\Finance\Models\Payment;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\System\Services\AuditTrail;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * O UNICO lugar que abre, movimenta e fecha o caixa (caixa.md).
 *
 * - Um caixa aberto por barbearia (decisao do dono): open_marker unico no
 *   banco, alem da checagem aqui.
 * - Toda operacao num caixa roda numa transacao que PRIMEIRO escreve na
 *   linha do caixa (version + 1): movimentar e fechar ao mesmo tempo ficam em
 *   fila; quem chega depois do fechamento recebe "caixa fechado".
 * - Valor esperado em dinheiro = valor inicial + movimentacoes em dinheiro
 *   (pagamentos e suprimentos entram, estornos e sangrias saem). Pix e
 *   cartao aparecem no resumo por forma, mas nao estao na gaveta.
 * - Nada e apagado ou editado: caixa fechado e razao sao historico.
 */
final class CashRegister
{
    /** Gancho SO para o teste de concorrencia (depois da trava, antes de gravar). */
    public static ?Closure $afterLock = null;

    public function current(): ?CashSession
    {
        return CashSession::query()->where('status', CashSessionStatus::Open)->first();
    }

    public function open(int $openingFloatCents, ?string $notes, User $actor): CashSession
    {
        if ($openingFloatCents < 0) {
            throw new CashRuleViolation('invalid_amount');
        }

        try {
            return DB::transaction(function () use ($openingFloatCents, $notes, $actor): CashSession {
                if ($this->current() !== null) {
                    throw new CashRuleViolation('already_open');
                }

                $s = CashSession::query()->create([
                    'open_marker' => 1,
                    'status' => CashSessionStatus::Open,
                    'opened_by_user_id' => $actor->id,
                    'opened_at' => BusinessTime::now(),
                    'opening_float_cents' => $openingFloatCents,
                    'opening_notes' => $this->clean($notes),
                ]);
                AuditTrail::record('cash.opened', $s, $actor, 'Caixa aberto.', ['valor_inicial_cents' => $openingFloatCents]);

                return $s;
            });
        } catch (QueryException $e) {
            // Duas aberturas ao mesmo tempo: o indice unico de open_marker barra a segunda.
            if ($this->current() !== null) {
                throw new CashRuleViolation('already_open');
            }
            throw $e;
        }
    }

    /** Suprimento (reforco de troco): entra dinheiro. */
    public function supply(CashSession $session, int $amountCents, string $reason, User $actor, ?string $key = null): CashMovement
    {
        return $this->manual($session, CashMovementType::Supply, $amountCents, $reason, $actor, $key);
    }

    /** Sangria (retirada de dinheiro): nunca mais do que ha em dinheiro. */
    public function withdraw(CashSession $session, int $amountCents, string $reason, User $actor, ?string $key = null): CashMovement
    {
        return $this->manual($session, CashMovementType::Withdrawal, $amountCents, $reason, $actor, $key);
    }

    /**
     * Fecha o caixa com a contagem do dinheiro. Diferenca (contado -
     * esperado) fica registrada; se houver, a justificativa e obrigatoria.
     */
    public function close(CashSession $session, int $countedCashCents, ?string $notes, User $actor): CashSession
    {
        if ($countedCashCents < 0) {
            throw new CashRuleViolation('invalid_amount');
        }

        return DB::transaction(function () use ($session, $countedCashCents, $notes, $actor): CashSession {
            $s = $this->lockSession($session->id);
            if (self::$afterLock !== null) {
                (self::$afterLock)();
            }
            $esperado = $this->expectedCash($s);
            $diferenca = $countedCashCents - $esperado;
            $obs = $this->clean($notes);
            if ($diferenca !== 0 && ($obs === null || mb_strlen($obs) < 5)) {
                throw new CashRuleViolation('justification_required');
            }

            $s->forceFill([
                'open_marker' => null,
                'status' => CashSessionStatus::Closed,
                'closed_by_user_id' => $actor->id,
                'closed_at' => BusinessTime::now(),
                'expected_cash_cents' => $esperado,
                'counted_cash_cents' => $countedCashCents,
                'difference_cents' => $diferenca,
                'closing_notes' => $obs,
            ])->save();

            AuditTrail::record('cash.closed', $s, $actor, 'Caixa fechado.', [
                'esperado_cents' => $esperado, 'contado_cents' => $countedCashCents, 'diferenca_cents' => $diferenca,
            ]);

            return $s;
        });
    }

    /**
     * Trava o caixa aberto dentro da transacao do chamador e o devolve.
     * Usado pelo atendimento (pagamentos) e pelo estorno.
     */
    public function lockOpen(): CashSession
    {
        $id = CashSession::query()->where('status', CashSessionStatus::Open)->value('id');
        if ($id === null) {
            throw new CashRuleViolation('no_open_session');
        }

        return $this->lockSession((int) $id);
    }

    /**
     * Lanca no caixa (ja travado) a entrada de um pagamento ou a saida de um
     * estorno. Valor = pagamento + gorjeta (o que passou pela maquininha ou
     * pela gaveta).
     */
    public function recordPayment(CashSession $session, Payment $payment, string $description, ?User $actor): CashMovement
    {
        $valor = $payment->amount_cents + (int) $payment->tip_cents;
        $estorno = $payment->kind === PaymentKind::Refund;

        return CashMovement::query()->create([
            'cash_session_id' => $session->id,
            'type' => $estorno ? CashMovementType::Refund : CashMovementType::Payment,
            'method' => $payment->method,
            'amount_cents' => $estorno ? -$valor : $valor,
            'payment_id' => $payment->id,
            'description' => mb_substr($description, 0, 255),
            'created_by_user_id' => $actor?->id,
            'occurred_at' => BusinessTime::now(),
        ]);
    }

    /** Dinheiro que deveria estar na gaveta agora. */
    public function expectedCash(CashSession $session): int
    {
        return $session->opening_float_cents + (int) CashMovement::query()
            ->where('cash_session_id', $session->id)
            ->where('method', PaymentMethod::Cash->value)
            ->sum('amount_cents');
    }

    /**
     * Resumo do caixa: entradas e saidas por forma, e o dinheiro esperado.
     *
     * @return array{by_method: array<string, int>, inflow: int, outflow: int, expected_cash: int}
     */
    public function summary(CashSession $session): array
    {
        $porForma = [];
        $entradas = 0;
        $saidas = 0;
        foreach (CashMovement::query()->where('cash_session_id', $session->id)->get(['method', 'amount_cents']) as $m) {
            $porForma[$m->method->value] = ($porForma[$m->method->value] ?? 0) + $m->amount_cents;
            $m->amount_cents > 0 ? $entradas += $m->amount_cents : $saidas -= $m->amount_cents;
        }

        return ['by_method' => $porForma, 'inflow' => $entradas, 'outflow' => $saidas, 'expected_cash' => $this->expectedCash($session)];
    }

    private function manual(CashSession $session, CashMovementType $type, int $amountCents, string $reason, User $actor, ?string $key): CashMovement
    {
        if ($amountCents < 1) {
            throw new CashRuleViolation('invalid_amount');
        }
        $motivo = $this->clean($reason);
        if ($motivo === null || mb_strlen($motivo) < 3) {
            throw new CashRuleViolation('reason_required');
        }

        return DB::transaction(function () use ($session, $type, $amountCents, $motivo, $actor, $key): CashMovement {
            $s = $this->lockSession($session->id);

            if ($key !== null && ($existente = CashMovement::query()->where('request_key', $key)->first()) !== null) {
                return $existente; // repeticao da mesma requisicao
            }

            if ($type === CashMovementType::Withdrawal && $this->expectedCash($s) < $amountCents) {
                throw new CashRuleViolation('insufficient_cash');
            }

            $m = CashMovement::query()->create([
                'cash_session_id' => $s->id,
                'type' => $type,
                'method' => PaymentMethod::Cash,
                'amount_cents' => $type->isInflow() ? $amountCents : -$amountCents,
                'description' => $motivo,
                'request_key' => $key,
                'created_by_user_id' => $actor->id,
                'occurred_at' => BusinessTime::now(),
            ]);
            AuditTrail::record('cash.'.$type->value, $s, $actor, $type->label().' no caixa.', ['valor_cents' => $amountCents, 'motivo' => $motivo]);

            return $m;
        });
    }

    /** Primeira escrita da transacao: trava a linha do caixa e confere se segue aberto. */
    private function lockSession(int $id): CashSession
    {
        DB::table('cash_sessions')->where('id', $id)->increment('version');
        $s = CashSession::query()->findOrFail($id);
        if (! $s->isOpen()) {
            throw new CashRuleViolation('closed');
        }

        return $s;
    }

    private function clean(?string $text): ?string
    {
        $t = $text !== null ? trim($text) : '';

        return $t === '' ? null : mb_substr($t, 0, 255);
    }
}
