<?php

namespace App\Modules\Loyalty\Services;

use App\Modules\Finance\Enums\CashMovementType;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Exceptions\CashRuleViolation;
use App\Modules\Finance\Models\CashMovement;
use App\Modules\Finance\Services\CashRegister;
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Enums\GiftCardStatus;
use App\Modules\Loyalty\Exceptions\PromotionRejected;
use App\Modules\Loyalty\Models\GiftCard;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Shared\Support\Money;
use App\Modules\System\Services\AuditTrail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Vale-presente (vale-presente.md), decisao do dono: FORMA DE PAGAMENTO.
 *
 * - Venda: o dinheiro entra no caixa aberto (movimento gift_card_sale, na
 *   forma usada pelo comprador). E receita antecipada: nao e desconto.
 * - Uso: na conclusao do atendimento, como uma linha de pagamento com o
 *   codigo (AttendanceService::complete). Uso unico (R-13): o vale paga de
 *   uma vez o MENOR entre o seu valor e o total a pagar; nao entra na gaveta
 *   (o dinheiro entrou na venda). A comissao nao muda: o servico foi pago.
 * - Cancelamento (so disponivel): devolve o valor pelo caixa aberto, se a
 *   venda foi registrada aqui. Nada e apagado.
 *
 * Trava: linha do vale (version). Venda/cancelamento: vale -> caixa.
 */
final class GiftCards
{
    public const SALE_METHODS = [PaymentMethod::Cash, PaymentMethod::Pix, PaymentMethod::DebitCard, PaymentMethod::CreditCard, PaymentMethod::Other];

    public function __construct(private readonly CashRegister $cash) {}

    /**
     * @param  array{purchaser_name?: ?string, purchaser_email?: ?string, recipient_name?: ?string, recipient_email?: ?string, message?: ?string, expires_on?: ?string}  $data
     *
     * @throws PromotionRejected|CashRuleViolation
     */
    public function sell(int $amountCents, PaymentMethod $method, array $data, User $actor, string $key): GiftCard
    {
        if ($amountCents < 1 || $amountCents > 100_000_00) {
            throw new PromotionRejected('invalid_amount');
        }
        if (! in_array($method, self::SALE_METHODS, true)) {
            throw new PromotionRejected('invalid_method');
        }
        $validade = trim((string) ($data['expires_on'] ?? '')) ?: null;
        if ($validade !== null && (! BusinessTime::isValidDate($validade) || $validade < BusinessTime::today())) {
            throw new PromotionRejected('promotion_rejected', 'Informe uma validade a partir de hoje (ou deixe sem validade).');
        }

        return DB::transaction(function () use ($amountCents, $method, $data, $validade, $actor, $key): GiftCard {
            $existente = GiftCard::query()->where('request_key', $key)->first();
            if ($existente !== null) {
                return $existente;
            }
            $caixa = $this->cash->lockOpen();

            $vale = GiftCard::query()->create([
                'code' => $this->newCode(),
                'amount_cents' => $amountCents,
                'status' => GiftCardStatus::Available,
                'issued_at' => BusinessTime::now(),
                'expires_on' => $validade,
                'purchaser_name' => $this->clean($data['purchaser_name'] ?? null, 255),
                'purchaser_email' => $this->email($data['purchaser_email'] ?? null),
                'recipient_name' => $this->clean($data['recipient_name'] ?? null, 255),
                'recipient_email' => $this->email($data['recipient_email'] ?? null),
                'message' => $this->clean($data['message'] ?? null, 500),
                'sale_method' => $method,
                'sale_cash_session_id' => $caixa->id,
                'sold_by_user_id' => $actor->id,
                'is_legacy' => false,
                'request_key' => $key,
            ]);
            CashMovement::query()->create([
                'cash_session_id' => $caixa->id,
                'type' => CashMovementType::GiftCardSale,
                'method' => $method,
                'amount_cents' => $amountCents,
                'gift_card_id' => $vale->id,
                'description' => 'Venda do vale-presente '.$vale->code,
                'created_by_user_id' => $actor->id,
                'occurred_at' => BusinessTime::now(),
            ]);
            AuditTrail::record('gift_card.sold', $vale, $actor, 'Vale-presente '.$vale->code.' vendido: '.Money::fromCents($amountCents)->format().'.', [
                'valor_cents' => $amountCents, 'forma' => $method->value,
            ]);

            return $vale;
        });
    }

    /**
     * @throws PromotionRejected|CashRuleViolation
     */
    public function cancel(GiftCard $card, string $reason, User $actor): GiftCard
    {
        $motivo = trim($reason);
        if (mb_strlen($motivo) < 3) {
            throw new PromotionRejected('reason_required');
        }

        return DB::transaction(function () use ($card, $motivo, $actor): GiftCard {
            $vale = $this->lock($card->id);
            if ($vale->status !== GiftCardStatus::Available) {
                throw new PromotionRejected('already_cancelled');
            }
            $devolve = ! $vale->is_legacy && $vale->sale_method !== null;
            $caixa = $devolve ? $this->cash->lockOpen() : null;
            if ($caixa !== null && $vale->sale_method === PaymentMethod::Cash && $this->cash->expectedCash($caixa) < $vale->amount_cents) {
                throw new CashRuleViolation('insufficient_cash_for_professional');
            }

            $vale->forceFill(['status' => GiftCardStatus::Cancelled, 'cancelled_at' => BusinessTime::now(), 'cancel_reason' => mb_substr($motivo, 0, 255), 'cancelled_by_user_id' => $actor->id])->save();
            if ($caixa !== null) {
                CashMovement::query()->create([
                    'cash_session_id' => $caixa->id,
                    'type' => CashMovementType::GiftCardRefund,
                    'method' => $vale->sale_method,
                    'amount_cents' => -$vale->amount_cents,
                    'gift_card_id' => $vale->id,
                    'description' => 'Devolução do vale-presente '.$vale->code.' · '.$motivo,
                    'created_by_user_id' => $actor->id,
                    'occurred_at' => BusinessTime::now(),
                ]);
            }
            AuditTrail::record('gift_card.cancelled', $vale, $actor, 'Vale-presente '.$vale->code.' cancelado.', [
                'valor_cents' => $vale->amount_cents, 'devolvido' => $devolve ? 'sim' : 'nao', 'motivo' => $motivo,
            ]);

            return $vale;
        });
    }

    /**
     * Confere um vale para pagar um atendimento (dentro da transacao da
     * conclusao, ja travado): disponivel, na validade, e o valor da linha e
     * exatamente o que o uso unico permite.
     *
     * @throws PromotionRejected
     */
    public function checkForPayment(string $code, int $lineAmountCents, int $dueCents): GiftCard
    {
        $id = GiftCard::query()->where('code', GiftCard::normalizeCode($code))->value('id');
        if ($id === null) {
            throw new PromotionRejected('gift_card_not_found');
        }
        $vale = $this->lock((int) $id);
        if (! $vale->isUsable()) {
            throw new PromotionRejected('gift_card_unusable', 'Situação: '.mb_strtolower($vale->situationLabel()).'.');
        }
        $permitido = min($vale->amount_cents, $dueCents);
        if ($lineAmountCents !== $permitido) {
            throw new PromotionRejected('gift_card_amount', 'Use '.Money::fromCents($permitido)->format().' deste vale.');
        }

        return $vale;
    }

    public function lock(int $id): GiftCard
    {
        DB::table('gift_cards')->where('id', $id)->increment('version');

        return GiftCard::query()->findOrFail($id);
    }

    private function newCode(): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $sufixo = '';
            for ($i = 0; $i < 8; $i++) {
                $sufixo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            }
            $codigo = 'PRESENTE-'.$sufixo;
        } while (GiftCard::query()->where('code', $codigo)->exists());

        return $codigo;
    }

    private function clean(?string $text, int $max): ?string
    {
        $t = $text !== null ? trim($text) : '';

        return $t === '' ? null : mb_substr($t, 0, $max);
    }

    private function email(?string $email): ?string
    {
        $e = $email !== null ? mb_strtolower(trim($email)) : '';
        if ($e === '') {
            return null;
        }
        if (filter_var($e, FILTER_VALIDATE_EMAIL) === false) {
            throw new PromotionRejected('promotion_rejected', 'E-mail inválido: '.$e);
        }

        return $e;
    }

    /** Validade padrao sugerida na tela: um ano. */
    public static function suggestedExpiry(): string
    {
        return CarbonImmutable::createFromFormat('Y-m-d', BusinessTime::today())->addYear()->toDateString();
    }
}
