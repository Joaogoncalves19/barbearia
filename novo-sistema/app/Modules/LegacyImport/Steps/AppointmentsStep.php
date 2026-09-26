<?php

namespace App\Modules\LegacyImport\Steps;

use App\Modules\LegacyImport\Enums\IssueClassification as C;
use App\Modules\LegacyImport\Enums\IssueSeverity as S;
use App\Modules\LegacyImport\Support\LegacyValue as V;
use Illuminate\Support\Facades\DB;

/**
 * agendamentos -> appointments + appointment_items + appointment_adjustments
 * + payments + appointment_reminders + appointment_events.
 *
 * O banco antigo NAO guarda o preco cobrado. O sistema antigo calcula
 * faturamento com o preco ATUAL do catalogo; o item importado recebe esse
 * mesmo valor, marcado como legacy_catalog_estimate, para que os relatorios
 * batam com os do sistema antigo. Item sem catalogo: valor desconhecido
 * (legacy_unknown), nunca zero inventado.
 */
final class AppointmentsStep extends Step
{
    public const STATUS_MAP = [
        'pendente' => 'pending',
        'aguardando_pagamento' => 'awaiting_payment',
        'aprovado' => 'confirmed',
        'concluido' => 'completed',
        'cancelado' => 'cancelled',
        'cancelado_pelo_cliente' => 'cancelled',
        'rejeitado' => 'cancelled',
    ];

    public const DISCOUNT_MAP = [
        'cupom' => 'coupon',
        'voucher' => 'gift_card',
        'fidelidade' => 'loyalty',
        'aniversario' => 'birthday',
        'indicacao' => 'referral',
        'assinatura_vip' => 'subscription',
        'assinatura' => 'subscription',
        'plano' => 'subscription',
        'adesao_plano' => 'plan_signup',
    ];

    public const METHOD_MAP = ['dinheiro' => 'cash', 'pix' => 'pix', 'debito' => 'debit_card', 'credito' => 'credit_card', 'outro' => 'other'];

    /** PAGAMENTO_PENDENTE_MINUTOS do sistema antigo. */
    private const PAYMENT_WINDOW_MINUTES = 15;

    /** @var array<string, array{price: ?int, name: string, minutes: int}> */
    private array $services = [];

    /** @var array<string, array{price: ?int, name: string, minutes: int}> */
    private array $packages = [];

    /** @var array<string, array<string, ?string>> */
    private array $operations = [];

    /** @var array<string, list<array<string, ?string>>> */
    private array $history = [];

    /** @var array<int, string> */
    private array $professionalNames = [];

    /** @var array<string, string> agendamento antigo => voucher antigo */
    private array $vouchers = [];

    public function name(): string
    {
        return 'Agendamentos';
    }

    public function tables(): array
    {
        return ['agendamentos', 'agenda_historico', 'agenda_operacao'];
    }

    protected function handle(): void
    {
        $this->loadLookups();

        foreach ($this->src->rows('agendamentos') as $row) {
            $sid = (string) $row['id'];
            if ($this->ctx->status('agendamentos', $sid, $row) !== 'new') {
                continue;
            }
            $this->importOne($sid, $row);
        }

        $this->historyWithoutAppointment();
        $this->linkVouchers();
        $this->detectConflicts();
    }

    private function loadLookups(): void
    {
        $slotsPorServico = [];
        foreach ($this->src->rows('servicos') as $s) {
            $slots = max(1, (int) (V::int($s['slots'] ?? null) ?? 1));
            $slotsPorServico[$s['id']] = $slots;
            $this->services[$s['id']] = ['price' => V::money($s['valor'] ?? null)['cents'], 'name' => V::unescapedText($s['nome'] ?? null) ?? 'Servico', 'minutes' => $slots * 30];
        }
        foreach ($this->src->rows('combos') as $c) {
            $slots = 0;
            foreach (V::csv($c['servicos_ids'] ?? null) as $sv) {
                $slots += $slotsPorServico[$sv] ?? 1; // regra atual: servico ausente conta 1 slot
            }
            $this->packages[$c['id']] = ['price' => V::money($c['valor'] ?? null)['cents'], 'name' => V::unescapedText($c['nome'] ?? null) ?? 'Combo', 'minutes' => max(1, $slots) * 30];
        }
        foreach ($this->src->rows('agenda_operacao') as $o) {
            $this->operations[(string) $o['agendamento_id']] = $o;
            $this->ctx->count('agenda_operacao', 'read');
        }
        foreach ($this->src->rows('agenda_historico') as $h) {
            $this->history[(string) $h['agendamento_id']][] = $h;
            $this->ctx->count('agenda_historico', 'read');
        }
        foreach (DB::table('professionals')->get(['id', 'display_name']) as $p) {
            $this->professionalNames[(int) $p->id] = $p->display_name;
        }
        foreach ($this->src->rows('vouchers') as $v) {
            if ($uso = V::text($v['agendamento_id_uso'] ?? null)) {
                $this->vouchers[$uso] = (string) $v['id'];
            }
        }
    }

    /**
     * @param  array<string, ?string>  $row
     */
    private function importOne(string $sid, array $row): void
    {
        $inicio = $this->local(V::date($row['data'] ?? null).' '.V::time($row['hora'] ?? null));
        if (V::date($row['data'] ?? null) === null || V::time($row['hora'] ?? null) === null || $inicio === null) {
            $this->ctx->skip('agendamentos', $sid, C::Inconsistent, 'invalid_appointment_datetime', 'Agendamento com data ou hora invalida: NAO importado.', ['data' => $row['data'] ?? null, 'hora' => $row['hora'] ?? null, 'status' => $row['status'] ?? null]);

            return;
        }
        $statusRaw = (string) V::text($row['status'] ?? null);
        $status = self::STATUS_MAP[$statusRaw] ?? null;
        if ($status === null) {
            $this->ctx->skip('agendamentos', $sid, C::Unknown, 'unknown_appointment_status', "Status \"{$statusRaw}\" desconhecido: NAO importado.", ['status' => $statusRaw]);

            return;
        }

        // ---- Itens (servicos/combos do CSV + produtos do JSON)
        $itens = [];
        foreach (V::csv($row['servicos_ids'] ?? null) as $item) {
            if (isset($this->services[$item])) {
                $itens[] = $this->catalogItem('service', $this->ctx->ref('servicos', $item), $this->services[$item]);
            } elseif (isset($this->packages[$item])) {
                $itens[] = $this->catalogItem('package', $this->ctx->ref('combos', $item), $this->packages[$item]);
            } else {
                $itens[] = ['item_type' => str_starts_with($item, 'cb-') ? 'package' : 'service', // prefixos do sistema antigo: sv- / cb- 'service_id' => null, 'package_id' => null, 'product_id' => null,
                    'name' => "Item removido do catalogo ({$item})", 'unit_price_cents' => null, 'duration_minutes' => 30, 'cost_cents' => null, 'price_source' => 'legacy_unknown'];
                $this->ctx->issue('agendamentos', $sid, C::Orphan, S::Warning, 'unknown_catalog_item',
                    "Item {$item} nao existe mais no catalogo: importado com valor desconhecido.", ['item' => $item], true);
            }
        }
        if ($itens === []) {
            $this->ctx->issue('agendamentos', $sid, C::Inconsistent, S::Warning, 'appointment_without_services', 'Agendamento sem servicos: importado sem itens de servico.', [], true);
        }
        $produtosRaw = V::text($row['produtos_vendidos'] ?? null);
        if ($produtosRaw !== null) {
            $produtos = json_decode($produtosRaw, true);
            if (! is_array($produtos)) {
                $this->ctx->issue('agendamentos', $sid, C::Inconsistent, S::Warning, 'invalid_products_json', 'Produtos vendidos ilegiveis (JSON): nao importados.', ['produtos_vendidos' => mb_substr($produtosRaw, 0, 500)], true);
            } else {
                foreach ($produtos as $p) {
                    $valor = is_array($p) ? V::money(isset($p['valor']) ? (string) $p['valor'] : null)['cents'] : null;
                    if ($valor === null || $valor < 0) {
                        $this->ctx->issue('agendamentos', $sid, C::Inconsistent, S::Warning, 'invalid_product_item', 'Produto vendido sem valor valido: nao importado.', ['produto' => $p], true);

                        continue;
                    }
                    // O JSON guarda o TOTAL da linha e a quantidade no nome ("Pomada (2x)"): gravado como 1 linha com o total registrado.
                    $itens[] = ['item_type' => 'product', 'service_id' => null, 'package_id' => null,
                        'product_id' => $this->ctx->ref('produtos', isset($p['produto_id']) ? (string) $p['produto_id'] : null),
                        'name' => V::unescapedText($p['nome'] ?? null) ?? 'Produto', 'unit_price_cents' => $valor, 'duration_minutes' => null,
                        'cost_cents' => isset($p['custo']) ? V::money((string) $p['custo'])['cents'] : null, 'price_source' => 'recorded'];
                }
            }
        }

        $minutos = array_sum(array_map(fn ($i) => $i['item_type'] === 'product' ? 0 : (int) $i['duration_minutes'], $itens));
        $fim = $inicio->addMinutes(max(30, $minutos));

        // ---- Desconto
        $desconto = $this->money('agendamentos', $sid, 'desconto_aplicado', $row['desconto_aplicado'] ?? null) ?? 0;
        $tipoDesconto = V::text($row['tipo_desconto'] ?? null);
        if ($desconto < 0) {
            $this->ctx->issue('agendamentos', $sid, C::Inconsistent, S::Warning, 'negative_discount', 'Desconto negativo: ignorado (o sistema antigo tambem o ignora).', ['desconto' => $row['desconto_aplicado']]);
            $desconto = 0;
        }

        // ---- Totais (desconto so sobre servicos/combos, limitado a eles)
        $conhecido = ! in_array(null, array_column($itens, 'unit_price_cents'), true);
        $servicos = array_sum(array_map(fn ($i) => $i['item_type'] === 'product' ? 0 : (int) $i['unit_price_cents'], $itens));
        $produtosTotal = array_sum(array_map(fn ($i) => $i['item_type'] === 'product' ? (int) $i['unit_price_cents'] : 0, $itens));
        $descontoEfetivo = min($desconto, $servicos);
        $subtotal = $conhecido ? $servicos + $produtosTotal : null;
        $total = $conhecido ? $subtotal - $descontoEfetivo : null;

        // ---- Status e cancelamento
        $cancel = ['cancelled_at' => null, 'cancelled_by' => null, 'cancellation_reason' => null];
        $criado = $this->local($row['data_criacao'] ?? null);
        if ($statusRaw === 'cancelado') {
            $cancel['cancelled_by'] = 'staff';
        } elseif ($statusRaw === 'cancelado_pelo_cliente') {
            $cancel['cancelled_by'] = 'customer';
        } elseif ($statusRaw === 'rejeitado') {
            $cancel = ['cancelled_at' => null, 'cancelled_by' => 'staff', 'cancellation_reason' => 'rejected'];
        } elseif ($status === 'awaiting_payment') {
            $limite = ($criado ?? $inicio)->addMinutes(self::PAYMENT_WINDOW_MINUTES);
            if ($limite->lt($this->ctx->now) || $inicio->lt($this->ctx->now)) {
                $status = 'cancelled';
                $cancel = ['cancelled_at' => null, 'cancelled_by' => 'system', 'cancellation_reason' => 'payment_expired'];
                $this->ctx->issue('agendamentos', $sid, C::Legacy, S::Info, 'expired_payment_cancelled',
                    'Aguardando pagamento ha mais de 15 minutos: importado como cancelado (o sistema antigo liberaria o horario).');
            }
        }
        if (in_array($status, ['confirmed', 'pending'], true) && $inicio->lt($this->ctx->now)) {
            $this->ctx->issue('agendamentos', $sid, C::PotentiallyValid, S::Warning, 'past_not_completed',
                'Agendamento no passado sem conclusao (D-17): importado com o status original, precisa de revisao (concluido ou falta).', ['status' => $statusRaw], true);
        }

        // ---- Vinculos
        $clienteId = $this->ctx->ref('clientes', $row['cliente_id'] ?? null);
        if ($clienteId === null && V::text($row['cliente_id'] ?? null) !== null) {
            $this->ctx->issue('agendamentos', $sid, C::Orphan, S::Info, 'orphan_customer', 'Cliente do agendamento nao existe mais: mantido como agendamento sem conta (contato do proprio registro).', ['cliente_id' => $row['cliente_id']]);
        }
        $profId = $this->professionalFor($row['barbeiro_id'] ?? null, 'agendamentos', $sid);
        $profNome = $profId ? ($this->professionalNames[$profId] ??= (string) DB::table('professionals')->where('id', $profId)->value('display_name')) : null;
        if ($profId === null) {
            $this->ctx->issue('agendamentos', $sid, C::Inconsistent, S::Warning, 'appointment_without_professional', 'Agendamento sem barbeiro.', [], true);
        }
        $nome = $this->text('agendamentos', $row['nome'] ?? null)
            ?? ($clienteId ? DB::table('customers')->where('id', $clienteId)->value('name') : null);
        if ($nome === null) {
            $this->ctx->issue('agendamentos', $sid, C::PotentiallyValid, S::Info, 'appointment_without_name', 'Agendamento sem nome do cliente: gravado como "(sem nome)".');
        }

        $op = $this->operations[$sid] ?? null;
        $id = $this->ctx->insert('appointments', [
            'code' => mb_substr($sid, 0, 32),
            'customer_id' => $clienteId,
            'professional_id' => $profId,
            'professional_name' => $profNome,
            'customer_name' => $nome ?? '(sem nome)',
            'customer_email' => V::text($row['email'] ?? null),
            'customer_phone' => V::text($row['telefone'] ?? null),
            'starts_at' => $inicio,
            'ends_at' => $fim,
            'status' => $status,
            'source' => 'legacy',
            'notes' => $this->text('agendamentos', $row['observacoes'] ?? null),
            'subtotal_cents' => $subtotal,
            'discount_cents' => $conhecido ? $descontoEfetivo : null,
            'total_cents' => $total,
            ...$cancel,
            'confirmation_requested_at' => ($op && V::text($op['confirmacao_status'] ?? null) === 'enviado') ? $this->local($op['updated_at'] ?? null) : null,
            'confirmed_at' => $this->local($row['presenca_confirmada'] ?? null),
            'completed_at' => $status === 'completed' ? $this->local($row['comanda_fechada_em'] ?? null) : null,
            'payment_gateway' => V::text($row['payment_gateway'] ?? null),
            'payment_gateway_reference' => V::text($row['gateway_reference'] ?? null),
            ...$this->stamps($criado),
        ]);
        $this->ctx->remember('agendamentos', $sid, 'appointment', $id, $row);

        foreach ($itens as $item) {
            $this->ctx->insert('appointment_items', [
                'appointment_id' => $id, ...$item, 'quantity' => 1,
                'total_cents' => $item['unit_price_cents'], ...$this->stamps(),
            ]);
        }
        if ($desconto > 0) {
            $kind = self::DISCOUNT_MAP[(string) $tipoDesconto] ?? 'legacy_unknown';
            $this->ctx->insert('appointment_adjustments', [
                'appointment_id' => $id, 'kind' => $kind, 'amount_cents' => $desconto,
                'gift_card_id' => $kind === 'gift_card' ? $this->ctx->ref('vouchers', $this->vouchers[$sid] ?? null) : null,
                'description' => $tipoDesconto ? "Desconto do sistema antigo ({$tipoDesconto})" : 'Desconto do sistema antigo (origem nao informada)',
                ...$this->stamps(),
            ]);
        }

        if ($status === 'completed') {
            $this->payment($sid, $row, $id, $clienteId, $total, $itens);
        }
        $this->reminders($row, $id);
        $this->events($sid, $id, $row);
    }

    /**
     * @param  array{price: ?int, name: string, minutes: int}  $cat
     * @return array<string, mixed>
     */
    private function catalogItem(string $tipo, ?int $novoId, array $cat): array
    {
        $conhecido = $cat['price'] !== null && $novoId !== null;

        return ['item_type' => $tipo, 'service_id' => $tipo === 'service' ? $novoId : null, 'package_id' => $tipo === 'package' ? $novoId : null, 'product_id' => null,
            'name' => $cat['name'], 'unit_price_cents' => $conhecido ? $cat['price'] : null, 'duration_minutes' => $cat['minutes'], 'cost_cents' => null,
            'price_source' => $conhecido ? 'legacy_catalog_estimate' : 'legacy_unknown'];
    }

    /**
     * @param  array<string, ?string>  $row
     * @param  list<array<string, mixed>>  $itens
     */
    private function payment(string $sid, array $row, int $appointmentId, ?int $clienteId, ?int $total, array $itens): void
    {
        $gorjeta = max(0, $this->money('agendamentos', $sid, 'gorjeta', $row['gorjeta'] ?? null) ?? 0);
        if ($total === null) {
            $this->ctx->issue('agendamentos', $sid, C::Inconsistent, S::Warning, 'payment_amount_unknown',
                'Atendimento concluido com item de valor desconhecido: pagamento NAO criado (valor nao pode ser afirmado).', ['gorjeta_centavos' => $gorjeta], true);

            return;
        }
        if ($total + $gorjeta <= 0) {
            return; // nada recebido (ex.: 100% de desconto sem gorjeta)
        }
        $metodoRaw = V::text($row['forma_pagamento'] ?? null);
        $metodo = self::METHOD_MAP[(string) $metodoRaw] ?? 'unknown';
        if ($metodoRaw !== null && ! isset(self::METHOD_MAP[$metodoRaw])) {
            $this->ctx->issue('agendamentos', $sid, C::Unknown, S::Info, 'unknown_payment_method', "Forma de pagamento \"{$metodoRaw}\" desconhecida: gravada como nao informada.");
        }
        $estimado = count(array_filter($itens, fn ($i) => $i['item_type'] !== 'product')) > 0;
        $this->ctx->insert('payments', [
            'appointment_id' => $appointmentId, 'customer_id' => $clienteId, 'kind' => 'payment', 'method' => $metodo,
            'amount_cents' => $total, 'tip_cents' => $gorjeta, 'amount_source' => $estimado ? 'legacy_estimated' : 'recorded',
            'paid_at' => $this->local($row['comanda_fechada_em'] ?? null), ...$this->stamps(),
        ]);
    }

    /**
     * lembrete_data = data do atendimento cujo lembrete de vespera ja foi
     * enviado (sem a hora do envio); lembrete_hora_em = instante do envio do
     * lembrete "horas antes".
     *
     * @param  array<string, ?string>  $row
     */
    private function reminders(array $row, int $appointmentId): void
    {
        if (V::text($row['lembrete_data'] ?? null) !== null) {
            $this->ctx->insert('appointment_reminders', ['appointment_id' => $appointmentId, 'kind' => 'day_before', 'status' => 'sent', 'sent_at' => null, ...$this->stamps()]);
        }
        if (V::text($row['lembrete_hora_em'] ?? null) !== null) {
            $this->ctx->insert('appointment_reminders', ['appointment_id' => $appointmentId, 'kind' => 'hours_before', 'status' => 'sent', 'sent_at' => $this->local($row['lembrete_hora_em']), ...$this->stamps()]);
        }
    }

    /**
     * @param  array<string, ?string>  $row
     */
    private function events(string $sid, int $appointmentId, array $row): void
    {
        foreach ($this->history[$sid] ?? [] as $h) {
            $this->ctx->insert('appointment_events', [
                'appointment_id' => $appointmentId,
                'type' => 'legacy.'.mb_substr((string) (V::text($h['acao'] ?? null) ?? 'evento'), 0, 56),
                'description' => $this->text('agenda_historico', $h['detalhes'] ?? null),
                'actor_label' => V::text($h['usuario'] ?? null),
                'occurred_at' => $this->local($h['created_at'] ?? null),
                'created_at' => $this->ctx->now,
            ]);
            $this->ctx->count('agenda_historico', 'imported');
        }
        if ($plano = V::text($row['plano_provisorio'] ?? null)) {
            $this->ctx->insert('appointment_events', [
                'appointment_id' => $appointmentId, 'type' => 'legacy.plan_signup_intent', 'description' => 'Adesao a plano escolhida no agendamento (sistema antigo).',
                'data' => json_encode(['plano_antigo' => $plano, 'plan_id' => $this->ctx->ref('planos', $plano)]), 'occurred_at' => null, 'created_at' => $this->ctx->now,
            ]);
        }
        if (isset($this->operations[$sid])) {
            $this->ctx->count('agenda_operacao', 'imported');
        }
    }

    /** Historico/operacao de agendamentos que nao existem mais: so contados e reportados. */
    private function historyWithoutAppointment(): void
    {
        foreach (['agenda_historico' => $this->history, 'agenda_operacao' => $this->operations] as $tabela => $porAgendamento) {
            $orfaos = array_diff_key($porAgendamento, array_flip(array_map('strval', DB::table('legacy_references')->where('source_table', 'agendamentos')->pluck('source_id')->all())));
            if ($orfaos !== []) {
                $n = $tabela === 'agenda_historico' ? array_sum(array_map('count', $orfaos)) : count($orfaos);
                $this->ctx->count($tabela, 'skipped', $n);
                $this->ctx->issue($tabela, null, C::Orphan, S::Info, 'orphan_history', "{$n} registro(s) de agendamentos que nao existem mais: nao importados (ficam no banco antigo).", ['agendamentos' => array_slice(array_keys($orfaos), 0, 50)]);
            }
        }
    }

    private function linkVouchers(): void
    {
        foreach ($this->vouchers as $agendamento => $voucher) {
            $gift = $this->ctx->ref('vouchers', $voucher);
            if ($gift === null || DB::table('gift_cards')->where('id', $gift)->whereNotNull('redeemed_appointment_id')->exists()) {
                continue;
            }
            $ap = $this->ctx->ref('agendamentos', $agendamento);
            if ($ap === null) {
                $this->ctx->issue('vouchers', $voucher, C::Orphan, S::Info, 'voucher_orphan_appointment', 'Vale usado em agendamento que nao existe mais.', ['agendamento_id_uso' => $agendamento]);

                continue;
            }
            DB::table('gift_cards')->where('id', $gift)->update(['redeemed_appointment_id' => $ap]);
        }
    }

    /**
     * Sobreposicao no mesmo profissional entre agendamentos ATIVOS que ainda
     * nao terminaram: pendencia bloqueante (resolver antes da virada).
     * Varredura ordenada O(n log n), sem auto-junção da tabela inteira.
     */
    private function detectConflicts(): void
    {
        $linhas = DB::table('appointments')
            ->whereIn('status', ['pending', 'awaiting_payment', 'confirmed'])
            ->where('source', 'legacy')->whereNotNull('professional_id')
            ->where('ends_at', '>', $this->ctx->now)
            ->orderBy('professional_id')->orderBy('starts_at')->orderBy('id')
            ->get(['code', 'professional_id', 'starts_at', 'ends_at']);

        $prof = null;
        $maiorFim = null;
        $dono = null;
        foreach ($linhas as $l) {
            if ($l->professional_id !== $prof) {
                [$prof, $maiorFim, $dono] = [$l->professional_id, $l->ends_at, $l->code];

                continue;
            }
            if ($l->starts_at < $maiorFim) {
                $this->ctx->issue('agendamentos', $l->code, C::Duplicate, S::Error, 'future_overlap',
                    "Agendamento futuro sobreposto a {$dono} no mesmo profissional: resolver com a recepcao antes da virada.", ['outro' => $dono, 'inicio_utc' => (string) $l->starts_at], true);
            }
            if ($l->ends_at > $maiorFim) {
                [$maiorFim, $dono] = [$l->ends_at, $l->code];
            }
        }
    }
}
