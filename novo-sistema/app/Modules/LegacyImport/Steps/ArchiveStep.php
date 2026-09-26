<?php

namespace App\Modules\LegacyImport\Steps;

use App\Modules\LegacyImport\Enums\IssueClassification as C;
use App\Modules\LegacyImport\Enums\IssueSeverity as S;
use Illuminate\Support\Facades\Storage;

/**
 * O que NAO vira dado no modelo novo. Nada e apagado do banco antigo.
 *
 * - Abandonadas (sem tela no sistema atual) e destinatarios de campanha:
 *   exportadas para o arquivo morto (JSON, disco privado), migraveis depois
 *   se a funcionalidade for aprovada (D-11, D-20).
 * - Efemeras (tokens, redefinicao de senha, limites de login): so contadas.
 *   Nao sao exportadas porque contem hashes de token e IPs.
 * - Tecnicas (controle de migracao do sistema antigo): so contadas.
 * - Tabela desconhecida: contada e reportada para decisao.
 */
final class ArchiveStep extends Step
{
    public const ARCHIVED = ['config', 'admin_crm_clientes', 'admin_metas_equipe', 'admin_retencao', 'admin_conciliacao', 'admin_lista_espera', 'campanha_destinatarios'];

    public const EPHEMERAL = ['clientes_tokens', 'password_resets', 'login_throttle', 'sys_login_attempts'];

    public const TECHNICAL = ['schema_migracoes', 'schema_migracoes_dados', 'migrations'];

    /** @param list<string> $mapped tabelas tratadas pelas outras etapas */
    public function __construct(private readonly array $mapped = []) {}

    public function name(): string
    {
        return 'Arquivo morto e tabelas nao migradas';
    }

    public function tables(): array
    {
        return [...self::ARCHIVED, ...self::EPHEMERAL, ...self::TECHNICAL];
    }

    protected function handle(): void
    {
        foreach (self::ARCHIVED as $tabela) {
            $n = $this->src->count($tabela);
            $this->ctx->count($tabela, 'read', $n);
            if ($n === 0) {
                continue;
            }
            if (! $this->ctx->dryRun) {
                $caminho = 'legacy-import/archive/run-'.$this->ctx->runId.'/'.$tabela.'.json';
                Storage::disk('local')->put($caminho, json_encode($this->src->all($tabela), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
            $this->ctx->count($tabela, 'archived', $n);
            $this->ctx->issue($tabela, null, C::Legacy, S::Info, 'archived_not_migrated',
                "{$n} registro(s) exportado(s) para o arquivo morto (JSON). Migrar so se a funcionalidade for aprovada.", [], $tabela !== 'config');
        }

        foreach ([...self::EPHEMERAL, ...self::TECHNICAL] as $tabela) {
            if ($n = $this->src->count($tabela)) {
                $this->ctx->count($tabela, 'read', $n);
                $this->ctx->count($tabela, 'ignored', $n);
            }
        }

        $conhecidas = [...$this->mapped, ...$this->tables()];
        foreach (array_diff($this->src->tables(), $conhecidas) as $tabela) {
            $n = $this->src->count($tabela);
            $this->ctx->count($tabela, 'read', $n);
            $this->ctx->count($tabela, 'ignored', $n);
            $this->ctx->issue($tabela, null, C::Unknown, S::Warning, 'unknown_table', "Tabela nao mapeada ({$n} registro(s)): nao importada.", [], true);
        }
    }
}
