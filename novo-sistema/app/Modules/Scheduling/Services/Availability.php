<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Catalog\Models\Service;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BusinessHour;
use App\Modules\Scheduling\Support\BookingPolicy;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\Scheduling\Support\Interval;
use App\Modules\Shared\Support\Duration;
use App\Modules\Team\Models\BlockedSlot;
use App\Modules\Team\Models\Professional;
use App\Modules\Team\Services\ProfessionalDirectory;
use Carbon\CarbonImmutable;

/**
 * A UNICA regra de disponibilidade (disponibilidade.md).
 *
 * check() responde "este horario pode ser reservado?" e slots() lista os
 * horarios livres de um dia. Os dois usam a MESMA funcao evaluate() sobre o
 * mesmo retrato do dia (planFor): o que aparece como livre e exatamente o que
 * a gravacao aceita. A gravacao (BookingService) chama check() de novo
 * dentro da transacao, com a agenda do profissional bloqueada.
 *
 * Um horario [inicio, inicio + duracao) esta livre quando:
 *  1. o servico e agendavel (ativo, categoria ativa);
 *  2. o profissional e agendavel (ativo, recebe agendamentos) e executa o servico;
 *  3. o inicio e multiplo de 5 min, esta no futuro e respeita a antecedencia
 *     do canal (BookingPolicy);
 *  4. o intervalo INTEIRO cabe num intervalo de funcionamento da barbearia
 *     que tambem esteja no expediente do profissional (intersecao);
 *  5. o profissional nao esta de folga no dia;
 *  6. nao encosta em pausa, bloqueio (dele ou da barbearia) nem em outro
 *     agendamento que ocupa horario — sobreposicao meio-aberta (Interval).
 */
final class Availability
{
    /** @var array<int, list<array{0: string, 1: string}>>|null */
    private ?array $businessHours = null;

    public function __construct(
        private readonly ProfessionalDirectory $directory,
    ) {}

    /**
     * Pode reservar este servico com este profissional neste inicio?
     *
     * @param  int|null  $durationMinutes  duracao fotografada (remarcacao); nulo = a do servico
     */
    public function check(Service $service, Professional $professional, CarbonImmutable $start, Channel $channel, ?Appointment $ignore = null, ?int $durationMinutes = null): AvailabilityResult
    {
        $motivos = $this->staticReasons($service, $professional, $channel);
        if ($motivos !== []) {
            return AvailabilityResult::unavailable($motivos);
        }

        $minutos = $durationMinutes ?? $service->duration_minutes;
        $plano = $this->planFor($professional, BusinessTime::dateOf($start), $ignore);

        return $this->evaluate(Interval::starting($start, $minutos), $plano, $channel, BookingPolicy::current());
    }

    /**
     * Horarios livres de um dia, na grade da BookingPolicy. Com profissional:
     * so a agenda dele. Sem (sem preferencia): todos que executam o servico,
     * e cada horario diz QUEM esta livre nele (na ordem da equipe).
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, professionals: list<Professional>}>
     */
    public function slots(Service $service, ?Professional $professional, string $date, Channel $channel, ?Appointment $ignore = null, ?int $durationMinutes = null): array
    {
        if (! BusinessTime::isValidDate($date)) {
            return [];
        }

        $candidatos = $professional !== null
            ? ($this->staticReasons($service, $professional, $channel) === [] ? [$professional] : [])
            : array_values(array_filter($this->directory->bookableFor($service)->all(), fn (Professional $p) => $this->staticReasons($service, $p, $channel) === []));

        $politica = BookingPolicy::current();
        $minutos = $durationMinutes ?? $service->duration_minutes;
        $passo = $politica->int('slot_step_minutes');
        $porInicio = [];

        foreach ($candidatos as $p) {
            $plano = $this->planFor($p, $date, $ignore);

            foreach ($plano['work'] as $janela) {
                for ($inicio = $this->firstGridPoint($janela->start, $date, $passo); $inicio->addMinutes($minutos)->lte($janela->end); $inicio = $inicio->addMinutes($passo)) {
                    $candidato = Interval::starting($inicio, $minutos);
                    if ($this->evaluate($candidato, $plano, $channel, $politica)->isAvailable()) {
                        $chave = $inicio->getTimestamp();
                        $porInicio[$chave] ??= ['start' => $candidato->start, 'end' => $candidato->end, 'professionals' => []];
                        $porInicio[$chave]['professionals'][] = $p;
                    }
                }
            }
        }

        ksort($porInicio);

        return array_values($porInicio);
    }

    /**
     * Dias abertos (a barbearia tem funcionamento) dentro do alcance do canal.
     *
     * @return list<string>
     */
    public function bookableDates(Channel $channel, int $limit = 60): array
    {
        $politica = BookingPolicy::current();
        $alcance = min($limit, $politica->int($channel === Channel::Customer ? 'max_advance_days' : 'staff_max_advance_days'));
        $hoje = CarbonImmutable::createFromFormat('Y-m-d', BusinessTime::today(), BusinessTime::zone());
        $dias = [];

        for ($i = 0; $i <= $alcance; $i++) {
            $d = $hoje->addDays($i)->toDateString();
            if (($this->businessHours()[BusinessTime::weekday($d)] ?? []) !== []) {
                $dias[] = $d;
            }
        }

        return $dias;
    }

    /**
     * Motivos que nao dependem do horario.
     *
     * @return list<string>
     */
    private function staticReasons(Service $service, Professional $professional, Channel $channel): array
    {
        $motivos = [];
        // Fase 11: pelo site (canal do cliente) so o que aparece no site:
        // servico e profissional publicos. A equipe continua agendando tudo.
        if ($channel === Channel::Customer) {
            if (! $service->is_public) {
                $motivos[] = 'service_not_public';
            }
            if (! $professional->is_public) {
                $motivos[] = 'professional_not_public';
            }
        }

        if (! Service::query()->whereKey($service->id)->bookable()->exists()) {
            $motivos[] = 'service_unavailable';
        }
        if (! $professional->isBookable()) {
            $motivos[] = 'professional_unavailable';
        }
        if (! $professional->services()->whereKey($service->id)->exists()) {
            $motivos[] = 'professional_not_qualified';
        }

        return $motivos;
    }

    /**
     * A regra, aplicada a um intervalo candidato sobre o retrato do dia.
     *
     * @param  array{date: string, work: list<Interval>, off: bool, breaks: list<Interval>, blocks: list<Interval>, busy: list<Interval>}  $plan
     */
    private function evaluate(Interval $slot, array $plan, Channel $channel, BookingPolicy $policy): AvailabilityResult
    {
        $agora = BusinessTime::now();

        if ((int) $slot->start->format('i') % Duration::STEP_MINUTES !== 0) {
            return AvailabilityResult::unavailable(['invalid_time']);
        }
        if ($slot->start->lte($agora)) {
            return AvailabilityResult::unavailable(['past']);
        }
        if ($channel === Channel::Customer && $slot->start->lt($agora->addMinutes($policy->int('min_notice_minutes')))) {
            return AvailabilityResult::unavailable(['too_soon']);
        }
        $alcance = $policy->int($channel === Channel::Customer ? 'max_advance_days' : 'staff_max_advance_days');
        if ($slot->start->gt($agora->addDays($alcance))) {
            return AvailabilityResult::unavailable(['too_far']);
        }

        $dentro = false;
        foreach ($plan['work'] as $janela) {
            if ($slot->within($janela)) {
                $dentro = true;
                break;
            }
        }
        if (! $dentro) {
            return AvailabilityResult::unavailable(['outside_hours']);
        }

        if ($plan['off']) {
            return AvailabilityResult::unavailable(['time_off']);
        }

        foreach (['break' => $plan['breaks'], 'blocked' => $plan['blocks'], 'conflict' => $plan['busy']] as $motivo => $ocupados) {
            foreach ($ocupados as $ocupado) {
                if ($slot->overlaps($ocupado)) {
                    return AvailabilityResult::unavailable([$motivo]);
                }
            }
        }

        return AvailabilityResult::available();
    }

    /**
     * Retrato do dia de um profissional: janelas de trabalho (funcionamento
     * da barbearia ∩ expediente dele), folga, pausas, bloqueios e horarios ja
     * ocupados. Tudo em instantes UTC.
     *
     * @return array{date: string, work: list<Interval>, off: bool, breaks: list<Interval>, blocks: list<Interval>, busy: list<Interval>}
     */
    private function planFor(Professional $professional, string $date, ?Appointment $ignore): array
    {
        $dia = BusinessTime::weekday($date);
        $limites = BusinessTime::dayBounds($date);

        $barbearia = array_map(fn ($h) => new Interval(BusinessTime::at($date, $h[0]), BusinessTime::at($date, $h[1])), $this->businessHours()[$dia] ?? []);

        // Sem nenhum expediente cadastrado: segue a barbearia.
        $temExpediente = $professional->workingHours()->exists();
        $proprio = $temExpediente
            ? $professional->workingHours()->where('weekday', $dia)->get()
                ->map(fn ($w) => new Interval(BusinessTime::at($date, $w->starts_at), BusinessTime::at($date, $w->ends_at)))->all()
            : $barbearia;

        $trabalho = [];
        foreach ($barbearia as $b) {
            foreach ($proprio as $p) {
                $inicio = $b->start->max($p->start);
                $fim = $b->end->min($p->end);
                if ($fim->gt($inicio)) {
                    $trabalho[] = new Interval($inicio, $fim);
                }
            }
        }

        $folga = $professional->timeOff()->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->exists();

        $pausas = $professional->breaks()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('weekday')->orWhere('weekday', $dia))->get()
            ->map(fn ($b) => new Interval(BusinessTime::at($date, $b->starts_at), BusinessTime::at($date, $b->ends_at)))->all();

        $bloqueios = BlockedSlot::query()
            ->where(fn ($q) => $q->whereNull('professional_id')->orWhere('professional_id', $professional->id))
            ->where('starts_at', '<', $limites->end)->where('ends_at', '>', $limites->start)
            ->get()->map(fn (BlockedSlot $b) => $b->interval())->all();

        $ocupados = Appointment::query()
            ->where('professional_id', $professional->id)
            ->whereIn('status', array_map(fn (AppointmentStatus $s) => $s->value, AppointmentStatus::blockingSlot()))
            ->where('starts_at', '<', $limites->end)->where('ends_at', '>', $limites->start)
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))
            ->get(['starts_at', 'ends_at'])
            ->map(fn (Appointment $a) => new Interval(CarbonImmutable::instance($a->starts_at), CarbonImmutable::instance($a->ends_at)))->all();

        return ['date' => $date, 'work' => $trabalho, 'off' => $folga, 'breaks' => $pausas, 'blocks' => $bloqueios, 'busy' => $ocupados];
    }

    /** Primeiro ponto da grade (multiplo do passo desde a meia-noite local) >= $from. */
    private function firstGridPoint(CarbonImmutable $from, string $date, int $step): CarbonImmutable
    {
        $meiaNoite = BusinessTime::dayBounds($date)->start;
        $minutos = (int) $meiaNoite->diffInMinutes($from);
        $resto = $minutos % $step;

        return $resto === 0 ? $from : $from->addMinutes($step - $resto);
    }

    /**
     * Funcionamento por dia da semana (carregado uma vez por requisicao).
     *
     * @return array<int, list<array{0: string, 1: string}>>
     */
    private function businessHours(): array
    {
        return $this->businessHours ??= BusinessHour::query()->orderBy('weekday')->orderBy('starts_at')->get()
            ->groupBy('weekday')
            ->map(fn ($g) => $g->map(fn (BusinessHour $h) => [substr($h->starts_at, 0, 5), substr($h->ends_at, 0, 5)])->values()->all())
            ->all();
    }
}
