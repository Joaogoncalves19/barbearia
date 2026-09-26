<?php

namespace App\Modules\LegacyImport\Steps;

use App\Modules\LegacyImport\Enums\IssueClassification as C;
use App\Modules\LegacyImport\Enums\IssueSeverity as S;
use App\Modules\System\Models\Setting;

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
