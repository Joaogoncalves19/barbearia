<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Service;
use App\Modules\Checkout\Exceptions\CheckoutRuleViolation;
use App\Modules\Checkout\Services\AttendanceService;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Exceptions\PromotionRejected;
use App\Modules\Loyalty\Pricing\PromotionRequest;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Exceptions\BookingRuleViolation;
use App\Modules\Scheduling\Exceptions\SlotUnavailable;
use App\Modules\Scheduling\Services\BookingRequest;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\Team\Models\Professional;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sonda do TESTE DE CONCORRENCIA (BookingConcurrencyTest): cada processo
 * tenta reservar o mesmo horario pelo BookingService de verdade, como equipe,
 * como cliente pelo site (--as=online) ou como encaixe da recepcao
 * (--as=walk-in, pelo AttendanceService, que reserva pelo mesmo
 * BookingService; o inicio e o proximo ponto da grade). Espera um
 * arquivo de "largada" para todos comecarem juntos e segura a transacao
 * aberta (--hold) entre a verificacao e a gravacao, alargando a janela de
 * corrida. Fase 8: --coupon e --points pedem a promocao na reserva (o
 * cupom de uso limitado e os pontos disputados entre processos). So roda em
 * local/testing.
 */
class BookingProbe extends Command
{
    protected $signature = 'app:booking-probe
        {service} {professional} {customer} {start : instante UTC (ISO 8601)}
        {--barrier= : arquivo que libera a largada}
        {--hold=300 : milissegundos segurando a transacao depois da verificacao}
        {--as=staff : staff | online | walk-in}
        {--actor= : usuario da equipe (encaixe)}
        {--now= : relogio parado neste instante (ISO 8601)}
        {--coupon= : codigo do cupom pedido}
        {--points : pede o resgate de pontos}';

    protected $description = 'Tenta uma reserva (so para o teste de concorrencia)';

    public function handle(BookingService $booking, AttendanceService $attendances): int
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

        if ((string) $this->option('now') !== '') {
            Carbon::setTestNow(CarbonImmutable::parse((string) $this->option('now'))->utc());
        }

        $hold = (int) $this->option('hold');
        BookingService::$afterCheck = fn () => usleep($hold * 1000);

        $servico = Service::query()->findOrFail((int) $this->argument('service'));
        $pro = Professional::query()->findOrFail((int) $this->argument('professional'));
        $cliente = Customer::query()->findOrFail((int) $this->argument('customer'));

        try {
            if ($this->option('as') === 'walk-in') {
                $at = $attendances->openWalkIn($servico, $pro, $cliente, null, null, User::query()->findOrFail((int) $this->option('actor')));
                $this->line('OK '.$at->appointment_id.' walk-in');

                return self::SUCCESS;
            }

            $online = $this->option('as') === 'online';
            $a = $booking->book(new BookingRequest(
                service: $servico,
                professional: $pro,
                start: CarbonImmutable::parse((string) $this->argument('start'))->utc(),
                channel: $online ? Channel::Customer : Channel::Staff,
                source: $online ? AppointmentSource::Online : AppointmentSource::Staff,
                customer: $cliente,
                promotion: (string) $this->option('coupon') !== '' || $this->option('points') ? new PromotionRequest((string) $this->option('coupon') ?: null, (bool) $this->option('points')) : null,
            ));
            $this->line('OK '.$a->id.' '.$a->source->value);
        } catch (SlotUnavailable $e) {
            $this->line('CONFLICT '.implode(',', $e->result->reasons));
        } catch (BookingRuleViolation|CheckoutRuleViolation $e) {
            $this->line('RULE '.$e->reason);
        } catch (PromotionRejected $e) {
            $this->line('PROMO '.$e->reason.' '.$e->getMessage());
        } catch (Throwable $e) {
            $this->line('ERROR '.get_class($e).': '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
