<?php

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Enums\ConsentAction;
use App\Modules\Customers\Enums\MarketingConsent;
use App\Modules\Customers\Models\ConsentRecord;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\EmailSuppression;
use App\Modules\Customers\Support\Email;
use Illuminate\Support\Facades\DB;

/**
 * Consentimento de marketing por e-mail (LGPD).
 *
 * - "Desconhecido" nunca vira "aceito" sozinho.
 * - Revogar vale para o cliente E para o e-mail (lista de supressao), mesmo
 *   sem cadastro e mesmo que o cliente troque de e-mail depois.
 * - Todo aceite/revogacao gera prova em consent_records.
 */
class MarketingConsentService
{
    public function revoke(?Customer $customer, ?string $email, string $source, ?string $evidence = null): void
    {
        $email = Email::normalize($email ?? $customer?->email);

        DB::transaction(function () use ($customer, $email, $source, $evidence) {
            if ($customer) {
                $customer->forceFill([
                    'marketing_email_consent' => MarketingConsent::Revoked,
                    'marketing_consent_updated_at' => now(),
                ])->save();
            }
            if ($email !== null) {
                EmailSuppression::firstOrCreate(
                    ['email' => $email],
                    ['reason' => 'marketing_opt_out', 'customer_id' => $customer?->getKey(), 'suppressed_at' => now()],
                );
            }
            ConsentRecord::create([
                'customer_id' => $customer?->getKey(),
                'email' => $email,
                'purpose' => 'marketing_email',
                'action' => ConsentAction::Revoked,
                'source' => $source,
                'occurred_at' => now(),
                'evidence' => $evidence,
            ]);
        });
    }

    /**
     * Aceite explicito do proprio cliente. Remove a supressao por opt-out
     * (nunca a de bounce/reclamacao).
     */
    public function grant(Customer $customer, string $source, ?string $evidence = null): void
    {
        DB::transaction(function () use ($customer, $source, $evidence) {
            $customer->forceFill([
                'marketing_email_consent' => MarketingConsent::Granted,
                'marketing_consent_updated_at' => now(),
            ])->save();

            if ($customer->email !== null) {
                EmailSuppression::where('email', $customer->email)->where('reason', 'marketing_opt_out')->delete();
            }

            ConsentRecord::create([
                'customer_id' => $customer->getKey(),
                'email' => $customer->email,
                'purpose' => 'marketing_email',
                'action' => ConsentAction::Granted,
                'source' => $source,
                'occurred_at' => now(),
                'evidence' => $evidence,
            ]);
        });
    }
}
