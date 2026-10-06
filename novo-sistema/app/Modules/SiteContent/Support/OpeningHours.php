<?php

namespace App\Modules\SiteContent\Support;

use App\Modules\Scheduling\Models\BusinessHour;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Team\Models\BlockedSlot;
use Carbon\CarbonImmutable;

/**
 * Horario de funcionamento exibido no site, lido da MESMA configuracao da
 * agenda (business_hours; horarios.md). Nada digitado a parte: se a agenda
 * muda, o site muda. "Aberto agora" considera tambem o bloqueio da
 * barbearia inteira (sem profissional) que cobre o momento atual.
 */
final class OpeningHours
{
    public const DAYS = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];

    /** @var array<int, list<array{0: string, 1: string}>>|null */
    private ?array $week = null;

    /**
     * Faixas por dia da semana (0 = domingo).
     *
     * @return array<int, list<array{0: string, 1: string}>>
     */
    public function week(): array
    {
        if ($this->week === null) {
            $this->week = array_fill(0, 7, []);
            foreach (BusinessHour::query()->orderBy('weekday')->orderBy('starts_at')->get() as $h) {
                $this->week[(int) $h->weekday][] = [substr($h->starts_at, 0, 5), substr($h->ends_at, 0, 5)];
            }
        }

        return $this->week;
    }

    public function isConfigured(): bool
    {
        return array_filter($this->week()) !== [];
    }

    /**
     * Tabela para a tela: segunda a domingo, com o dia de hoje marcado.
     *
     * @return list<array{day: string, hours: string, today: bool, weekday: int}>
     */
    public function table(): array
    {
        $hoje = (int) BusinessTime::local(BusinessTime::now())->format('w');
        $linhas = [];
        foreach ([1, 2, 3, 4, 5, 6, 0] as $d) {
            $faixas = $this->week()[$d];
            $linhas[] = [
                'day' => self::DAYS[$d],
                'hours' => $faixas === [] ? 'Fechado' : implode(' e ', array_map(fn ($f) => self::label($f[0]).' às '.self::label($f[1]), $faixas)),
                'today' => $d === $hoje,
                'weekday' => $d,
            ];
        }

        return $linhas;
    }

    /**
     * "Aberto agora até 20h" / "Abre hoje às 9h" / "Abre segunda às 9h".
     *
     * @return array{open: bool, text: string}|null nulo sem horario configurado
     */
    public function status(?CarbonImmutable $at = null): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }
        $agora = BusinessTime::local($at ?? BusinessTime::now());
        $hhmm = $agora->format('H:i');
        $dia = (int) $agora->format('w');

        foreach ($this->week()[$dia] as [$ini, $fim]) {
            if ($hhmm >= $ini && $hhmm < $fim && ! $this->shopBlocked($at ?? BusinessTime::now())) {
                return ['open' => true, 'text' => 'Aberto agora até '.self::label($fim)];
            }
        }
        foreach ($this->week()[$dia] as [$ini]) {
            if ($hhmm < $ini) {
                return ['open' => false, 'text' => 'Abre hoje às '.self::label($ini)];
            }
        }
        for ($i = 1; $i <= 7; $i++) {
            $d = ($dia + $i) % 7;
            if ($this->week()[$d] !== []) {
                return ['open' => false, 'text' => 'Abre '.($i === 1 ? 'amanhã' : mb_strtolower(self::DAYS[$d])).' às '.self::label($this->week()[$d][0][0])];
            }
        }

        return null;
    }

    /**
     * Para os dados estruturados (schema.org OpeningHoursSpecification).
     *
     * @return list<array{days: list<string>, opens: string, closes: string}>
     */
    public function specification(): array
    {
        $nomes = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $porFaixa = [];
        foreach ($this->week() as $d => $faixas) {
            foreach ($faixas as [$ini, $fim]) {
                $porFaixa[$ini.'-'.$fim]['days'][] = $nomes[$d];
                $porFaixa[$ini.'-'.$fim]['opens'] = $ini;
                $porFaixa[$ini.'-'.$fim]['closes'] = $fim;
            }
        }

        return array_values($porFaixa);
    }

    /** "09:00" -> "9h"; "09:30" -> "9h30". */
    public static function label(string $hhmm): string
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return $h.'h'.($m > 0 ? str_pad((string) $m, 2, '0', STR_PAD_LEFT) : '');
    }

    private function shopBlocked(CarbonImmutable $at): bool
    {
        return BlockedSlot::query()->whereNull('professional_id')->where('starts_at', '<=', $at)->where('ends_at', '>', $at)->exists();
    }
}
