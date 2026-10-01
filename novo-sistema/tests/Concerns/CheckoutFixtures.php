<?php

namespace Tests\Concerns;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Services\StockLedger;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Checkout\Services\AttendanceCorrections;
use App\Modules\Checkout\Services\AttendanceService;
use App\Modules\Checkout\Services\PaymentLine;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Models\CashSession;
use App\Modules\Finance\Services\CashRegister;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\BookingRequest;
use App\Modules\Scheduling\Support\Channel;
use Illuminate\Support\Str;

/**
 * Atendimento ficticio sobre a agenda dos testes (AgendaFixtures): relogio
 * na segunda 05/10/2026 08:00 (Sao Paulo); "Corte" de R$ 50,00 com o Joao;
 * "Barba" de R$ 30,00 que o Joao tambem faz; pomada a venda (R$ 35,00, custo
 * R$ 15,00) com 10 no estoque; lamina (insumo, sem preco) com 100.
 */
trait CheckoutFixtures
{
    use AgendaFixtures;

    protected User $recepcao;

    protected Service $barba;

    protected Product $pomada;

    protected Product $lamina;

    protected function setUpCheckout(): void
    {
        $this->setUpAgenda();
        $this->recepcao = User::factory()->create(['name' => 'Recepção Fictícia']);
        $this->barba = Service::factory()->create(['name' => 'Barba', 'duration_minutes' => 30, 'price_cents' => 3000]);
        $this->joao->services()->attach($this->barba->id);

        $gerente = User::factory()->manager()->create();
        $this->pomada = Product::factory()->create(['name' => 'Pomada modeladora', 'price_cents' => 3500, 'cost_cents' => 1500]);
        $this->lamina = Product::factory()->supply()->create(['name' => 'Lâmina descartável']);
        $this->stock()->receive($this->pomada, 10, 1500, 'Estoque inicial', $gerente);
        $this->stock()->receive($this->lamina, 100, 80, 'Estoque inicial', $gerente);
    }

    protected function attendances(): AttendanceService
    {
        return app(AttendanceService::class);
    }

    protected function corrections(): AttendanceCorrections
    {
        return app(AttendanceCorrections::class);
    }

    protected function cash(): CashRegister
    {
        return app(CashRegister::class);
    }

    protected function stock(): StockLedger
    {
        return app(StockLedger::class);
    }

    protected function openCash(int $floatCents = 10000): CashSession
    {
        return $this->cash()->open($floatCents, null, $this->recepcao);
    }

    /** Agendamento de hoje (segunda), 10:00, com o Joao. */
    protected function todayAppointment(string $time = '10:00'): Appointment
    {
        return $this->booking()->book(new BookingRequest(
            service: $this->corte,
            professional: $this->joao,
            start: $this->at($this->segunda, $time),
            channel: Channel::Staff,
            source: AppointmentSource::Staff,
            customer: $this->cliente,
        ));
    }

    /** Atendimento aberto a partir do agendamento de hoje e ja iniciado. */
    protected function startedAttendance(): Attendance
    {
        $at = $this->attendances()->openFromAppointment($this->todayAppointment(), $this->recepcao);

        return $this->attendances()->start($at, $this->recepcao);
    }

    /** @return list<PaymentLine> */
    protected function pay(int $cents, PaymentMethod $method = PaymentMethod::Pix, int $tip = 0): array
    {
        return [new PaymentLine($method, $cents, $tip)];
    }

    protected function key(): string
    {
        return (string) Str::uuid();
    }
}
