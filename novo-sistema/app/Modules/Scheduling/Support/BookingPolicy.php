<?php

namespace App\Modules\Scheduling\Support;

use App\Modules\Shared\Support\Duration;
use App\Modules\System\Models\Setting;
use App\Modules\System\Services\AuditTrail;
use InvalidArgumentException;

/**
 * Regras configuraveis da agenda (R-01, D-06, D-13), num lugar so. Gravadas
 * na tabela settings (chave agenda.policy) com tipos e limites validados;
 * nada de numero magico em controller. Padroes = comportamento do sistema
 * antigo (antecedencia 120 min, ate 30 dias) e a recomendacao de D-13.
 *
 * Canal CLIENTE (site, conta): valem antecedencia minima/maxima, prazos de
 * cancelamento/remarcacao e limite de remarcacoes. Canal EQUIPE: so nao pode
 * no passado e ate staff_max_advance_days (a recepcao encaixa quem chega).
 */
final class BookingPolicy
{
    public const KEY = 'agenda.policy';

    /** @var array<string, array{default: int|bool, min?: int, max?: int, label: string}> */
    public const FIELDS = [
        'min_notice_minutes' => ['default' => 120, 'min' => 0, 'max' => 10080, 'label' => 'Antecedência mínima para o cliente agendar (minutos)'],
        'max_advance_days' => ['default' => 30, 'min' => 1, 'max' => 365, 'label' => 'Até quantos dias à frente o cliente pode agendar'],
        'staff_max_advance_days' => ['default' => 365, 'min' => 1, 'max' => 730, 'label' => 'Até quantos dias à frente a equipe pode agendar'],
        'slot_step_minutes' => ['default' => 15, 'min' => 5, 'max' => 60, 'label' => 'Intervalo entre os horários oferecidos (minutos)'],
        'customer_cancel_notice_minutes' => ['default' => 120, 'min' => 0, 'max' => 10080, 'label' => 'Cliente cancela até (minutos antes)'],
        'customer_reschedule_notice_minutes' => ['default' => 120, 'min' => 0, 'max' => 10080, 'label' => 'Cliente remarca até (minutos antes)'],
        'customer_max_reschedules' => ['default' => 2, 'min' => 0, 'max' => 10, 'label' => 'Quantas vezes o cliente pode remarcar o mesmo agendamento'],
        'requires_confirmation' => ['default' => false, 'label' => 'Agendamento do site precisa ser confirmado pela equipe'],
    ];

    /**
     * @param  array<string, int|bool>  $values
     */
    private function __construct(private readonly array $values) {}

    public static function current(): self
    {
        $salvo = Setting::valueOf(self::KEY, []);

        return new self(self::normalize(is_array($salvo) ? $salvo : []));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function save(array $values): self
    {
        $antes = self::current()->toArray();
        $novo = self::normalize($values, strict: true);

        Setting::query()->updateOrCreate(['key' => self::KEY], ['value' => $novo]);
        AuditTrail::record('agenda.policy_changed', null, null, 'Regras da agenda alteradas.', array_map(
            fn ($v) => is_bool($v) ? ($v ? 'sim' : 'nao') : $v,
            array_diff_assoc($novo, $antes),
        ));

        return new self($novo);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, int|bool>
     */
    private static function normalize(array $values, bool $strict = false): array
    {
        $saida = [];
        foreach (self::FIELDS as $campo => $def) {
            $v = $values[$campo] ?? $def['default'];

            if (is_bool($def['default'])) {
                $saida[$campo] = (bool) $v;

                continue;
            }

            $v = (int) $v;
            if ($v < $def['min'] || $v > $def['max']) {
                if ($strict) {
                    throw new InvalidArgumentException("{$campo} fora do limite.");
                }
                $v = $def['default'];
            }
            $saida[$campo] = $v;
        }

        if ($saida['slot_step_minutes'] % Duration::STEP_MINUTES !== 0) {
            if ($strict) {
                throw new InvalidArgumentException('slot_step_minutes deve ser multiplo de '.Duration::STEP_MINUTES.'.');
            }
            $saida['slot_step_minutes'] = self::FIELDS['slot_step_minutes']['default'];
        }

        return $saida;
    }

    /**
     * @return array<string, int|bool>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    public function int(string $field): int
    {
        return (int) $this->values[$field];
    }

    public function requiresConfirmation(): bool
    {
        return (bool) $this->values['requires_confirmation'];
    }
}
