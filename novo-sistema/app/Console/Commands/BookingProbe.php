<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Exceptions\BookingRuleViolation;
use App\Modules\Scheduling\Exceptions\SlotUnavailable;
use App\Modules\Scheduling\Services\BookingRequest;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\Team\Models\Professional;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sonda do TESTE DE CONCORRENCIA (BookingConcurrencyTest): cada processo
 * tenta reservar o mesmo horario pelo BookingService de verdade. Espera um
 * arquivo de "largada" para todos comecarem juntos e segura a transacao
 * aberta (--hold) entre a verificacao e a gravacao, alargando a janela de
 * corrida. So roda em local/testing.
 */
class BookingProbe extends Command
{
    protected $signature = 'app:booking-probe
        {service} {professional} {customer} {start : instante UTC (ISO 8601)}
        {--barrier= : arquivo que libera a largada}
        {--hold=300 : milissegundos segurando a transacao depois da verificacao}';

    protected $description = 'Tenta uma reserva (so para o teste de concorrencia)';

    public function handle(BookingService $booking): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Somente em local/testing.');

            return self::FAILURE;
        }

        $barreira = (string) $this->option('barrier');
        $limite = microtime(true) + 20;
        while ($barreira !== '' && ! file_exists($barreira) && microtime(true) < $limite) {
            usleep(5000);
        }

        $hold = (int) $this->option('hold');
        BookingService::$afterCheck = fn () => usleep($hold * 1000);

        try {
            $a = $booking->book(new BookingRequest(
                service: Service::query()->findOrFail((int) $this->argument('service')),
                professional: Professional::query()->findOrFail((int) $this->argument('professional')),
                start: CarbonImmutable::parse((string) $this->argument('start'))->utc(),
                channel: Channel::Staff,
                source: AppointmentSource::Staff,
                customer: Customer::query()->findOrFail((int) $this->argument('customer')),
            ));
            $this->line('OK '.$a->id);
        } catch (SlotUnavailable $e) {
            $this->line('CONFLICT '.implode(',', $e->result->reasons));
        } catch (BookingRuleViolation $e) {
            $this->line('RULE '.$e->reason);
        } catch (Throwable $e) {
            $this->line('ERROR '.get_class($e).': '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
