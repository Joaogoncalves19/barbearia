<?php

namespace App\Modules\Communication\Services;

use App\Modules\Customers\Enums\ConsentAction;
use App\Modules\Customers\Enums\MarketingConsent;
use App\Modules\Customers\Models\ConsentRecord;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\MarketingConsentService;
use Illuminate\Support\Facades\DB;

/**
 * Preferencias de comunicacao do PROPRIO cliente (consentimento.md):
 *
 * - marketing (campanhas, promocoes, novidades): consentimento LGPD pelo
 *   MarketingConsentService (prova em consent_records, lista de supressao).
 *   So muda por acao explicita do cliente; "desconhecido" fica
 *   desconhecido ate ele escolher.
 * - lembretes por e-mail: transacionais OPCIONAIS (D-51). Desligar nao
 *   afeta confirmacao, remarcacao, cancelamento, comprovante nem assinatura
 *   (necessarios ao servico). A mudanca tambem fica em consent_records
 *   (finalidade reminder_email).
 *
 * Nada muda se o valor pedido ja e o atual (sem registro repetido).
 */
final class CommunicationPreferences
{
    public function __construct(private readonly MarketingConsentService $consent) {}

    /**
     * $marketing nulo = o cliente nao escolheu (fica como esta; quem nunca
     * respondeu continua "desconhecido": nada e presumido em nenhum sentido).
     *
     * @return list<string> o que mudou (para a mensagem da tela)
     */
    public function update(Customer $customer, ?bool $marketing, bool $reminders, string $source, ?string $evidence = null): array
    {
        $mudou = [];
        $atual = $customer->marketing_email_consent;
        if ($marketing === true && $atual !== MarketingConsent::Granted) {
            $this->consent->grant($customer, $source, $evidence);
            $mudou[] = 'novidades por e-mail ligadas';
        } elseif ($marketing === false && $atual !== MarketingConsent::Revoked) {
            $this->consent->revoke($customer, $customer->email, $source, $evidence);
            $mudou[] = 'novidades por e-mail desligadas';
        }

        if ($reminders !== (bool) $customer->email_reminders_enabled) {
            DB::transaction(function () use ($customer, $reminders, $source, $evidence): void {
                $customer->forceFill(['email_reminders_enabled' => $reminders])->save();
                ConsentRecord::query()->create([
                    'customer_id' => $customer->getKey(),
                    'email' => $customer->email,
                    'purpose' => 'reminder_email',
                    'action' => $reminders ? ConsentAction::Granted : ConsentAction::Revoked,
                    'source' => $source,
                    'occurred_at' => now(),
                    'evidence' => $evidence,
                ]);
            });
            $mudou[] = $reminders ? 'lembretes por e-mail ligados' : 'lembretes por e-mail desligados';
        }

        return $mudou;
    }
}
