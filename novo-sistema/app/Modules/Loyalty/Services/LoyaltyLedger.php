<?php

namespace App\Modules\Loyalty\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Loyalty\Enums\LoyaltyEntryKind;
use App\Modules\Loyalty\Models\LoyaltyEntry;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Pontos de fidelidade como razao: o saldo e sempre a soma dos lancamentos.
 * Nao existe saldo gravado que possa divergir do historico.
 */
class LoyaltyLedger
{
    public function balance(Customer|int $customer): int
    {
        $id = $customer instanceof Customer ? $customer->getKey() : $customer;

        return (int) LoyaltyEntry::where('customer_id', $id)->sum('points');
    }

    public function credit(Customer $customer, int $points, LoyaltyEntryKind $kind, ?string $description = null, ?int $appointmentId = null): LoyaltyEntry
    {
        if ($points <= 0) {
            throw DomainRuleViolation::rule('R-PONTOS', 'Credito de pontos deve ser positivo.');
        }

        return $this->record($customer, $points, $kind, $description, $appointmentId);
    }

    /** Resgate: nunca deixa o saldo negativo. */
    public function debit(Customer $customer, int $points, LoyaltyEntryKind $kind, ?string $description = null, ?int $appointmentId = null): LoyaltyEntry
    {
        if ($points <= 0) {
            throw DomainRuleViolation::rule('R-PONTOS', 'Debito de pontos deve ser positivo.');
        }

        return DB::transaction(function () use ($customer, $points, $kind, $description, $appointmentId) {
            if ($this->balance($customer) < $points) {
                throw DomainRuleViolation::rule('R-PONTOS', 'Saldo de pontos insuficiente.');
            }

            return $this->record($customer, -$points, $kind, $description, $appointmentId);
        });
    }

    private function record(Customer $customer, int $points, LoyaltyEntryKind $kind, ?string $description, ?int $appointmentId): LoyaltyEntry
    {
        return LoyaltyEntry::create([
            'customer_id' => $customer->getKey(),
            'points' => $points,
            'kind' => $kind,
            'description' => $description,
            'appointment_id' => $appointmentId,
            'occurred_at' => now(),
        ]);
    }
}
