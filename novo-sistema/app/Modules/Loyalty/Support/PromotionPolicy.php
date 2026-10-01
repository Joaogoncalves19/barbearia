<?php

namespace App\Modules\Loyalty\Support;

use App\Modules\Identity\Models\User;
use App\Modules\System\Models\Setting;
use App\Modules\System\Services\AuditTrail;
use InvalidArgumentException;

/**
 * Regras configuraveis de fidelidade, aniversario e indicacao (R-14, R-15,
 * R-17, R-18), num lugar so, como a BookingPolicy da agenda. Gravadas na
 * tabela settings (chave promotions.policy), com tipos e limites validados.
 * Padroes = os do sistema antigo. Mudar vale para o que acontecer DEPOIS:
 * resgates reservados e pontos ja lancados guardam a regra do momento.
 * Toda mudanca vai para a auditoria (promotions.policy_changed).
 */
final class PromotionPolicy
{
    public const KEY = 'promotions.policy';

    /** @var array<string, array{default: int|bool|string, min?: int, max?: int, options?: list<string>, label: string}> */
    public const FIELDS = [
        'loyalty_enabled' => ['default' => true, 'label' => 'Programa de fidelidade ativo'],
        'loyalty_earn_mode' => ['default' => 'visit', 'options' => ['visit', 'value'], 'label' => 'Como o cliente ganha pontos'],
        'loyalty_points_per_visit' => ['default' => 1, 'min' => 1, 'max' => 1000, 'label' => 'Pontos por atendimento concluído'],
        'loyalty_cents_per_point' => ['default' => 1000, 'min' => 1, 'max' => 1000000, 'label' => 'Valor gasto para ganhar 1 ponto (centavos)'],
        'loyalty_points_required' => ['default' => 10, 'min' => 1, 'max' => 100000, 'label' => 'Pontos para um resgate'],
        'loyalty_reward_type' => ['default' => 'percent', 'options' => ['percent', 'fixed', 'free_service'], 'label' => 'Recompensa do resgate'],
        'loyalty_reward_base' => ['default' => 'cheapest', 'options' => ['cheapest', 'most_expensive', 'total'], 'label' => 'Onde o percentual incide'],
        'loyalty_reward_percent_bp' => ['default' => 5000, 'min' => 1, 'max' => 10000, 'label' => 'Percentual do resgate'],
        'loyalty_reward_fixed_cents' => ['default' => 1000, 'min' => 1, 'max' => 10000000, 'label' => 'Valor do resgate (centavos)'],
        'birthday_enabled' => ['default' => false, 'label' => 'Desconto de aniversário ativo'],
        'birthday_percent_bp' => ['default' => 1500, 'min' => 1, 'max' => 10000, 'label' => 'Desconto de aniversário'],
        'referral_enabled' => ['default' => false, 'label' => 'Indicação ativa'],
        'referral_percent_bp' => ['default' => 1000, 'min' => 1, 'max' => 10000, 'label' => 'Desconto no primeiro atendimento do indicado'],
        'referral_bonus_points' => ['default' => 1, 'min' => 0, 'max' => 100000, 'label' => 'Pontos para quem indicou'],
    ];

    /**
     * @param  array<string, int|bool|string>  $values
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
        AuditTrail::record('promotions.policy_changed', null, $actor, 'Regras de fidelidade, aniversário e indicação alteradas.', array_map(
            fn ($v) => is_bool($v) ? ($v ? 'sim' : 'nao') : $v,
            array_diff_assoc($novo, $antes),
        ));

        return new self($novo);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, int|bool|string>
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
            if (isset($def['options'])) {
                $v = (string) $v;
                if (! in_array($v, $def['options'], true)) {
                    if ($strict) {
                        throw new InvalidArgumentException("{$campo} invalido.");
                    }
                    $v = (string) $def['default'];
                }
                $saida[$campo] = $v;

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
     * @return array<string, int|bool|string>
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

    public function string(string $field): string
    {
        return (string) $this->values[$field];
    }
}
