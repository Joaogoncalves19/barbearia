<?php

namespace App\Modules\LegacyImport\Steps;

use App\Modules\Identity\Enums\StaffRole;
use App\Modules\LegacyImport\Enums\IssueClassification as C;
use App\Modules\LegacyImport\Enums\IssueSeverity as S;
use App\Modules\LegacyImport\Support\LegacyValue as V;
use App\Modules\Team\Enums\TimeOffKind;
use Illuminate\Support\Facades\DB;

/**
 * barbeiros -> professionals (+ users quando ha login), servicos que
 * realiza, expediente, almoco, ausencias, bloqueios e meta diaria.
 *
 * Regras herdadas do sistema atual:
 * - status vazio = ativo.
 * - expediente: o sistema atual le a PRIMEIRA linha do dia e ignora a
 *   coluna "ativo"; linhas repetidas do mesmo dia sao pendencia.
 * - almoco: 1 hora a partir do horario configurado, todos os dias.
 * - bloqueio: 1 slot de 30 minutos.
 */
final class ProfessionalsStep extends Step
{
    private const TIME_OFF_MAP = [
        'folga' => TimeOffKind::DayOff,
        'ferias' => TimeOffKind::Vacation,
        'férias' => TimeOffKind::Vacation,
        'atestado' => TimeOffKind::Medical,
    ];

    private const COMMISSION_MODE_MAP = ['padrao' => 'default', 'percentual' => 'percent', 'fixo' => 'fixed', 'nenhuma' => 'none'];

    public function name(): string
    {
        return 'Profissionais e agenda';
    }

    public function tables(): array
    {
        return ['barbeiros', 'horarios_trabalho', 'config_almoco_barbeiro', 'barbeiro_ausencias', 'horarios_bloqueados'];
    }

    protected function handle(): void
    {
        $this->professionals();
        $this->workingHours();
        $this->breaks();
        $this->timeOff();
        $this->blockedSlots();
    }

    private function professionals(): void
    {
        foreach ($this->src->rows('barbeiros') as $row) {
            $sid = (string) $row['id'];
            if ($this->ctx->status('barbeiros', $sid, $row) !== 'new') {
                continue;
            }

            $statusRaw = V::text($row['status'] ?? null);
            $ativo = $statusRaw === null || $statusRaw === 'ativo';
            if ($statusRaw !== null && ! in_array($statusRaw, ['ativo', 'inativo'], true)) {
                $this->ctx->issue('barbeiros', $sid, C::Unknown, S::Warning, 'unknown_status', "Status \"{$statusRaw}\" desconhecido: importado como inativo.", [], true);
            }
            $nome = $this->text('barbeiros', $row['nome'] ?? null) ?? '(sem nome)';

            // Login: so quando ha usuario E senha reconhecida.
            $userId = null;
            $username = V::text($row['username'] ?? null);
            if ($username !== null) {
                $senha = $this->password('barbeiros', $sid, $row['password'] ?? null);
                $login = $this->uniqueUsername('barbeiros', $sid, $username);
                $userId = $this->ctx->insert('users', [
                    'name' => $nome,
                    'username' => $login,
                    'email' => null,
                    'password' => $senha,
                    'role' => StaffRole::Professional->value,
                    'is_active' => $ativo && $login !== null && $senha !== null,
                    ...$this->stamps(),
                ]);
            }

            $comissao = V::percentBp($row['comissao'] ?? null);
            if ($comissao === null && V::text($row['comissao'] ?? null) !== null) {
                $this->ctx->issue('barbeiros', $sid, C::Inconsistent, S::Warning, 'invalid_commission', 'Percentual de comissao invalido: importado como 0%.', ['comissao' => $row['comissao']], true);
            }
            $modoRaw = V::text($row['comissao_assinatura_tipo'] ?? null) ?? 'padrao';
            $modo = self::COMMISSION_MODE_MAP[$modoRaw] ?? 'default'; // o sistema atual trata desconhecido como "padrao"
            $valorModo = $row['comissao_assinatura_valor'] ?? null;

            $id = $this->ctx->insert('professionals', [
                'user_id' => $userId,
                'display_name' => $nome,
                'photo_path' => V::text($row['foto'] ?? null),
                'is_active' => $ativo,
                'is_bookable' => $ativo,
                ...$this->stamps(),
            ]);
            $this->ctx->remember('barbeiros', $sid, 'professional', $id, $row);

            // Comissao (Fase 7): o percentual do barbeiro vira a regra do
            // profissional para servicos; "comissao_produtos" vira a regra de
            // produtos com o MESMO percentual (como o sistema atual calculava).
            if (($comissao ?? 0) > 0) {
                $this->commissionRule('service', $id, $comissao);
                if (V::bool($row['comissao_produtos'] ?? null)) {
                    $this->commissionRule('product', $id, $comissao);
                }
            }
            // Comissao em atendimento de assinante (Fase 9): "padrao" = sem regra
            // propria (vale a do servico sobre o preco de tabela).
            if ($modo === 'percent') {
                $this->commissionRule('subscription', $id, min(10000, V::percentBp($valorModo) ?? 0));
            } elseif ($modo === 'fixed') {
                $this->commissionRule('subscription', $id, null, max(0, $this->money('barbeiros', $sid, 'comissao_assinatura_valor', $valorModo) ?? 0));
            } elseif ($modo === 'none') {
                $this->commissionRule('subscription', $id, null, null, 'none');
            }

            // Servicos e combos que realiza (CSV misturando os dois).
            foreach (array_unique(V::csv($row['servicos_ids'] ?? null)) as $item) {
                if ($servico = $this->ctx->ref('servicos', $item)) {
                    DB::table('professional_service')->insert(['professional_id' => $id, 'service_id' => $servico]);
                } elseif ($combo = $this->ctx->ref('combos', $item)) {
                    DB::table('professional_package')->insert(['professional_id' => $id, 'package_id' => $combo]);
                } else {
                    $this->ctx->issue('barbeiros', $sid, C::Orphan, S::Info, 'unknown_service_link', "Servico/combo {$item} nao existe: vinculo ignorado.", ['item' => $item]);
                }
            }

            // Meta diaria (R$) -> meta do profissional.
            $meta = $this->money('barbeiros', $sid, 'meta_diaria', $row['meta_diaria'] ?? null);
            if ($meta !== null && $meta > 0) {
                $this->ctx->insert('financial_goals', ['professional_id' => $id, 'period' => 'daily', 'amount_cents' => $meta, 'effective_from' => null, ...$this->stamps()]);
            }
        }
    }

    private function workingHours(): void
    {
        $vistos = [];
        foreach ($this->src->rows('horarios_trabalho') as $row) {
            $sid = $this->ctx->contentKey('horarios_trabalho', $row);
            if ($this->ctx->status('horarios_trabalho', $sid, $row) !== 'new') {
                continue;
            }
            $prof = $this->ctx->ref('barbeiros', $row['barbeiro_id'] ?? null);
            $dia = V::int($row['dia'] ?? null);
            $inicio = V::time($row['inicio'] ?? null);
            $fim = V::time($row['fim'] ?? null);

            if ($prof === null) {
                $this->ctx->skip('horarios_trabalho', $sid, C::Orphan, 'orphan_working_hours', 'Expediente de barbeiro inexistente.', $row, false);

                continue;
            }
            if ($dia === null || $dia < 0 || $dia > 6 || $inicio === null || $fim === null || $fim <= $inicio) {
                $this->ctx->skip('horarios_trabalho', $sid, C::Inconsistent, 'invalid_working_hours', 'Expediente com dia ou horario invalido.', $row);

                continue;
            }
            $chave = $prof.'-'.$dia;
            if (isset($vistos[$chave]) || DB::table('working_hours')->where(['professional_id' => $prof, 'weekday' => $dia])->exists()) {
                $this->ctx->skip('horarios_trabalho', $sid, C::Duplicate, 'duplicate_working_hours',
                    'Mais de um expediente para o mesmo dia: mantido o primeiro (o sistema atual so le o primeiro).', $row);

                continue;
            }
            $vistos[$chave] = true;
            if (($row['ativo'] ?? null) === '0') {
                $this->ctx->issue('horarios_trabalho', $sid, C::Inconsistent, S::Warning, 'inactive_flag_ignored_by_legacy',
                    'Linha marcada ativo=0, mas o sistema atual ignora essa coluna e considera o dia como de trabalho. Importada como trabalho.', $row, true);
            }

            $id = $this->ctx->insert('working_hours', ['professional_id' => $prof, 'weekday' => $dia, 'starts_at' => $inicio, 'ends_at' => $fim, ...$this->stamps()]);
            $this->ctx->remember('horarios_trabalho', $sid, 'working_hour', $id, $row);
        }
    }

    private function breaks(): void
    {
        foreach ($this->src->rows('config_almoco_barbeiro') as $row) {
            $sid = (string) $row['barbeiro_id'];
            if ($this->ctx->status('config_almoco_barbeiro', $sid, $row) !== 'new') {
                continue;
            }
            $prof = $this->ctx->ref('barbeiros', $sid);
            $inicio = V::time($row['horario'] ?? null);
            if ($prof === null || $inicio === null) {
                $this->ctx->skip('config_almoco_barbeiro', $sid, $prof === null ? C::Orphan : C::Inconsistent, 'invalid_break', 'Almoco de barbeiro inexistente ou com horario invalido.', $row, false);

                continue;
            }
            $fim = date('H:i:s', strtotime('2000-01-01 '.$inicio) + 3600);
            if ($fim <= $inicio) {
                $this->ctx->skip('config_almoco_barbeiro', $sid, C::Inconsistent, 'break_crosses_midnight', 'Almoco atravessa a meia-noite.', $row);

                continue;
            }
            $id = $this->ctx->insert('schedule_breaks', [
                'professional_id' => $prof, 'weekday' => null, 'starts_at' => $inicio, 'ends_at' => $fim,
                'label' => 'Almoço', 'is_active' => V::text($row['status'] ?? null) === 'ativo', ...$this->stamps(),
            ]);
            $this->ctx->remember('config_almoco_barbeiro', $sid, 'schedule_break', $id, $row);
        }
    }

    private function timeOff(): void
    {
        foreach ($this->src->rows('barbeiro_ausencias') as $row) {
            $sid = (string) $row['id'];
            if ($this->ctx->status('barbeiro_ausencias', $sid, $row) !== 'new') {
                continue;
            }
            $prof = $this->professionalFor($row['barbeiro_id'] ?? null, 'barbeiro_ausencias', $sid);
            $ini = V::date($row['data_inicio'] ?? null);
            $fim = V::date($row['data_fim'] ?? null);
            if ($prof === null || $ini === null || $fim === null || $fim < $ini) {
                $this->ctx->skip('barbeiro_ausencias', $sid, C::Inconsistent, 'invalid_time_off', 'Ausencia sem profissional ou com periodo invalido.', $row);

                continue;
            }
            $tipoRaw = mb_strtolower((string) V::text($row['tipo'] ?? null));
            $tipo = self::TIME_OFF_MAP[$tipoRaw] ?? TimeOffKind::Other;
            $motivo = $this->text('barbeiro_ausencias', $row['motivo'] ?? null);
            if ($tipo === TimeOffKind::Other && $tipoRaw !== '' && $tipoRaw !== 'outro') {
                $motivo = trim('['.$tipoRaw.'] '.$motivo); // preserva o tipo original
            }
            $id = $this->ctx->insert('time_off', [
                'professional_id' => $prof, 'starts_on' => $ini, 'ends_on' => $fim, 'kind' => $tipo->value,
                'reason' => $motivo ?: null, ...$this->stamps($this->local($row['criado_em'] ?? null)),
            ]);
            $this->ctx->remember('barbeiro_ausencias', $sid, 'time_off', $id, $row);
        }
    }

    private function blockedSlots(): void
    {
        foreach ($this->src->rows('horarios_bloqueados') as $row) {
            $sid = $this->ctx->contentKey('horarios_bloqueados', $row);
            if ($this->ctx->status('horarios_bloqueados', $sid, $row) !== 'new') {
                continue;
            }
            $prof = $this->ctx->ref('barbeiros', $row['barbeiro_id'] ?? null);
            $data = V::date($row['data'] ?? null);
            $hora = V::time($row['hora'] ?? null);
            if ($prof === null || $data === null || $hora === null) {
                $this->ctx->skip('horarios_bloqueados', $sid, $prof === null ? C::Orphan : C::Inconsistent, 'invalid_blocked_slot', 'Bloqueio sem barbeiro valido ou com data/hora invalida.', $row, false);

                continue;
            }
            $inicio = $this->local($data.' '.$hora);
            if (DB::table('blocked_slots')->where(['professional_id' => $prof, 'starts_at' => $inicio])->exists()) {
                $this->ctx->skip('horarios_bloqueados', $sid, C::Duplicate, 'duplicate_blocked_slot', 'Bloqueio repetido no mesmo horario: mantido um.', $row, false);

                continue;
            }
            $id = $this->ctx->insert('blocked_slots', ['professional_id' => $prof, 'starts_at' => $inicio, 'ends_at' => $inicio->addMinutes(30), ...$this->stamps()]);
            $this->ctx->remember('horarios_bloqueados', $sid, 'blocked_slot', $id, $row);
        }
    }

    /** Regra de comissao do profissional (a mesma forma de CommissionRules::set). */
    private function commissionRule(string $target, int $professionalId, ?int $rateBp, ?int $amountCents = null, ?string $type = null): void
    {
        $escopo = $target.'|p'.$professionalId.'|s*';
        $tipo = $type ?? ($rateBp !== null ? 'percent' : 'fixed');
        $this->ctx->insert('commission_rules', [
            'target' => $target, 'professional_id' => $professionalId, 'service_id' => null,
            'type' => $tipo, 'rate_bp' => $tipo === 'percent' ? $rateBp : null, 'amount_cents' => $tipo === 'fixed' ? $amountCents : null,
            'scope_key' => $escopo, 'current_scope' => $escopo, 'starts_at' => $this->ctx->now,
            'reason' => 'Importada do sistema antigo', ...$this->stamps(),
        ]);
    }
}
