<?php

namespace Tests\Concerns;

use App\Modules\Catalog\Models\Service;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Checkout\Services\AttendancePricing;
use App\Modules\Checkout\Services\PaymentLine;
use App\Modules\Finance\Enums\CommissionRuleType;
use App\Modules\Finance\Enums\CommissionTarget;
use App\Modules\Finance\Enums\LedgerEntryKind;
use App\Modules\Finance\Models\CommissionEntry;
use App\Modules\Finance\Models\CommissionRule;
use App\Modules\Finance\Services\Advances;
use App\Modules\Finance\Services\CommissionRules;
use App\Modules\Finance\Services\Payouts;
use App\Modules\Finance\Services\ProfessionalLedger;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Team\Models\Professional;

/**
 * Comissao, gorjeta, vales e repasse sobre o atendimento ficticio
 * (CheckoutFixtures): caixa aberto com R$ 100,00; dono e financeiro
 * ficticios. Nenhuma regra de comissao existe ate o teste criar.
 */
trait FinanceFixtures
{
    use CheckoutFixtures;

    protected User $dono;

    protected User $financeiro;

    protected function setUpFinance(): void
    {
        $this->setUpCheckout();
        $this->dono = User::factory()->owner()->create(['name' => 'Dono Fictício']);
        $this->financeiro = User::factory()->role(StaffRole::Finance)->create(['name' => 'Financeiro Fictício']);
        $this->openCash();
    }

    protected function rules(): CommissionRules
    {
        return app(CommissionRules::class);
    }

    protected function ledger(): ProfessionalLedger
    {
        return app(ProfessionalLedger::class);
    }

    protected function payouts(): Payouts
    {
        return app(Payouts::class);
    }

    protected function advances(): Advances
    {
        return app(Advances::class);
    }

    /** Percentual em pontos-base (4000 = 40%). */
    protected function percent(int $bp, ?Professional $pro = null, ?Service $service = null, CommissionTarget $target = CommissionTarget::Service): CommissionRule
    {
        return $this->rules()->set($target, $pro, $service, CommissionRuleType::Percent, $bp, null, null, $this->dono);
    }

    protected function fixed(int $cents, ?Professional $pro = null, ?Service $service = null): CommissionRule
    {
        return $this->rules()->set(CommissionTarget::Service, $pro, $service, CommissionRuleType::Fixed, null, $cents, null, $this->dono);
    }

    protected function noCommission(?Professional $pro = null, ?Service $service = null, CommissionTarget $target = CommissionTarget::Service): CommissionRule
    {
        return $this->rules()->set($target, $pro, $service, CommissionRuleType::None, null, null, 'Sem comissão', $this->dono);
    }

    /**
     * Conclui o atendimento ja iniciado com estes pagamentos.
     *
     * @param  list<PaymentLine>|null  $payments  nulo = Pix do total, sem gorjeta
     */
    protected function finish(Attendance $at, ?array $payments = null): Attendance
    {
        $total = (int) app(AttendancePricing::class)->breakdown($at)->total?->cents;

        return $this->attendances()->complete($at, $payments ?? $this->pay($total), $this->key(), $this->recepcao);
    }

    /** Atendimento do Joao (corte R$ 50,00, 10:00) concluido. */
    protected function completedAttendance(?array $payments = null): Attendance
    {
        return $this->finish($this->startedAttendance(), $payments);
    }

    /**
     * Comissoes calculadas (valor por item, na ordem dos itens).
     *
     * @return list<int>
     */
    protected function earned(Attendance $at): array
    {
        return CommissionEntry::query()->where('attendance_id', $at->id)->where('kind', LedgerEntryKind::Earned)
            ->orderBy('attendance_item_id')->pluck('amount_cents')->map(fn ($v) => (int) $v)->all();
    }
}
