<?php

namespace App\Modules\LegacyImport\Steps;

use App\Modules\LegacyImport\Enums\IssueClassification as C;
use App\Modules\LegacyImport\Enums\IssueSeverity as S;
use App\Modules\LegacyImport\Source\LegacyDatabase;
use App\Modules\LegacyImport\Support\ImportContext;
use App\Modules\LegacyImport\Support\LegacyValue as V;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Uma etapa do importador (um grupo de tabelas antigas).
 *
 * Toda etapa: le a origem em ordem de insercao; pula o que ja foi
 * importado; valida antes de gravar; registra pendencia para tudo que nao
 * foi importado exatamente como estava; nunca inventa valor.
 */
abstract class Step
{
    protected ImportContext $ctx;

    protected LegacyDatabase $src;

    abstract public function name(): string;

    /** @return list<string> tabelas antigas tratadas pela etapa */
    abstract public function tables(): array;

    abstract protected function handle(): void;

    public function run(ImportContext $ctx): void
    {
        $this->ctx = $ctx;
        $this->src = $ctx->source;
        $this->handle();
    }

    /** created_at/updated_at dos registros criados pela migracao. */
    protected function stamps(?\DateTimeInterface $created = null): array
    {
        return ['created_at' => $created ?? $this->ctx->now, 'updated_at' => $this->ctx->now];
    }

    protected function local(mixed $v): ?CarbonImmutable
    {
        return V::localDateTime($v, $this->ctx->timezone);
    }

    /**
     * Dinheiro obrigatorio/opcional com pendencia automatica.
     */
    protected function money(string $table, ?string $id, string $field, mixed $raw): ?int
    {
        $m = V::money($raw);
        if ($m['cents'] === null && V::text($raw) !== null) {
            $this->ctx->issue($table, $id, C::Inconsistent, S::Warning, 'invalid_money', "Valor monetario ilegivel em {$field}.", [$field => $raw], true);
        } elseif ($m['divergent']) {
            $this->ctx->issue($table, $id, C::Inconsistent, S::Warning, 'money_format_divergent',
                "{$field} gravado em formato brasileiro; o sistema antigo o lia de forma diferente (cast float).", [$field => $raw, 'importado_centavos' => $m['cents']], true);
        } elseif ($m['rounded']) {
            $this->ctx->issue($table, $id, C::PotentiallyValid, S::Info, 'money_rounded', "{$field} tinha mais de 2 casas decimais; arredondado meio para cima.", [$field => $raw, 'importado_centavos' => $m['cents']]);
        }

        return $m['cents'];
    }

    /** Texto com desescape HTML de um nivel, contado por tabela. */
    protected function text(string $table, mixed $raw): ?string
    {
        $t = V::unescapedText($raw, $changed);
        if ($changed) {
            $this->ctx->count($table, 'html_unescaped');
        }

        return $t;
    }

    /**
     * Profissional novo para um barbeiro antigo. Se o barbeiro nao existe
     * mais (registro orfao em agendamentos, comissoes, vales), cria UM
     * profissional inativo "Profissional removido" por id antigo, para nao
     * perder o historico financeiro nem juntar pessoas diferentes.
     */
    protected function professionalFor(?string $legacyBarberId, string $table, ?string $sourceId): ?int
    {
        $legacyBarberId = V::text($legacyBarberId);
        if ($legacyBarberId === null) {
            return null;
        }
        if ($id = $this->ctx->ref('barbeiros', $legacyBarberId)) {
            return $id;
        }
        if ($id = $this->ctx->ref('barbeiros:removido', $legacyBarberId)) {
            return $id;
        }

        $id = $this->ctx->insert('professionals', [
            'display_name' => 'Profissional removido ('.$legacyBarberId.')',
            'is_active' => false,
            'is_bookable' => false,
            'commission_rate_bp' => 0,
            ...$this->stamps(),
        ]);
        $this->ctx->remember('barbeiros:removido', $legacyBarberId, 'professional', $id, ['barbeiro_id' => $legacyBarberId]);
        $this->ctx->issue($table, $sourceId, C::Orphan, S::Warning, 'orphan_professional',
            "Barbeiro {$legacyBarberId} nao existe mais; vinculado a um profissional inativo \"Profissional removido\".", ['barbeiro_id' => $legacyBarberId], true);

        return $id;
    }

    /** Username normalizado e livre; null (com pendencia) se colidir. */
    protected function uniqueUsername(string $table, string $sid, ?string $raw): ?string
    {
        $u = mb_strtolower((string) V::text($raw));
        if ($u === '') {
            return null;
        }
        if (DB::table('users')->where('username', $u)->exists()) {
            $this->ctx->issue($table, $sid, C::Duplicate, S::Warning, 'duplicate_username',
                "Usuario \"{$u}\" ja usado por outro membro da equipe: importado SEM usuario (sem acesso) ate decisao.", ['username' => $u], true);

            return null;
        }

        return $u;
    }

    protected function password(string $table, string $sid, ?string $hash): ?string
    {
        $hash = V::text($hash);
        if ($hash === null) {
            return null;
        }
        if (V::isBcrypt($hash)) {
            return $hash;
        }
        $this->ctx->issue($table, $sid, C::Inconsistent, S::Warning, 'unsupported_password_hash',
            'Senha em formato nao reconhecido (nao e bcrypt): conta importada sem senha, exige redefinicao.', [], true);

        return null;
    }
}
