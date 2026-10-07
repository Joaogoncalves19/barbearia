<?php

namespace App\Modules\LegacyImport\Steps;

use App\Modules\Customers\Support\Cpf;
use App\Modules\Customers\Support\Email;
use App\Modules\Customers\Support\Phone;
use App\Modules\LegacyImport\Enums\IssueClassification as C;
use App\Modules\LegacyImport\Enums\IssueSeverity as S;
use App\Modules\LegacyImport\Support\LegacyValue as V;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * clientes e tudo que pende do cliente: indicacao, anotacoes, favoritos,
 * notificacoes e opt-out de e-mail.
 *
 * Duplicidade (estrategia-duplicidades.md): NUNCA mescla. O primeiro
 * cliente (ordem de cadastro) fica com o e-mail/telefone/CPF; nos seguintes
 * o campo conflitante fica vazio e o par vira customer_merge_candidates,
 * com o valor original guardado, para decisao humana.
 *
 * LGPD: consentimento de marketing comeca "unknown". So o opt-out existe no
 * sistema antigo, e ele e preservado no cliente E na lista de supressao.
 */
final class CustomersStep extends Step
{
    public function name(): string
    {
        return 'Clientes';
    }

    public function tables(): array
    {
        return ['clientes', 'anotacoes_clientes', 'barbeiros_favoritos', 'notificacoes', 'email_optout'];
    }

    protected function handle(): void
    {
        $this->customers();
        $this->referrals();
        $this->notes();
        $this->favorites();
        $this->notifications();
        $this->optOuts();
    }

    private function customers(): void
    {
        foreach ($this->src->rows('clientes') as $row) {
            $sid = (string) $row['id'];
            if ($this->ctx->status('clientes', $sid, $row) !== 'new') {
                continue;
            }

            $nome = $this->text('clientes', $row['nome'] ?? null);
            if ($nome === null) {
                $this->ctx->issue('clientes', $sid, C::PotentiallyValid, S::Warning, 'customer_without_name', 'Cliente sem nome: importado como "(sem nome)".', [], true);
            }

            $campos = [
                'email' => [Email::normalize($row['email'] ?? null), $row['email'] ?? null],
                'phone' => [Phone::normalize($row['telefone'] ?? null), $row['telefone'] ?? null],
                'cpf' => [Cpf::normalize($row['cpf'] ?? null), $row['cpf'] ?? null],
            ];
            $conflitos = [];
            foreach ($campos as $campo => [$normal, $bruto]) {
                if ($normal === null && V::text($bruto) !== null) {
                    $this->ctx->issue('clientes', $sid, C::Inconsistent, S::Warning, "invalid_{$campo}",
                        "Valor invalido em {$campo}: importado vazio.", [$campo => $campo === 'cpf' ? '(omitido)' : $bruto], true);
                }
                if ($normal !== null && ($dono = DB::table('customers')->where($campo, $normal)->value('id'))) {
                    $conflitos[$campo] = [(int) $dono, $normal];
                    $campos[$campo][0] = null;
                }
            }

            $codigo = V::text($row['codigo_indicacao'] ?? null);
            if ($codigo !== null && DB::table('customers')->where('referral_code', $codigo)->exists()) {
                $this->ctx->issue('clientes', $sid, C::Duplicate, S::Warning, 'duplicate_referral_code',
                    'Codigo de indicacao repetido: importado vazio neste cliente.', ['codigo' => $codigo], true);
                $codigo = null;
            }

            $nascimento = V::date($row['data_nascimento'] ?? null);
            if ($nascimento === null && V::text($row['data_nascimento'] ?? null) !== null) {
                $this->ctx->issue('clientes', $sid, C::Inconsistent, S::Info, 'invalid_birth_date', 'Data de nascimento invalida: importada vazia.', ['data_nascimento' => $row['data_nascimento']]);
            }

            $statusRaw = V::text($row['status'] ?? null);
            $status = ($statusRaw === null || $statusRaw === 'ativo') ? 'active' : 'inactive';
            if ($statusRaw !== null && ! in_array($statusRaw, ['ativo', 'inativo'], true)) {
                $this->ctx->issue('clientes', $sid, C::Unknown, S::Warning, 'unknown_status', "Status \"{$statusRaw}\" desconhecido: importado como inativo.", [], true);
            }

            $id = $this->ctx->insert('customers', [
                'public_id' => (string) Str::ulid(),
                'name' => $nome ?? '(sem nome)',
                'email' => $campos['email'][0],
                'phone' => $campos['phone'][0],
                'cpf' => $campos['cpf'][0],
                'password' => $this->password('clientes', $sid, $row['password_hash'] ?? null),
                'birth_date' => $nascimento,
                'photo_path' => V::photoPath($row['foto_perfil'] ?? null),
                'status' => $status,
                'referral_code' => $codigo,
                'marketing_email_consent' => 'unknown',
                ...$this->stamps(),
            ]);
            $this->ctx->remember('clientes', $sid, 'customer', $id, $row);

            foreach ($conflitos as $campo => [$dono, $valor]) {
                DB::table('customer_merge_candidates')->insertOrIgnore([
                    'customer_id' => $dono,
                    'duplicate_customer_id' => $id,
                    'match_field' => $campo,
                    'match_value' => $valor,
                    'status' => 'pending',
                    'import_run_id' => $this->ctx->runId,
                    'notes' => "Importado do sistema antigo: {$campo} igual ao de outro cliente. Valor mantido so no primeiro cadastro.",
                    ...$this->stamps(),
                ]);
                $this->ctx->count('clientes', 'duplicate_'.$campo);
                $this->ctx->issue('clientes', $sid, C::Duplicate, S::Warning, 'duplicate_customer',
                    "Mesmo {$campo} de outro cliente: NAO mesclado; registrado para revisao.", ['campo' => $campo, 'cliente_existente_id' => $dono], true);
            }

            // Anotacao do barbeiro guardada no proprio cliente.
            if ($nota = $this->text('clientes', $row['notas_barbeiro'] ?? null)) {
                $this->ctx->insert('customer_notes', [
                    'customer_id' => $id, 'author_label' => 'Barbeiros (sistema antigo)', 'visibility' => 'professionals', 'body' => $nota, ...$this->stamps(),
                ]);
            }
        }
    }

    /** Segunda passada: indicado_por_id depende de todos os clientes ja importados. */
    private function referrals(): void
    {
        foreach ($this->src->rows('clientes') as $row) {
            $sid = (string) $row['id'];
            $indicador = V::text($row['indicado_por_id'] ?? null);
            $id = $this->ctx->ref('clientes', $sid);
            if ($indicador === null || $id === null || DB::table('customers')->where('id', $id)->whereNotNull('referred_by_customer_id')->exists()) {
                continue;
            }
            $ref = $this->ctx->ref('clientes', $indicador);
            if ($ref === null || $ref === $id) {
                $this->ctx->issue('clientes', $sid, C::Orphan, S::Info, 'orphan_referrer', 'Cliente indicado por cadastro inexistente (ou por si mesmo): vinculo ignorado.', ['indicado_por_id' => $indicador]);

                continue;
            }
            DB::table('customers')->where('id', $id)->update(['referred_by_customer_id' => $ref]);
        }
    }

    private function notes(): void
    {
        foreach ($this->src->rows('anotacoes_clientes') as $row) {
            $sid = (string) $row['id'];
            if ($this->ctx->status('anotacoes_clientes', $sid, $row) !== 'new') {
                continue;
            }
            $cliente = $this->ctx->ref('clientes', $sid);
            $texto = $this->text('anotacoes_clientes', $row['anotacao'] ?? null);
            if ($cliente === null || $texto === null) {
                $this->ctx->skip('anotacoes_clientes', $sid, $cliente === null ? C::Orphan : C::Inconsistent, 'invalid_note', 'Anotacao vazia ou de cliente inexistente.', ['tamanho' => strlen((string) ($row['anotacao'] ?? ''))], false);

                continue;
            }
            $id = $this->ctx->insert('customer_notes', [
                'customer_id' => $cliente, 'author_label' => 'Administracao (sistema antigo)', 'visibility' => 'team', 'body' => $texto, ...$this->stamps(),
            ]);
            $this->ctx->remember('anotacoes_clientes', $sid, 'customer_note', $id, $row);
        }
    }

    private function favorites(): void
    {
        foreach ($this->src->rows('barbeiros_favoritos') as $row) {
            $sid = $row['cliente_id'].'|'.$row['barbeiro_id'];
            if ($this->ctx->status('barbeiros_favoritos', $sid, $row) !== 'new') {
                continue;
            }
            $cliente = $this->ctx->ref('clientes', $row['cliente_id'] ?? null);
            $prof = $this->ctx->ref('barbeiros', $row['barbeiro_id'] ?? null);
            if ($cliente === null || $prof === null) {
                $this->ctx->skip('barbeiros_favoritos', $sid, C::Orphan, 'orphan_favorite', 'Favorito com cliente ou barbeiro inexistente.', $row, false);

                continue;
            }
            DB::table('customer_favorite_professionals')->insert([
                'customer_id' => $cliente, 'professional_id' => $prof, 'created_at' => $this->local($row['created_at'] ?? null),
            ]);
            $this->ctx->remember('barbeiros_favoritos', $sid, 'customer_favorite', $cliente, $row);
        }
    }

    private function notifications(): void
    {
        foreach ($this->src->rows('notificacoes') as $row) {
            $sid = (string) $row['id'];
            if ($this->ctx->status('notificacoes', $sid, $row) !== 'new') {
                continue;
            }
            $cliente = $this->ctx->ref('clientes', $row['cliente_id'] ?? null);
            if ($cliente === null) {
                $this->ctx->skip('notificacoes', $sid, C::Orphan, 'orphan_notification', 'Notificacao de cliente inexistente.', [], false);

                continue;
            }
            // Duas colunas para "lida" e duas para data no sistema antigo (B-17).
            $criada = $this->local($row['data_criacao'] ?? null) ?? $this->local($row['timestamp'] ?? null);
            $lida = V::bool($row['lida'] ?? null) || in_array(V::text($row['status'] ?? null), ['lida', 'lido'], true);
            $id = $this->ctx->insert('customer_notifications', [
                'customer_id' => $cliente,
                'message' => $this->text('notificacoes', $row['mensagem'] ?? null) ?? '',
                'read_at' => $lida ? ($criada ?? $this->ctx->now) : null,
                ...$this->stamps($criada),
            ]);
            $this->ctx->remember('notificacoes', $sid, 'customer_notification', $id, $row);
        }
    }

    private function optOuts(): void
    {
        foreach ($this->src->rows('email_optout') as $row) {
            $sid = (string) $row['email'];
            if ($this->ctx->status('email_optout', $sid, $row) !== 'new') {
                continue;
            }
            // Supressao e conservadora: mesmo e-mail invalido entra na lista.
            $email = Email::normalize($row['email'] ?? null) ?? mb_strtolower(trim((string) $row['email']));
            if ($email === '') {
                $this->ctx->skip('email_optout', $sid, C::Inconsistent, 'empty_optout', 'Opt-out sem e-mail.', [], false);

                continue;
            }
            $quando = $this->local($row['criado_em'] ?? null);
            $cliente = $this->ctx->ref('clientes', $row['cliente_id'] ?? null)
                ?? DB::table('customers')->where('email', $email)->value('id');

            $supressao = DB::table('email_suppressions')->where('email', $email)->value('id');
            if ($supressao === null) {
                $supressao = $this->ctx->insert('email_suppressions', [
                    'email' => $email, 'reason' => 'marketing_opt_out', 'customer_id' => $cliente, 'suppressed_at' => $quando, ...$this->stamps(),
                ]);
            }
            $this->ctx->insert('consent_records', [
                'customer_id' => $cliente, 'email' => $email, 'purpose' => 'marketing_email', 'action' => 'revoked',
                'source' => 'legacy_import', 'occurred_at' => $quando, 'evidence' => 'email_optout do sistema antigo', 'created_at' => $this->ctx->now,
            ]);
            if ($cliente !== null) {
                DB::table('customers')->where('id', $cliente)->update(['marketing_email_consent' => 'revoked', 'marketing_consent_updated_at' => $quando]);
            }
            $this->ctx->remember('email_optout', $sid, 'email_suppression', (int) $supressao, $row);
        }
    }
}
