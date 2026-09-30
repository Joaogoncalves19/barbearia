<?php

namespace Tests\Concerns;

use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BusinessHour;
use App\Modules\Scheduling\Services\Availability;
use App\Modules\Scheduling\Services\BookingRequest;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Support\BookingPolicy;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\Team\Models\Professional;
use Carbon\CarbonImmutable;

/**
 * Agenda ficticia para os testes: relogio parado numa SEGUNDA-FEIRA,
 * 05/10/2026 as 08:00 em Sao Paulo (11:00 UTC); barbearia aberta todos os
 * dias das 09:00 as 20:00; "Corte" de 30 min por R$ 50,00 feito pelo Joao.
 */
trait AgendaFixtures
{
    protected Service $corte;

    protected Professional $joao;

    protected Customer $cliente;

    /** Segunda-feira do relogio parado. */
    protected string $segunda = '2026-10-05';

    /** Dia seguinte (terca). */
    protected string $terca = '2026-10-06';

    protected function setUpAgenda(): void
    {
        config(['barbearia.display_timezone' => 'America/Sao_Paulo']);
        $this->travelTo(CarbonImmutable::parse('2026-10-05 11:00:00', 'UTC'));

        for ($d = 0; $d <= 6; $d++) {
            BusinessHour::query()->create(['weekday' => $d, 'starts_at' => '09:00', 'ends_at' => '20:00']);
        }
        BookingPolicy::save(['min_notice_minutes' => 120, 'max_advance_days' => 30, 'slot_step_minutes' => 15]);

        $this->corte = Service::factory()->create(['name' => 'Corte', 'duration_minutes' => 30, 'price_cents' => 5000]);
        $this->joao = Professional::factory()->create(['display_name' => 'João']);
        $this->joao->services()->attach($this->corte->id);
        $this->cliente = Customer::factory()->create(['name' => 'Cliente Fictício']);
    }

    /** Instante UTC de uma hora local da barbearia. */
    protected function at(string $date, string $time): CarbonImmutable
    {
        return BusinessTime::at($date, $time);
    }

    protected function availability(): Availability
    {
        return app(Availability::class);
    }

    protected function booking(): BookingService
    {
        return app(BookingService::class);
    }

    protected function book(string $date, string $time, ?Professional $pro = null, ?Service $service = null, Channel $channel = Channel::Customer, ?Customer $customer = null): Appointment
    {
        return $this->booking()->book(new BookingRequest(
            service: $service ?? $this->corte,
            professional: $pro ?? $this->joao,
            start: $this->at($date, $time),
            channel: $channel,
            source: $channel === Channel::Customer ? AppointmentSource::Online : AppointmentSource::Staff,
            customer: $customer ?? Customer::factory()->create(),
            actor: null,
        ));
    }

    /**
     * Horarios livres (HH:MM locais) de um dia.
     *
     * @return list<string>
     */
    protected function freeTimes(string $date, ?Professional $pro = null, ?Service $service = null, Channel $channel = Channel::Customer): array
    {
        return array_map(
            fn (array $s) => BusinessTime::local($s['start'])->format('H:i'),
            $this->availability()->slots($service ?? $this->corte, $pro ?? $this->joao, $date, $channel),
        );
    }

    protected function reasonAt(string $date, string $time, ?Professional $pro = null, ?Service $service = null, Channel $channel = Channel::Customer): ?string
    {
        $r = $this->availability()->check($service ?? $this->corte, $pro ?? $this->joao, $this->at($date, $time), $channel);

        return $r->reasons[0] ?? null;
    }
}
