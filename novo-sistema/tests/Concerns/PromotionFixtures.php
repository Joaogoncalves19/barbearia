<?php

namespace Tests\Concerns;

use App\Modules\Customers\Models\Customer;
use App\Modules\Loyalty\Enums\LoyaltyEntryKind;
use App\Modules\Loyalty\Models\Coupon;
use App\Modules\Loyalty\Pricing\PromotionEngine;
use App\Modules\Loyalty\Pricing\PromotionRequest;
use App\Modules\Loyalty\Services\GiftCards;
use App\Modules\Loyalty\Services\LoyaltyLedger;
use App\Modules\Loyalty\Services\PromotionService;
use App\Modules\Loyalty\Support\PromotionPolicy;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\BookingRequest;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\Team\Models\Professional;

/**
 * Promocoes ficticias sobre o atendimento (CheckoutFixtures; relogio na
 * segunda 05/10/2026 08:00): fidelidade ligada (1 ponto por visita, resgate
 * de 10 pontos = 50% no servico mais barato), aniversario 15% e indicacao 10%
 * (desligados ate o teste ligar). Caixa aberto com R$ 100,00.
 */
trait PromotionFixtures
{
    use CheckoutFixtures;

    protected function setUpPromotions(): void
    {
        $this->setUpCheckout();
        $this->openCash();
        PromotionPolicy::save([
            'loyalty_enabled' => true, 'loyalty_earn_mode' => 'visit', 'loyalty_points_per_visit' => 1, 'loyalty_points_required' => 10,
            'loyalty_reward_type' => 'percent', 'loyalty_reward_base' => 'cheapest', 'loyalty_reward_percent_bp' => 5000,
            'birthday_enabled' => false, 'birthday_percent_bp' => 1500, 'referral_enabled' => false, 'referral_percent_bp' => 1000, 'referral_bonus_points' => 3,
        ], null);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function policy(array $values): void
    {
        PromotionPolicy::save($values, null);
    }

    protected function engine(): PromotionEngine
    {
        return app(PromotionEngine::class);
    }

    protected function promotions(): PromotionService
    {
        return app(PromotionService::class);
    }

    protected function loyalty(): LoyaltyLedger
    {
        return app(LoyaltyLedger::class);
    }

    protected function giftCards(): GiftCards
    {
        return app(GiftCards::class);
    }

    protected function coupon(string $code, string $type = 'percent', int $value = 1000, ?int $maxUses = null, array $extra = []): Coupon
    {
        return Coupon::query()->create([
            'code' => $code, 'discount_type' => $type,
            'percent_bp' => $type === 'percent' ? $value : null, 'amount_cents' => $type === 'fixed' ? $value : null,
            'max_uses' => $maxUses, 'is_active' => true, ...$extra,
        ]);
    }

    protected function givePoints(Customer $customer, int $points): void
    {
        $this->loyalty()->credit($customer, $points, LoyaltyEntryKind::Adjustment, 'Pontos de teste');
    }

    /** Agendamento de hoje (equipe), com promocao pedida. */
    protected function bookToday(Customer $customer, string $time = '10:00', ?PromotionRequest $promo = null, ?Professional $pro = null, ?int $expected = null): Appointment
    {
        return $this->booking()->book(new BookingRequest(
            service: $this->corte,
            professional: $pro ?? $this->joao,
            start: $this->at($this->segunda, $time),
            channel: Channel::Staff,
            source: AppointmentSource::Staff,
            customer: $customer,
            actor: $this->recepcao,
            promotion: $promo,
            expectedTotalCents: $expected,
        ));
    }
}
