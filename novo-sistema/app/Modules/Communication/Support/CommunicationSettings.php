<?php

namespace App\Modules\Communication\Support;

use App\Modules\Identity\Models\User;
use App\Modules\System\Models\Setting;
use App\Modules\System\Services\AuditTrail;
use InvalidArgumentException;

/**
 * Configuracao da comunicacao (lembretes.md, avaliacoes.md, campanhas.md):
 * lembretes (vespera a partir de uma hora; X horas antes; D-51, padroes do
 * sistema antigo), pedido de avaliacao (D-49) e ritmo de envio das
 * campanhas. Sem segredo nenhum (credenciais do e-mail ficam no ambiente).
 */
final class CommunicationSettings
{
    public const KEY = 'communication.settings';

    /** @var array<string, array{default: int|bool, min?: int, max?: int, label: string}> */
    public const FIELDS = [
        'reminder_day_before_enabled' => ['default' => true, 'label' => 'Lembrete na véspera'],
        'reminder_day_before_hour' => ['default' => 9, 'min' => 6, 'max' => 21, 'label' => 'Hora (a partir de) do lembrete da véspera'],
        'reminder_hours_before_enabled' => ['default' => true, 'label' => 'Lembrete algumas horas antes'],
        'reminder_hours_before' => ['default' => 2, 'min' => 1, 'max' => 24, 'label' => 'Quantas horas antes'],
        'review_request_enabled' => ['default' => true, 'label' => 'Pedir avaliação depois do atendimento'],
        'review_request_delay_hours' => ['default' => 3, 'min' => 0, 'max' => 72, 'label' => 'Horas depois da conclusão'],
        'campaign_per_minute' => ['default' => 30, 'min' => 1, 'max' => 500, 'label' => 'E-mails de campanha por minuto (limite do provedor)'],
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
     *
     * @throws InvalidArgumentException
     */
    public static function save(array $values, ?User $actor): self
    {
        $antes = self::current()->toArray();
        $novo = self::normalize($values + $antes, strict: true);
        Setting::query()->updateOrCreate(['key' => self::KEY], ['value' => $novo]);
        AuditTrail::record('communication.settings_changed', null, $actor, 'Configuração de lembretes, avaliações e campanhas alterada.', array_map(
            fn ($v) => is_bool($v) ? ($v ? 'sim' : 'nao') : $v,
            array_diff_assoc($novo, $antes),
        ));

        return new self($novo);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, int|bool>
     */
    public static function normalize(array $values, bool $strict = false): array
    {
        $saida = [];
        foreach (self::FIELDS as $campo => $def) {
            $v = $values[$campo] ?? $def['default'];
            if (is_bool($def['default'])) {
                $saida[$campo] = filter_var($v, FILTER_VALIDATE_BOOLEAN);

                continue;
            }
            $v = is_numeric($v) ? (int) $v : null;
            if ($v === null || $v < $def['min'] || $v > $def['max']) {
                if ($strict) {
                    throw new InvalidArgumentException("{$campo} fora do limite.");
                }
                $v = (int) $def['default'];
            }
            $saida[$campo] = $v;
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

    public function bool(string $field): bool
    {
        return (bool) $this->values[$field];
    }

    public function int(string $field): int
    {
        return (int) $this->values[$field];
    }
}
