<?php

namespace App\Modules\LegacyImport\Steps;

use App\Modules\LegacyImport\Enums\IssueClassification as C;
use App\Modules\LegacyImport\Enums\IssueSeverity as S;
use App\Modules\Loyalty\Support\PromotionPolicy;
use App\Modules\System\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * configuracoes (secao -> JSON). Segredos NUNCA entram no banco novo:
 * secoes sensiveis sao descartadas inteiras e, nas demais, qualquer campo
 * com nome de segredo e removido. O relatorio lista o que precisa ser
 * recadastrado no .env (de preferencia com credenciais novas).
 */
final class SettingsStep extends Step
{
    /** Secoes que so contem credenciais/segredos. */
    public const SECRET_SECTIONS = ['config_email', 'config_stripe', 'config_gemini', 'config_chatbot', 'config_cron'];

    public function name(): string
    {
        return 'Configuracoes';
    }

    public function tables(): array
    {
        return ['configuracoes'];
    }

    protected function handle(): void
    {
        foreach ($this->src->rows('configuracoes') as $row) {
            $secao = (string) $row['secao'];
            if (in_array($secao, self::SECRET_SECTIONS, true)) {
                $this->ctx->count('configuracoes', 'secret_section_discarded');
                $this->ctx->issue('configuracoes', $secao, C::Legacy, S::Warning, 'secret_section_not_imported',
                    "Secao sensivel \"{$secao}\" nao importada: recadastrar no .env com credenciais novas (rotacionar).", [], true);

                continue;
            }
            if ($this->ctx->status('configuracoes', $secao, $row) !== 'new') {
                continue;
            }

            $dados = json_decode((string) $row['dados_json'], true);
            if (! is_array($dados)) {
                $this->ctx->skip('configuracoes', $secao, C::Inconsistent, 'invalid_json', 'JSON da configuracao ilegivel.', ['bytes' => strlen((string) $row['dados_json'])]);

                continue;
            }

            $removidos = Setting::secretPaths($dados);
            foreach ($removidos as $caminho) {
                $dados = $this->forget($dados, explode('.', $caminho));
            }
            if ($removidos !== []) {
                $this->ctx->issue('configuracoes', $secao, C::Legacy, S::Warning, 'secret_fields_removed',
                    'Campos com cara de segredo removidos: '.implode(', ', $removidos).'.', ['campos' => $removidos]);
            }

            $id = $this->ctx->insert('settings', [
                'key' => 'legacy.'.$secao,
                'value' => json_encode($dados, JSON_UNESCAPED_UNICODE),
                ...$this->stamps(),
            ]);
            $this->ctx->remember('configuracoes', $secao, 'setting', $id, $row);
        }

        $this->promotionPolicy();
    }

    /**
     * Fase 8: fidelidade, aniversario e indicacao do sistema antigo viram a
     * PromotionPolicy (mesmos padroes do sistema antigo quando faltar campo).
     * So na primeira importacao: depois, quem manda e a tela do sistema novo.
     */
    private function promotionPolicy(): void
    {
        if (DB::table('settings')->where('key', PromotionPolicy::KEY)->exists()) {
            return;
        }
        $ler = function (string $secao): ?array {
            $v = DB::table('settings')->where('key', 'legacy.'.$secao)->value('value');
            $d = is_string($v) ? json_decode($v, true) : null;

            return is_array($d) ? $d : null;
        };
        $fid = $ler('fidelidade_config');
        $ani = $ler('config_aniversario');
        $ind = $ler('config_indicacao');
        if ($fid === null && $ani === null && $ind === null) {
            return;
        }
        $bp = fn ($v) => is_numeric($v) ? (int) round((float) $v * 100) : null;
        $valores = array_filter([
            'loyalty_enabled' => $fid !== null ? (bool) ($fid['ativado'] ?? 1) : null,
            'loyalty_earn_mode' => isset($fid['modo_ganho']) ? ($fid['modo_ganho'] === 'valor' ? 'value' : 'visit') : null,
            'loyalty_points_per_visit' => isset($fid['pontos_por_visita']) ? (int) $fid['pontos_por_visita'] : null,
            'loyalty_cents_per_point' => $bp($fid['real_por_ponto'] ?? null) ?: null,
            'loyalty_points_required' => isset($fid['pontos_necessarios']) ? (int) $fid['pontos_necessarios'] : null,
            'loyalty_reward_type' => isset($fid['tipo_recompensa']) ? (['valor_fixo' => 'fixed', 'servico_gratis' => 'free_service'][$fid['tipo_recompensa']] ?? 'percent') : null,
            'loyalty_reward_base' => isset($fid['base_desconto']) ? (['mais_caro' => 'most_expensive', 'total' => 'total'][$fid['base_desconto']] ?? 'cheapest') : null,
            'loyalty_reward_percent_bp' => $bp($fid['desconto_percentual'] ?? null),
            'loyalty_reward_fixed_cents' => $bp($fid['valor_desconto_fixo'] ?? null) ?: null,
            'birthday_enabled' => $ani !== null ? (bool) ($ani['ativado'] ?? 0) : null,
            'birthday_percent_bp' => $bp($ani['desconto_percentual'] ?? null),
            'referral_enabled' => $ind !== null ? (bool) ($ind['ativado'] ?? 0) : null,
            'referral_percent_bp' => $bp($ind['desconto_novo_cliente'] ?? null),
            'referral_bonus_points' => isset($ind['pontos_indicacao']) ? (int) $ind['pontos_indicacao'] : null,
        ], fn ($v) => $v !== null);

        $normal = PromotionPolicy::normalize($valores);
        foreach ($valores as $campo => $v) {
            if ($normal[$campo] !== $v) {
                $this->ctx->issue('configuracoes', 'fidelidade', C::Inconsistent, S::Warning, 'promotion_value_out_of_range',
                    "Valor de {$campo} fora do limite no sistema antigo: usado o padrão.", ['valor' => $v], true);
            }
        }
        $this->ctx->insert('settings', ['key' => PromotionPolicy::KEY, 'value' => json_encode($normal), ...$this->stamps()]);
        $this->ctx->count('configuracoes', 'promotion_policy_converted');
    }

    /**
     * @param  array<array-key, mixed>  $dados
     * @param  list<string>  $caminho
     * @return array<array-key, mixed>
     */
    private function forget(array $dados, array $caminho): array
    {
        $chave = array_shift($caminho);
        if ($caminho === []) {
            unset($dados[$chave]);
        } elseif (isset($dados[$chave]) && is_array($dados[$chave])) {
            $dados[$chave] = $this->forget($dados[$chave], $caminho);
        }

        return $dados;
    }
}
