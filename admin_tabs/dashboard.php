<?php
// admin_tabs/dashboard.php
// Versão 4.0 — Painel operacional + gerencial.
//
// A versão anterior mostrava 4 KPIs soltos (receita, atendimentos, novos
// clientes, ticket) sem nenhum ponto de comparação e três listas de top 5.
// Todo o resto do que o admin_data.php já calcula — lucro líquido, margem,
// variação vs. período anterior, ocupação, MRR, avaliações, estoque, despesas,
// clientes em risco — ficava só na aba Relatórios ou não aparecia em lugar
// nenhum. Esta versão consome esses dados e organiza a tela em três camadas:
//
//   1. HOJE      — o que está acontecendo agora (ignora o filtro de período).
//   2. AÇÕES     — o que precisa da atenção do dono, com link para a aba certa.
//   3. PERÍODO   — resultado, tendência e rankings do intervalo filtrado.

// =============================================================================
// CAMADA 1: SNAPSHOT DE HOJE (independente do filtro de período)
// =============================================================================
$dashHoje       = date('Y-m-d');
$dashAgoraHora  = date('H:i');
$statusMortos   = ['cancelado', 'cancelado_pelo_cliente', 'rejeitado', 'aguardando_pagamento'];

$agsHoje = array_filter($agendamentosArr, function ($ag) use ($dashHoje, $statusMortos) {
    return ($ag['data'] ?? '') === $dashHoje && !in_array($ag['status'] ?? '', $statusMortos, true);
});
uasort($agsHoje, function ($a, $b) { return strcmp($a['hora'] ?? '', $b['hora'] ?? ''); });

$hojeTotal       = count($agsHoje);
$hojeConcluidos  = 0;
$hojeReceita     = 0.0;
$hojeProximo     = null;

foreach ($agsHoje as $ag) {
    if (($ag['status'] ?? '') === 'concluido') {
        $hojeConcluidos++;
        $valoresHoje = calcularValoresAgendamentoRelatorio($ag, $servicosArr, $combosArr);
        $hojeReceita += $valoresHoje['total'];
    } elseif ($hojeProximo === null && ($ag['hora'] ?? '') >= $dashAgoraHora) {
        // Primeiro da fila que ainda não passou: é o "próximo" da recepção.
        $hojeProximo = $ag;
    }
}
$hojeRestantes = $hojeTotal - $hojeConcluidos;

// Ocupação de hoje: mesma métrica da aba Relatórios, só que com a janela de
// um dia — responde "a agenda de hoje está cheia ou vazia?".
$hojeOcupacoes = [];
foreach ($barbeirosArr as $bId => $bDados) {
    if (($bDados['status'] ?? 'ativo') === 'inativo') continue;
    $hojeOcupacoes[] = getTaxaOcupacaoBarbeiro(
        $bId, $dashHoje, $dashHoje, $agsHoje, $horariosTrabalhoRaw, $servicosArr, $combosArr
    );
}
$hojeOcupacao = $hojeOcupacoes ? round(array_sum($hojeOcupacoes) / count($hojeOcupacoes), 1) : 0;

// =============================================================================
// CAMADA 2: AÇÕES PENDENTES
// Cada alerta só entra na tela quando tem contagem > 0 e leva para a aba que
// resolve o problema. Um painel que mostra "0 pendências" em seis caixas é
// ruído; um que só fala quando há algo a fazer é ferramenta.
// =============================================================================
$dashAlertas = [];

$qtdPendentes = count($agendamentosPendentes ?? []);
if ($qtdPendentes > 0) {
    $dashAlertas[] = ['tipo' => 'danger', 'icone' => 'fa-hourglass-half', 'n' => $qtdPendentes,
        'texto' => 'agendamento(s) aguardando aprovação', 'aba' => 'agendamentos'];
}

// Estoque no/abaixo do mínimo configurado.
$produtosCriticos = array_filter($produtosArr ?? [], function ($p) {
    $minimo = (int)($p['estoque_minimo'] ?? 0);
    return $minimo > 0 && (int)($p['quantidade'] ?? 0) <= $minimo;
});
if ($produtosCriticos) {
    $dashAlertas[] = ['tipo' => 'danger', 'icone' => 'fa-box-open', 'n' => count($produtosCriticos),
        'texto' => 'produto(s) no estoque mínimo', 'aba' => 'servicos'];
}

// Despesas já vencidas e ainda em aberto.
$despesasVencidas = array_filter($despesasArr ?? [], function ($d) use ($dashHoje) {
    $vencimento = $d['data_vencimento'] ?? '';
    return ($d['status'] ?? '') !== 'pago' && $vencimento !== '' && $vencimento < $dashHoje;
});
if ($despesasVencidas) {
    $dashAlertas[] = ['tipo' => 'danger', 'icone' => 'fa-file-invoice-dollar', 'n' => count($despesasVencidas),
        'texto' => 'despesa(s) vencida(s)', 'aba' => 'financeiro'];
}

if (!empty($assinaturasVencendo)) {
    $dashAlertas[] = ['tipo' => 'warn', 'icone' => 'fa-crown', 'n' => count($assinaturasVencendo),
        'texto' => 'assinatura(s) vencendo em 15 dias', 'aba' => 'assinaturas'];
}

// $agendamentosPendentesDeAvaliacao varre o histórico inteiro — como alerta
// ele mostrava "715 atendimentos sem avaliação", número grande demais para
// alguém agir. Aqui contamos só o período filtrado, que é o que dá para
// recuperar com uma campanha de lembrete.
$idsAvaliadosPeriodo = array_column($avaliacoesArr ?? [], 'agendamento_id');
$qtdSemAvaliacao = count(array_filter($agendamentosConcluidos, function ($ag) use ($idsAvaliadosPeriodo) {
    return !in_array($ag['id'], $idsAvaliadosPeriodo, true);
}));
if ($qtdSemAvaliacao > 0) {
    $dashAlertas[] = ['tipo' => 'info', 'icone' => 'fa-star-half-stroke', 'n' => $qtdSemAvaliacao,
        'texto' => 'atendimento(s) do período sem avaliação', 'aba' => 'avaliacoes'];
}

$qtdEmRisco = count($clientesEmRisco ?? []);
if ($qtdEmRisco > 0) {
    $dashAlertas[] = ['tipo' => 'warn', 'icone' => 'fa-user-clock', 'n' => $qtdEmRisco,
        'texto' => 'cliente(s) sem retornar há 90 dias', 'aba' => 'marketing'];
}

// Só mostra o alerta se o perfil realmente pode abrir a aba de destino.
$dashAlertas = array_filter($dashAlertas, function ($a) {
    return adminPodeAcessarAba($a['aba']);
});

// =============================================================================
// CAMADA 3: PERÍODO FILTRADO
// =============================================================================

// Perfis sem acesso ao financeiro (ex.: recepção) não devem ver faturamento e
// lucro no dashboard — a aba Financeiro já é bloqueada para eles.
$podeVerDinheiro = adminPodeAcessarAba('financeiro');

$totalPeriodo      = $contagemStatus['total'] ?? 0;
$canceladosPeriodo = $contagemStatus['cancelado'] ?? 0;
$taxaCancelamento  = $totalPeriodo > 0 ? round(($canceladosPeriodo / $totalPeriodo) * 100, 1) : 0;

// Atalhos de período. O filtro por data continua disponível ao lado; estes
// cobrem os recortes que o dono usa todo dia sem precisar digitar duas datas.
$dashPresets = [
    'Hoje'         => [date('Y-m-d'), date('Y-m-d')],
    '7 dias'       => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
    'Este mês'     => [date('Y-m-01'), date('Y-m-t')],
    'Mês passado'  => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    '90 dias'      => [date('Y-m-d', strtotime('-89 days')), date('Y-m-d')],
];

/** Renderiza o selo de variação percentual usado nos KPIs. */
function dashSeloVariacao($variacao) {
    $v = (float)$variacao;
    if (abs($v) < 0.05) {
        return '<span class="kpi-trend trend-flat"><i class="fa fa-minus"></i> estável</span>';
    }
    $classe = $v > 0 ? 'trend-up' : 'trend-down';
    $seta   = $v > 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down';
    $sinal  = $v > 0 ? '+' : '';
    return '<span class="kpi-trend ' . $classe . '"><i class="fa ' . $seta . '"></i> '
         . $sinal . number_format($v, 1, ',', '.') . '%</span>';
}

$periodoAnteriorLabel = date('d/m', strtotime($comparativoPeriodo['anterior_inicio']))
    . ' a ' . date('d/m', strtotime($comparativoPeriodo['anterior_fim']));

// Ranking da equipe, restrito ao período filtrado (o $estatisticas_barbeiros do
// admin_data.php é histórico total — usar aquele aqui fazia o card contradizer
// os KPIs logo acima, que são do período).
$rankingEquipe = [];
foreach ($agendamentosConcluidos as $ag) {
    $bid = $ag['barbeiro_id'] ?? '';
    if ($bid === '' || !isset($barbeirosArr[$bid])) continue;
    if (!isset($rankingEquipe[$bid])) {
        $rankingEquipe[$bid] = ['receita' => 0.0, 'atendimentos' => 0];
    }
    $valoresBarbeiro = calcularValoresAgendamentoRelatorio($ag, $servicosArr, $combosArr);
    $rankingEquipe[$bid]['receita'] += $valoresBarbeiro['total'];
    $rankingEquipe[$bid]['atendimentos']++;
}
uasort($rankingEquipe, function ($a, $b) { return $b['receita'] <=> $a['receita']; });
$receitaLiderEquipe = $rankingEquipe ? reset($rankingEquipe)['receita'] : 0;

// Top serviços do período (o ranking já vem ordenado por quantidade).
$topServicos = array_slice($rankingServicosIds, 0, 6, true);
$maiorQtdServico = $topServicos ? max($topServicos) : 0;

// Horários de pico: corta as pontas vazias para não desenhar 24 colunas zeradas.
$picoAtivo = array_filter($horariosPico, function ($q) { return $q > 0; });
$picoMax = $picoAtivo ? max($picoAtivo) : 0;
if ($picoAtivo) {
    $horasComMovimento = array_keys($picoAtivo);
    $primeiraHora = min($horasComMovimento);
    $ultimaHora   = max($horasComMovimento);
    $picoJanela = array_filter($horariosPico, function ($h) use ($primeiraHora, $ultimaHora) {
        return $h >= $primeiraHora && $h <= $ultimaHora;
    }, ARRAY_FILTER_USE_KEY);
} else {
    $picoJanela = [];
}

// Composição da receita — só faz sentido para quem enxerga o financeiro.
$composicaoReceita = [
    ['Serviços e combos', $receitaServicos, '#3b82f6'],
    ['Assinaturas',       $receitaPlanos,   '#a855f7'],
    ['Produtos',          $receitaProdutos, '#f97316'],
];

// Aniversariantes do dia.
$dashHojeMD = date('m-d');
$aniversariantes = array_filter($clientesArr ?? [], function ($c) use ($dashHojeMD) {
    return !empty($c['data_nascimento']) && substr($c['data_nascimento'], 5, 5) === $dashHojeMD;
});

// Pacote de contexto enviado ao consultor de IA. Quanto mais completo, mais
// útil o diagnóstico — a versão anterior mandava só 6 linhas de faturamento.
$topBarbeiroNome = 'Nenhum';
if ($rankingEquipe) {
    $idTopBarbeiro = array_key_first($rankingEquipe);
    $topBarbeiroNome = $barbeirosArr[$idTopBarbeiro]['nome'] ?? 'Nenhum';
}
// Os números financeiros só entram no pacote quando o perfil pode vê-los: este
// texto vai para o HTML da página E para a API de IA, então incluí-lo sempre
// vazaria faturamento para a recepção mesmo com os cards escondidos.
$linhasParaIA = [
    'Período analisado: ' . date('d/m/Y', strtotime($data_inicio_filtro)) . ' a ' . date('d/m/Y', strtotime($data_fim_filtro)),
    'Atendimentos concluídos: ' . ($contagemStatus['concluido'] ?? 0) . ' de ' . $totalPeriodo . ' agendados (taxa de cancelamento: ' . number_format($taxaCancelamento, 1, ',', '.') . '%)',
    'Ocupação média da equipe: ' . number_format($taxaOcupacaoMedia, 1, ',', '.') . '%',
    'Clientes: ' . ($statsClientes['novos_no_periodo'] ?? 0) . ' novos e ' . ($dadosNovosVsRecorrentes['recorrentes'] ?? 0) . ' recorrentes; ' . $qtdEmRisco . ' sem retornar há 90 dias',
    'Avaliação média no período: ' . (is_numeric($mediaAvaliacoes) ? $mediaAvaliacoes . ' de 5 em ' . $totalAvaliacoes . ' avaliações' : 'sem avaliações'),
    'Profissional com mais atendimentos: ' . $topBarbeiroNome,
];
if ($podeVerDinheiro) {
    array_splice($linhasParaIA, 1, 0, [
        'Receita total: R$ ' . number_format($receitaTotal, 2, ',', '.') . ' (variação vs. período anterior: ' . number_format($comparativoPeriodo['receita']['var'], 1, ',', '.') . '%)',
        'Lucro líquido: R$ ' . number_format($lucroLiquidoPeriodo, 2, ',', '.') . ' (margem de ' . number_format($margemLucroPeriodo, 1, ',', '.') . '%)',
        'Custos: comissões R$ ' . number_format($totalComissoesPeriodo, 2, ',', '.') . ', despesas R$ ' . number_format($totalDespesasPeriodo, 2, ',', '.') . ', CMV de produtos R$ ' . number_format($relCmvProdutos, 2, ',', '.'),
        'Composição da receita: serviços R$ ' . number_format($receitaServicos, 2, ',', '.') . ', assinaturas R$ ' . number_format($receitaPlanos, 2, ',', '.') . ', produtos R$ ' . number_format($receitaProdutos, 2, ',', '.'),
        'Ticket médio: R$ ' . number_format($ticketMedio, 2, ',', '.'),
        'Assinaturas ativas: ' . $totalAssinantesAtivos . ' (MRR de R$ ' . number_format($mrrAtual, 2, ',', '.') . ')',
    ]);
}
$dadosParaIA = implode("\n", $linhasParaIA);
?>

<style>
    .modern-dashboard {
        display: flex; flex-direction: column; gap: 24px;
        padding-bottom: 40px; animation: fadeIn .5s ease-out;
    }

    /* Cabeçalho, KPIs e widgets base vivem em css/admin_components.css. */

    /* --- Filtro de período --------------------------------------------- */
    .dash-period-tools { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
    .period-presets { display: flex; gap: 6px; flex-wrap: wrap; }
    .period-preset {
        padding: 7px 13px; border: 1px solid #e2e8f0; border-radius: 8px;
        background: #fff; color: #64748b; font-size: .8rem; font-weight: 700;
        text-decoration: none; transition: .2s; white-space: nowrap;
    }
    .period-preset:hover { border-color: #cbd5e1; background: #f8fafc; color: #1e293b; }
    .period-preset.active {
        background: var(--secondary-color, #007bff); border-color: var(--secondary-color, #007bff); color: #fff;
    }
    .modern-filter-form { display: flex; gap: 8px; align-items: center; }
    .modern-filter-form input[type="date"] {
        padding: 9px 13px; border: 1px solid #cbd5e1; border-radius: 9px;
        background: #f8fafc; color: #334155; font-weight: 600; outline: none; transition: .2s;
    }
    .modern-filter-form input:focus { background: #fff; border-color: var(--secondary-color, #007bff); }
    .btn-filtrar {
        padding: 10px 18px; border: none; border-radius: 9px;
        background: var(--secondary-color, #007bff); color: #fff;
        font-weight: 700; cursor: pointer; transition: .2s; white-space: nowrap;
    }
    .btn-filtrar:hover { filter: brightness(1.08); transform: translateY(-1px); }

    /* --- Camada 1: hoje ------------------------------------------------- */
    .today-strip { display: grid; grid-template-columns: repeat(auto-fit, minmax(215px, 1fr)); gap: 16px; }
    .today-card {
        padding: 17px 20px; background: #fff;
        border: 1px solid #e2e8f0; border-left: 4px solid var(--secondary-color, #007bff);
        border-radius: 13px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, .03);
    }
    .today-card.is-live { border-left-color: #10b981; }
    .today-label {
        display: flex; align-items: center; gap: 7px; margin-bottom: 9px;
        color: #64748b; font-size: .71rem; font-weight: 800;
        text-transform: uppercase; letter-spacing: .6px;
    }
    .today-value { color: #0f172a; font-size: 1.5rem; font-weight: 900; line-height: 1.1; overflow-wrap: anywhere; }
    .today-value.is-text { font-size: 1.05rem; }
    .today-meta { margin-top: 7px; color: #94a3b8; font-size: .77rem; font-weight: 600; }

    /* --- Camada 2: alertas acionáveis ----------------------------------- */
    .alert-rail { display: flex; flex-wrap: wrap; gap: 12px; }
    .alert-chip {
        display: flex; align-items: center; gap: 12px;
        padding: 12px 17px; border: 1px solid; border-radius: 12px;
        text-decoration: none; transition: .2s;
    }
    .alert-chip:hover { transform: translateY(-2px); box-shadow: 0 12px 22px -14px rgba(15, 23, 42, .5); }
    .alert-chip i { font-size: 1.05rem; }
    .chip-count { font-size: 1.2rem; font-weight: 900; line-height: 1; }
    .chip-text { color: #475569; font-size: .8rem; font-weight: 700; }
    .chip-danger { background: #fef2f2; border-color: #fecaca; }
    .chip-danger i, .chip-danger .chip-count { color: #dc2626; }
    .chip-warn { background: #fffbeb; border-color: #fde68a; }
    .chip-warn i, .chip-warn .chip-count { color: #d97706; }
    .chip-info { background: #f0f9ff; border-color: #bae6fd; }
    .chip-info i, .chip-info .chip-count { color: #0284c7; }
    .alert-ok {
        display: flex; align-items: center; gap: 10px;
        padding: 14px 18px; border: 1px solid #a7f3d0; border-radius: 12px;
        background: #ecfdf5; color: #065f46; font-size: .88rem; font-weight: 700;
    }

    /* --- Camada 3: KPIs ------------------------------------------------- */
    /* KPI principal empilhado: com o ícone ao lado, "R$ 44.199,60" quebrava
       em duas linhas no card de 250px. Em coluna o número ganha a largura
       inteira e o cartão comporta o selo de variação e a linha de apoio. */
    .kpi-grid-main .kpi-card { flex-direction: column; align-items: stretch; gap: 15px; }
    .kpi-grid-main .kpi-head { display: flex; align-items: center; gap: 13px; }
    .kpi-grid-main .kpi-icon { width: 44px; height: 44px; border-radius: 11px; font-size: 1.15rem; }
    .kpi-grid-main .kpi-head h4 {
        margin: 0; color: #64748b; font-size: .78rem; font-weight: 800;
        text-transform: uppercase; letter-spacing: .5px;
    }
    .kpi-grid-main .kpi-info .value { font-size: 1.7rem; white-space: nowrap; }

    .icon-receita { background: #ecfdf5; color: #10b981; }
    .icon-lucro { background: #eef2ff; color: #6366f1; }
    .icon-agendamentos { background: #eff6ff; color: #3b82f6; }
    .icon-ticket { background: #fff7ed; color: #f97316; }
    .icon-clientes { background: #fdf4ff; color: #a855f7; }
    .icon-ocupacao { background: #f0fdfa; color: #14b8a6; }
    .icon-assinatura { background: #fefce8; color: #ca8a04; }
    .icon-nota { background: #fff7ed; color: #f59e0b; }
    .icon-cancel { background: #fef2f2; color: #ef4444; }

    .mini-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; }
    .mini-kpi {
        display: flex; align-items: center; gap: 14px;
        padding: 15px 18px; background: #fff;
        border: 1px solid #e2e8f0; border-radius: 12px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, .03); transition: .25s;
    }
    .mini-kpi:hover { border-color: #cbd5e1; transform: translateY(-2px); }
    .mini-kpi-icon {
        width: 42px; height: 42px; flex-shrink: 0; border-radius: 11px;
        display: flex; align-items: center; justify-content: center; font-size: 1.05rem;
    }
    .mini-kpi-label { color: #64748b; font-size: .69rem; font-weight: 800; text-transform: uppercase; letter-spacing: .5px; }
    .mini-kpi-value { margin-top: 3px; color: #0f172a; font-size: 1.22rem; font-weight: 900; line-height: 1.15; }
    .mini-kpi-value small { color: #94a3b8; font-size: .72rem; font-weight: 700; }

    /* --- Listas com barra proporcional ---------------------------------- */
    .bar-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 17px; }
    .bar-row { display: flex; flex-direction: column; gap: 8px; }
    .bar-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
    .bar-ident { display: flex; align-items: center; gap: 11px; min-width: 0; }
    .bar-avatar { width: 36px; height: 36px; flex-shrink: 0; border: 2px solid #e2e8f0; border-radius: 50%; object-fit: cover; }
    .bar-rank {
        width: 24px; height: 24px; flex-shrink: 0; border-radius: 7px;
        display: flex; align-items: center; justify-content: center;
        background: #f1f5f9; color: #64748b; font-size: .72rem; font-weight: 900;
    }
    .bar-rank.is-first { background: #fef3c7; color: #b45309; }
    .bar-name { color: #1e293b; font-size: .92rem; font-weight: 800; overflow-wrap: anywhere; }
    .bar-sub { color: #94a3b8; font-size: .76rem; font-weight: 600; }
    .bar-value { flex-shrink: 0; color: #0f172a; font-size: .95rem; font-weight: 900; text-align: right; }
    .bar-value small { display: block; color: #94a3b8; font-size: .68rem; font-weight: 700; text-transform: uppercase; }
    .bar-track { height: 7px; border-radius: 99px; background: #f1f5f9; overflow: hidden; }
    .bar-fill { height: 100%; border-radius: 99px; transition: width .4s ease; }

    /* --- Horários de pico ----------------------------------------------- */
    /* A coluna precisa de altura DEFINIDA (100% de .peak-chart, que tem height
       fixo): sem isso o height percentual da barra não resolve e todas ficam
       coladas no chão, com 1px de altura. */
    .peak-chart { display: flex; align-items: flex-end; gap: 5px; height: 200px; padding-top: 8px; }
    .peak-col { flex: 1; min-width: 0; height: 100%; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; gap: 7px; }
    .peak-bar {
        width: 100%; min-height: 4px; border-radius: 6px 6px 0 0;
        background: var(--secondary-color, #007bff); opacity: .35; transition: .25s;
    }
    .peak-col.is-peak .peak-bar { opacity: 1; }
    .peak-col:hover .peak-bar { opacity: .8; }
    .peak-label { color: #94a3b8; font-size: .64rem; font-weight: 700; }

    /* --- Consultor de IA ------------------------------------------------ */
    .ia-card {
        padding: 22px 26px; border: 1px solid #e9d5ff; border-radius: 14px;
        background: linear-gradient(135deg, #faf5ff 0%, #f3e8ff 100%);
        box-shadow: 0 4px 10px rgba(109, 40, 217, .05);
    }
    .ia-head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px; margin-bottom: 14px; }
    .ia-head h4 { margin: 0; display: flex; align-items: center; gap: 9px; color: #5b21b6; font-size: 1.12rem; font-weight: 800; }
    .btn-ia {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 9px 17px; border: none; border-radius: 9px;
        background: #8b5cf6; color: #fff; font-size: .88rem; font-weight: 700;
        cursor: pointer; transition: .2s; box-shadow: 0 4px 12px rgba(139, 92, 246, .25);
    }
    .btn-ia:hover:not(:disabled) { transform: translateY(-2px); filter: brightness(1.07); }
    .btn-ia:disabled { opacity: .7; cursor: wait; }
    .ia-text { color: #4c1d95; font-size: .93rem; line-height: 1.65; }

    /* --- Estado vazio dentro de widget ---------------------------------- */
    .widget-empty { padding: 26px 10px; color: #94a3b8; font-size: .88rem; font-style: italic; text-align: center; }

    /* --- Listas simples (aniversariantes / atividade) -------------------- */
    .modern-list { list-style: none; margin: 0; padding: 0; }
    .modern-list li {
        display: flex; align-items: center; justify-content: space-between; gap: 12px;
        padding: 13px 0; border-bottom: 1px solid #f1f5f9;
    }
    .modern-list li:last-child { border-bottom: none; }
    .item-profile { display: flex; align-items: center; gap: 13px; min-width: 0; }
    .item-img { width: 38px; height: 38px; border: 2px solid #e2e8f0; border-radius: 50%; object-fit: cover; }
    .item-info { min-width: 0; }
    .item-info strong { display: block; color: #1e293b; font-size: .93rem; overflow-wrap: anywhere; }
    .item-info span { color: #64748b; font-size: .79rem; overflow-wrap: anywhere; }
    .item-link { color: #1e293b; text-decoration: none; transition: .2s; }
    .item-link:hover { color: var(--secondary-color, #007bff); }

    .activity-feed { list-style: none; margin: 0; padding: 0; }
    .activity-feed li { display: flex; align-items: center; gap: 12px; padding: 11px 0; border-bottom: 1px solid #f1f5f9; }
    .activity-feed li:last-child { border-bottom: none; }
    .activity-icon {
        width: 34px; height: 34px; flex-shrink: 0; border-radius: 9px;
        display: inline-flex; align-items: center; justify-content: center; font-size: .9rem;
    }
    .activity-body { min-width: 0; display: flex; flex-direction: column; }
    .activity-body strong { color: #1e293b; font-size: .91rem; font-weight: 700; overflow-wrap: anywhere; }
    .activity-body small { color: #94a3b8; font-size: .75rem; }

    /* --- Grid assimétrico (gráfico maior + card lateral) ---------------- */
    .widget-grid-wide { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr); gap: 22px; }

    @media (max-width: 980px) {
        .widget-grid-wide { grid-template-columns: 1fr; }
    }
    @media (max-width: 768px) {
        .dash-period-tools { width: 100%; }
        .modern-filter-form { width: 100%; flex-wrap: wrap; }
        .modern-filter-form input[type="date"] { flex: 1; min-width: 130px; }
        .btn-filtrar { width: 100%; }
        .peak-chart { height: 140px; }
        .peak-label { font-size: .55rem; }
    }
</style>

<div class="modern-dashboard">

    <!-- ================= CABEÇALHO + PERÍODO ================= -->
    <div class="dash-header-bar">
        <div class="dash-title">
            <h3><i class="fa fa-gauge-high"></i> Visão Geral do Estabelecimento</h3>
            <p>
                Período: <strong><?= date('d/m/Y', strtotime($data_inicio_filtro)) ?></strong>
                até <strong><?= date('d/m/Y', strtotime($data_fim_filtro)) ?></strong>
                &middot; comparado com <?= htmlspecialchars($periodoAnteriorLabel) ?>
            </p>
        </div>

        <div class="dash-period-tools">
            <div class="period-presets">
                <?php foreach ($dashPresets as $rotulo => $intervalo):
                    $ativo = ($data_inicio_filtro === $intervalo[0] && $data_fim_filtro === $intervalo[1]);
                    $urlPreset = 'admin.php?tab=dashboard&data_inicio=' . $intervalo[0] . '&data_fim=' . $intervalo[1];
                ?>
                    <a href="<?= htmlspecialchars($urlPreset) ?>" class="period-preset<?= $ativo ? ' active' : '' ?>"><?= htmlspecialchars($rotulo) ?></a>
                <?php endforeach; ?>
            </div>

            <form method="GET" class="modern-filter-form">
                <input type="hidden" name="tab" value="dashboard">
                <input type="date" name="data_inicio" value="<?= htmlspecialchars($data_inicio_filtro) ?>">
                <input type="date" name="data_fim" value="<?= htmlspecialchars($data_fim_filtro) ?>">
                <button type="submit" class="btn-filtrar"><i class="fa fa-filter"></i> Filtrar</button>
            </form>
        </div>
    </div>

    <!-- ================= CAMADA 1: HOJE ================= -->
    <div class="today-strip">
        <div class="today-card is-live">
            <div class="today-label"><i class="fa fa-calendar-day"></i> Agenda de hoje</div>
            <div class="today-value"><?= $hojeConcluidos ?> / <?= $hojeTotal ?></div>
            <div class="today-meta">
                <?php if ($hojeTotal === 0): ?>
                    Nenhum atendimento marcado para hoje
                <?php else: ?>
                    <?= $hojeRestantes ?> ainda por atender
                <?php endif; ?>
            </div>
        </div>

        <?php if ($podeVerDinheiro): ?>
        <div class="today-card">
            <div class="today-label"><i class="fa fa-cash-register"></i> Caixa de hoje</div>
            <div class="today-value">R$ <?= number_format($hojeReceita, 2, ',', '.') ?></div>
            <div class="today-meta">Somente atendimentos já concluídos</div>
        </div>
        <?php endif; ?>

        <div class="today-card">
            <div class="today-label"><i class="fa fa-forward"></i> Próximo atendimento</div>
            <?php if ($hojeProximo): ?>
                <div class="today-value is-text">
                    <?= htmlspecialchars(substr($hojeProximo['hora'] ?? '', 0, 5)) ?> &middot;
                    <?= htmlspecialchars($hojeProximo['nome'] ?? 'Cliente') ?>
                </div>
                <div class="today-meta">
                    com <?= htmlspecialchars($barbeirosArr[$hojeProximo['barbeiro_id']]['nome'] ?? 'profissional removido') ?>
                </div>
            <?php else: ?>
                <div class="today-value is-text">Nada na fila</div>
                <div class="today-meta">A agenda de hoje já foi cumprida</div>
            <?php endif; ?>
        </div>

        <div class="today-card">
            <div class="today-label"><i class="fa fa-chart-simple"></i> Ocupação de hoje</div>
            <div class="today-value"><?= number_format($hojeOcupacao, 1, ',', '.') ?>%</div>
            <div class="today-meta">Da jornada cadastrada da equipe ativa</div>
        </div>
    </div>

    <!-- ================= CAMADA 2: AÇÕES PENDENTES ================= -->
    <?php if (!empty($dashAlertas)): ?>
        <div class="alert-rail">
            <?php foreach ($dashAlertas as $alerta): ?>
                <a href="admin.php?tab=<?= htmlspecialchars($alerta['aba']) ?>" class="alert-chip chip-<?= htmlspecialchars($alerta['tipo']) ?>">
                    <i class="fa <?= htmlspecialchars($alerta['icone']) ?>"></i>
                    <span>
                        <span class="chip-count"><?= (int)$alerta['n'] ?></span>
                        <span class="chip-text"><?= htmlspecialchars($alerta['texto']) ?></span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="alert-ok">
            <i class="fa fa-circle-check"></i> Nenhuma pendência: agenda aprovada, estoque abastecido e contas em dia.
        </div>
    <?php endif; ?>

    <!-- ================= CAMADA 3: KPIs DO PERÍODO ================= -->
    <div class="kpi-grid kpi-grid-main">
        <?php if ($podeVerDinheiro): ?>
        <div class="kpi-card">
            <div class="kpi-head">
                <div class="kpi-icon icon-receita"><i class="fa fa-wallet"></i></div>
                <h4>Receita Total</h4>
            </div>
            <div class="kpi-info">
                <div class="value">R$ <?= number_format($receitaTotal, 2, ',', '.') ?></div>
                <?= dashSeloVariacao($comparativoPeriodo['receita']['var']) ?>
                <div class="kpi-sub">Antes: R$ <?= number_format($comparativoPeriodo['receita']['anterior'], 2, ',', '.') ?></div>
            </div>
        </div>

        <div class="kpi-card">
            <div class="kpi-head">
                <div class="kpi-icon icon-lucro"><i class="fa fa-sack-dollar"></i></div>
                <h4>Lucro Líquido</h4>
            </div>
            <div class="kpi-info">
                <div class="value">R$ <?= number_format($lucroLiquidoPeriodo, 2, ',', '.') ?></div>
                <?= dashSeloVariacao($comparativoPeriodo['lucro']['var']) ?>
                <div class="kpi-sub">Margem de <?= number_format($margemLucroPeriodo, 1, ',', '.') ?>% &middot; custos R$ <?= number_format($totalCustosPeriodo, 2, ',', '.') ?></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="kpi-card">
            <div class="kpi-head">
                <div class="kpi-icon icon-agendamentos"><i class="fa fa-calendar-check"></i></div>
                <h4>Atendimentos</h4>
            </div>
            <div class="kpi-info">
                <div class="value"><?= $contagemStatus['concluido'] ?? 0 ?></div>
                <?= dashSeloVariacao($comparativoPeriodo['atendimentos']['var']) ?>
                <div class="kpi-sub"><?= $totalPeriodo ?> agendados no período</div>
            </div>
        </div>

        <?php if ($podeVerDinheiro): ?>
        <div class="kpi-card">
            <div class="kpi-head">
                <div class="kpi-icon icon-ticket"><i class="fa fa-receipt"></i></div>
                <h4>Ticket Médio</h4>
            </div>
            <div class="kpi-info">
                <div class="value">R$ <?= number_format($ticketMedio, 2, ',', '.') ?></div>
                <?= dashSeloVariacao($comparativoPeriodo['ticket']['var']) ?>
                <div class="kpi-sub">Antes: R$ <?= number_format($comparativoPeriodo['ticket']['anterior'], 2, ',', '.') ?></div>
            </div>
        </div>
        <?php else: ?>
        <div class="kpi-card">
            <div class="kpi-head">
                <div class="kpi-icon icon-clientes"><i class="fa fa-users"></i></div>
                <h4>Novos Clientes</h4>
            </div>
            <div class="kpi-info">
                <div class="value"><?= $statsClientes['novos_no_periodo'] ?? 0 ?></div>
                <div class="kpi-sub"><?= $dadosNovosVsRecorrentes['recorrentes'] ?? 0 ?> recorrentes no período</div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- KPIs secundários: saúde do negócio além do caixa -->
    <div class="mini-kpi-grid">
        <div class="mini-kpi">
            <div class="mini-kpi-icon icon-clientes"><i class="fa fa-user-plus"></i></div>
            <div>
                <div class="mini-kpi-label">Novos clientes</div>
                <div class="mini-kpi-value"><?= $statsClientes['novos_no_periodo'] ?? 0 ?> <small>/ <?= $dadosNovosVsRecorrentes['recorrentes'] ?? 0 ?> recorrentes</small></div>
            </div>
        </div>

        <div class="mini-kpi">
            <div class="mini-kpi-icon icon-ocupacao"><i class="fa fa-gauge"></i></div>
            <div>
                <div class="mini-kpi-label">Ocupação média</div>
                <div class="mini-kpi-value"><?= number_format($taxaOcupacaoMedia, 1, ',', '.') ?>%</div>
            </div>
        </div>

        <?php if ($podeVerDinheiro): ?>
        <div class="mini-kpi">
            <div class="mini-kpi-icon icon-assinatura"><i class="fa fa-crown"></i></div>
            <div>
                <div class="mini-kpi-label">Assinantes ativos</div>
                <div class="mini-kpi-value"><?= $totalAssinantesAtivos ?> <small>MRR R$ <?= number_format($mrrAtual, 2, ',', '.') ?></small></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="mini-kpi">
            <div class="mini-kpi-icon icon-nota"><i class="fa fa-star"></i></div>
            <div>
                <div class="mini-kpi-label">Avaliação no período</div>
                <div class="mini-kpi-value">
                    <?= is_numeric($mediaAvaliacoes) ? number_format($mediaAvaliacoes, 1, ',', '.') : '—' ?>
                    <small><?= $totalAvaliacoes ?> avaliação(ões)</small>
                </div>
            </div>
        </div>

        <div class="mini-kpi">
            <div class="mini-kpi-icon icon-cancel"><i class="fa fa-calendar-xmark"></i></div>
            <div>
                <div class="mini-kpi-label">Cancelamentos</div>
                <div class="mini-kpi-value"><?= number_format($taxaCancelamento, 1, ',', '.') ?>% <small><?= $canceladosPeriodo ?> de <?= $totalPeriodo ?></small></div>
            </div>
        </div>
    </div>

    <!-- ================= CONSULTOR DE IA ================= -->
    <div class="ia-card">
        <div class="ia-head">
            <h4><i class="fa fa-lightbulb"></i> Consultor de Negócios (IA)</h4>
            <button id="btn-gerar-consultoria" class="btn-ia"><i class="fa fa-wand-magic-sparkles"></i> Analisar Painel</button>
        </div>
        <div id="ia-consultoria-text" class="ia-text">
            <i class="fa fa-info-circle"></i> A IA lê os indicadores deste período — receita, margem, ocupação, retenção e avaliações — e devolve um plano de ação priorizado.
        </div>
    </div>

    <!-- ================= RECEITA AO LONGO DO PERÍODO ================= -->
    <?php if ($podeVerDinheiro): ?>
    <div class="widget-grid-wide">
        <div class="widget-card">
            <div class="widget-header"><h4><i class="fa fa-chart-area"></i> Receita por dia</h4></div>
            <?php if (empty($receitaDiaria)): ?>
                <div class="widget-empty">Sem receita registrada neste período.</div>
            <?php else: ?>
                <div style="height: 280px;"><canvas id="receitaDiariaDashChart"></canvas></div>
            <?php endif; ?>
        </div>

        <div class="widget-card">
            <div class="widget-header"><h4><i class="fa fa-layer-group"></i> De onde vem a receita</h4></div>
            <?php if ($receitaTotal <= 0): ?>
                <div class="widget-empty">Sem receita registrada neste período.</div>
            <?php else: ?>
                <ul class="bar-list">
                    <?php foreach ($composicaoReceita as $fonte):
                        [$rotuloFonte, $valorFonte, $corFonte] = $fonte;
                        $percFonte = $receitaTotal > 0 ? ($valorFonte / $receitaTotal) * 100 : 0;
                    ?>
                        <li class="bar-row">
                            <div class="bar-top">
                                <div class="bar-ident"><span class="bar-name"><?= htmlspecialchars($rotuloFonte) ?></span></div>
                                <div class="bar-value">
                                    R$ <?= number_format($valorFonte, 2, ',', '.') ?>
                                    <small><?= number_format($percFonte, 1, ',', '.') ?>%</small>
                                </div>
                            </div>
                            <div class="bar-track">
                                <div class="bar-fill" style="width: <?= number_format(max(0, min(100, $percFonte)), 2, '.', '') ?>%; background: <?= $corFonte ?>;"></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ================= RANKING DA EQUIPE + TOP SERVIÇOS ================= -->
    <div class="widget-grid">
        <div class="widget-card">
            <div class="widget-header"><h4><i class="fa fa-user-tie"></i> Ranking da equipe no período</h4></div>
            <?php if (empty($rankingEquipe)): ?>
                <div class="widget-empty">Nenhum atendimento concluído neste período.</div>
            <?php else: ?>
                <ul class="bar-list">
                    <?php $posicao = 0; foreach (array_slice($rankingEquipe, 0, 5, true) as $bId => $stats):
                        $posicao++;
                        $barbeiro = $barbeirosArr[$bId] ?? null;
                        if (!$barbeiro) continue;
                        $fotoBarbeiro = (!empty($barbeiro['foto']) && file_exists($barbeiro['foto'])) ? $barbeiro['foto'] : 'uploads/default-profile.jpg';
                        $percBarra = $receitaLiderEquipe > 0 ? ($stats['receita'] / $receitaLiderEquipe) * 100 : 0;
                        $ocupacaoBarbeiro = $taxasOcupacao[$barbeiro['nome']] ?? null;
                        $notaBarbeiro = $estatisticas_barbeiros[$bId]['media_avaliacoes'] ?? 0;
                    ?>
                        <li class="bar-row">
                            <div class="bar-top">
                                <div class="bar-ident">
                                    <span class="bar-rank<?= $posicao === 1 ? ' is-first' : '' ?>"><?= $posicao ?></span>
                                    <img src="<?= htmlspecialchars($fotoBarbeiro) ?>" class="bar-avatar" alt="" onerror="this.src='uploads/default-profile.jpg';">
                                    <div>
                                        <div class="bar-name"><?= htmlspecialchars($barbeiro['nome']) ?></div>
                                        <div class="bar-sub">
                                            <?= (int)$stats['atendimentos'] ?> atendimentos
                                            <?php if ($ocupacaoBarbeiro !== null): ?>
                                                &middot; <?= number_format($ocupacaoBarbeiro, 0, ',', '.') ?>% ocupado
                                            <?php endif; ?>
                                            <?php if ($notaBarbeiro > 0): ?>
                                                &middot; <i class="fa fa-star" style="color:#f59e0b;"></i> <?= number_format($notaBarbeiro, 1, ',', '.') ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php if ($podeVerDinheiro): ?>
                                    <div class="bar-value">R$ <?= number_format($stats['receita'], 2, ',', '.') ?><small>Produção</small></div>
                                <?php else: ?>
                                    <div class="bar-value"><?= (int)$stats['atendimentos'] ?><small>Atend.</small></div>
                                <?php endif; ?>
                            </div>
                            <div class="bar-track">
                                <div class="bar-fill" style="width: <?= number_format(max(0, min(100, $percBarra)), 2, '.', '') ?>%; background: #10b981;"></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="widget-card">
            <div class="widget-header"><h4><i class="fa fa-scissors"></i> Serviços mais vendidos</h4></div>
            <?php if (empty($topServicos)): ?>
                <div class="widget-empty">Nenhum serviço realizado neste período.</div>
            <?php else: ?>
                <ul class="bar-list">
                    <?php foreach ($topServicos as $sid => $qtd):
                        $nomeServico = $servicosArr[$sid]['nome'] ?? ($combosArr[$sid]['nome'] ?? 'Serviço removido');
                        $percServico = $maiorQtdServico > 0 ? ($qtd / $maiorQtdServico) * 100 : 0;
                    ?>
                        <li class="bar-row">
                            <div class="bar-top">
                                <div class="bar-ident"><span class="bar-name"><?= htmlspecialchars($nomeServico) ?></span></div>
                                <div class="bar-value"><?= (int)$qtd ?><small>Realizados</small></div>
                            </div>
                            <div class="bar-track">
                                <div class="bar-fill" style="width: <?= number_format(max(0, min(100, $percServico)), 2, '.', '') ?>%; background: var(--secondary-color, #007bff);"></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- ================= HORÁRIOS DE PICO + NOVOS VS RECORRENTES ================= -->
    <div class="widget-grid">
        <div class="widget-card">
            <div class="widget-header"><h4><i class="fa fa-clock"></i> Horários de maior movimento</h4></div>
            <?php if (empty($picoJanela)): ?>
                <div class="widget-empty">Sem movimento registrado neste período.</div>
            <?php else: ?>
                <div class="peak-chart">
                    <?php foreach ($picoJanela as $horaLabel => $qtdHora):
                        // Teto em 85% para sobrar espaço da legenda da hora
                        // dentro da mesma coluna de altura fixa.
                        $altura = $picoMax > 0 ? max(2, ($qtdHora / $picoMax) * 85) : 2;
                    ?>
                        <div class="peak-col<?= ($qtdHora === $picoMax && $qtdHora > 0) ? ' is-peak' : '' ?>" title="<?= htmlspecialchars($horaLabel) ?> — <?= (int)$qtdHora ?> atendimento(s)">
                            <div class="peak-bar" style="height: <?= number_format($altura, 2, '.', '') ?>%;"></div>
                            <span class="peak-label"><?= htmlspecialchars(substr($horaLabel, 0, 2)) ?>h</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="widget-card">
            <div class="widget-header"><h4><i class="fa fa-chart-pie"></i> Novos vs. recorrentes</h4></div>
            <div style="height: 250px;"><canvas id="novosRecorrentesChart"></canvas></div>
        </div>
    </div>

    <!-- ================= ANIVERSARIANTES + ATIVIDADE ================= -->
    <div class="widget-grid">
        <div class="widget-card">
            <div class="widget-header"><h4><i class="fa fa-cake-candles"></i> Aniversariantes de hoje</h4></div>
            <?php if (empty($aniversariantes)): ?>
                <div class="widget-empty">Nenhum cliente faz aniversário hoje.</div>
            <?php else: ?>
                <ul class="modern-list">
                    <?php foreach ($aniversariantes as $id => $c):
                        $fotoCliente = (!empty($c['foto_perfil']) && file_exists($c['foto_perfil'])) ? $c['foto_perfil'] : 'uploads/default-profile.jpg';
                    ?>
                        <li>
                            <div class="item-profile">
                                <img src="<?= htmlspecialchars($fotoCliente) ?>" class="item-img" alt="" onerror="this.src='uploads/default-profile.jpg';">
                                <div class="item-info">
                                    <a href="#" class="item-link" data-modal-target="#modal-cliente-detalhes" data-id="<?= htmlspecialchars($c['id'] ?? $id) ?>">
                                        <strong><?= htmlspecialchars($c['nome']) ?></strong>
                                    </a>
                                    <span><?= htmlspecialchars($c['telefone'] ?? '') ?></span>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="widget-card">
            <div class="widget-header"><h4><i class="fa fa-clock-rotate-left"></i> Atividade recente no painel</h4></div>
            <?php $atividadesRecentes = function_exists('obterAtividadeRecenteAdmin') ? obterAtividadeRecenteAdmin(8) : []; ?>
            <?php if (empty($atividadesRecentes)): ?>
                <div class="widget-empty">Nenhuma ação registrada ainda.</div>
            <?php else: ?>
                <ul class="activity-feed">
                    <?php foreach ($atividadesRecentes as $atividade):
                        $desc = descreverAtividadeAdmin($atividade['detalhes'] ?? '', $atividade['acao'] ?? '');
                        $ts = strtotime($atividade['created_at'] ?? '');
                        $diff = $ts ? (time() - $ts) : null;
                        if ($diff === null)     { $quando = '—'; }
                        elseif ($diff < 60)     { $quando = 'agora mesmo'; }
                        elseif ($diff < 3600)   { $quando = 'há ' . floor($diff / 60) . ' min'; }
                        elseif ($diff < 86400)  { $quando = 'há ' . floor($diff / 3600) . 'h'; }
                        elseif ($diff < 604800) { $quando = 'há ' . floor($diff / 86400) . ' dia(s)'; }
                        else                    { $quando = date('d/m/Y H:i', $ts); }
                    ?>
                        <li>
                            <span class="activity-icon" style="background: <?= htmlspecialchars($desc['cor']) ?>1a; color: <?= htmlspecialchars($desc['cor']) ?>;">
                                <i class="fa <?= htmlspecialchars($desc['icone']) ?>"></i>
                            </span>
                            <span class="activity-body">
                                <strong><?= htmlspecialchars($desc['titulo']) ?></strong>
                                <small><?= htmlspecialchars($atividade['usuario'] ?? 'sistema') ?> &middot; <?= htmlspecialchars($quando) ?></small>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const btnConsultoria = document.getElementById('btn-gerar-consultoria');
    const contentConsultoria = document.getElementById('ia-consultoria-text');

    // json_encode em vez de template literal: o resumo tem quebras de linha,
    // aspas e R$ — interpolar isso cru dentro de uma crase quebrava o script.
    const dadosParaIA = <?= json_encode($dadosParaIA, JSON_UNESCAPED_UNICODE) ?>;

    if (!btnConsultoria) return;

    btnConsultoria.addEventListener('click', async function () {
        btnConsultoria.disabled = true;
        btnConsultoria.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processando...';
        contentConsultoria.innerHTML = '<div style="text-align:center; padding:10px; color:#7c3aed;"><i class="fa fa-circle-notch fa-spin fa-2x" style="margin-bottom:10px;"></i><br>Analisando faturamento, ocupação e retenção...</div>';

        try {
            const response = await fetch('ajax_gemini.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'consultor_dashboard', dados_dashboard: dadosParaIA })
            });
            const result = await response.json();

            if (result.success) {
                const texto = document.createElement('div');
                texto.textContent = result.resposta;
                contentConsultoria.innerHTML =
                    '<strong style="display:block; margin-bottom:10px; color:#4c1d95; font-size:1.05rem;"><i class="fa fa-check-circle"></i> Diagnóstico concluído:</strong>'
                    + texto.innerHTML.replace(/\n/g, '<br>');
            } else {
                const erro = document.createElement('span');
                erro.textContent = result.error || 'Falha desconhecida.';
                contentConsultoria.innerHTML = '<span style="color:#ef4444; font-weight:bold;"><i class="fa fa-triangle-exclamation"></i> Erro: ' + erro.innerHTML + '</span>';
            }
        } catch (error) {
            contentConsultoria.innerHTML = '<span style="color:#ef4444; font-weight:bold;"><i class="fa fa-wifi"></i> Erro de conexão com o servidor ou timeout. Tente novamente.</span>';
            console.error(error);
        } finally {
            btnConsultoria.disabled = false;
            btnConsultoria.innerHTML = '<i class="fa fa-rotate"></i> Atualizar análise';
        }
    });
});
</script>
