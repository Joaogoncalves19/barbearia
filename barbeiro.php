<?php
require_once 'functions.php';
iniciarSessaoSegura();
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['barbeiro_loggedin'])) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once 'functions.php';

$mensagem_sucesso = $_SESSION['agendamento_sucesso'] ?? ($_GET['success'] ?? '');
unset($_SESSION['agendamento_sucesso']);
if ($mensagem_sucesso) $mensagem_sucesso = htmlspecialchars($mensagem_sucesso);
$mensagem_erro = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';

$barbeiro_id = $_SESSION['barbeiro_id'];

// Colunas de comissão/assinatura/metas/notas garantidas por lib/migrations.php.
try {
    $pdo = getDB();
} catch(Exception $e) {}

$configGeral = carregarConfigGeral() ?: [];
$servicosArr = lerDados('servicos', ['id', 'nome', 'valor']) ?: [];
$combosArr = lerDados('combos', ['id', 'nome', 'servicos_ids', 'valor']) ?: []; 
$agendamentosArr = lerDados('agendamentos', ['id', 'nome', 'email', 'telefone', 'barbeiro_id', 'servicos_ids', 'data', 'hora', 'status', 'desconto_aplicado', 'tipo_desconto', 'observacoes', 'produtos_vendidos', 'plano_provisorio', 'cliente_id']) ?: [];
$avaliacoesArr = lerDados('avaliacoes', ['id', 'agendamento_id', 'cliente_id', 'barbeiro_id', 'rating', 'comment', 'timestamp']) ?: [];
$produtosArr = lerDados('produtos', ['id', 'nome', 'valor', 'quantidade']) ?: [];
$clientesArr = lerDados('clientes', ['id', 'nome', 'telefone', 'notas_barbeiro']) ?: [];

$planosArr = lerDados('planos', ['id', 'nome', 'valor', 'servicos_ids']) ?: [];
$assinaturasArr = lerDados('clientes_assinaturas', ['cliente_id', 'plano_id', 'data_inicio', 'data_fim', 'status']) ?: [];
$barbeirosArr = lerDados('barbeiros', ['id', 'nome', 'username', 'foto', 'status']) ?: [];

$stmtBarbeiro = $pdo->prepare("SELECT * FROM barbeiros WHERE id = ?");
$stmtBarbeiro->execute([$barbeiro_id]);
$barbeiro_atual = $stmtBarbeiro->fetch(PDO::FETCH_ASSOC);
if (!$barbeiro_atual) {
    $barbeiro_atual = [];
}

// Defesa em profundidade: um profissional desativado durante a sessão perde
// o acesso ao painel no próximo carregamento (o login já bloqueia na entrada).
if (($barbeiro_atual['status'] ?? 'ativo') === 'inativo') {
    session_destroy();
    header('Location: login.php?error=' . urlencode('Acesso desativado.'));
    exit;
}

// CORREÇÃO: Garante carregamento seguro da foto do barbeiro logado
$foto_barbeiro = 'uploads/default-profile.jpg';
if (!empty(trim($barbeiro_atual['foto'] ?? '')) && file_exists(trim($barbeiro_atual['foto']))) {
    $foto_barbeiro = trim($barbeiro_atual['foto']);
}

$meta_diaria = (float)($barbeiro_atual['meta_diaria'] ?? 200);
$descricao_comissao_assinatura = descreverComissaoAssinatura($barbeiro_atual);

$config_almoco_barbeiro = ['status' => 'inativo', 'horario' => '12:00'];
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS config_almoco_barbeiro (barbeiro_id TEXT PRIMARY KEY, status TEXT, horario TEXT)");
    $stmt = $pdo->prepare("SELECT status, horario FROM config_almoco_barbeiro WHERE barbeiro_id = ?");
    $stmt->execute([$barbeiro_id]);
    if ($row = $stmt->fetch()) { $config_almoco_barbeiro['status'] = $row['status']; $config_almoco_barbeiro['horario'] = $row['horario']; }
} catch (PDOException $e) {}

$horarios_para_almoco = [];
for ($i = 1; $i <= 5; $i++) { $horarioTrabalhoReferencia = getHorarioDeTrabalho($barbeiro_id, $i); if ($horarioTrabalhoReferencia) break; }
if ($horarioTrabalhoReferencia) {
    $start = strtotime($horarioTrabalhoReferencia['inicio'] ?? '08:00'); $end = strtotime($horarioTrabalhoReferencia['fim'] ?? '18:00');
    while ($start < $end) { $horarios_para_almoco[] = date('H:i', $start); $start = strtotime('+30 minutes', $start); }
    if (count($horarios_para_almoco) > 1) array_pop($horarios_para_almoco);
}

$meusAgendamentos = array_filter($agendamentosArr, function($ag) use ($barbeiro_id) { return ($ag['barbeiro_id'] ?? '') === $barbeiro_id && ($ag['status'] ?? '') !== 'aguardando_pagamento' && ($ag['status'] ?? '') !== 'pendente'; });
$agendamentos_concluidos_total = array_filter($meusAgendamentos, function($ag) { return ($ag['status'] ?? '') === 'concluido'; });

$contagem_visitas = [];
foreach($agendamentos_concluidos_total as $ag) {
    if(!empty($ag['cliente_id'])) {
        $contagem_visitas[$ag['cliente_id']] = ($contagem_visitas[$ag['cliente_id']] ?? 0) + 1;
    }
}

$hoje = date('Y-m-d');
$mes_atual = date('Y-m');
$semana_atual_inicio = date('Y-m-d', strtotime('monday this week'));
$semana_atual_fim = date('Y-m-d', strtotime('sunday this week'));

$agendamentosHoje = array_filter($meusAgendamentos, function($a) use ($hoje) { return ($a['data'] ?? '') === $hoje && ($a['status'] ?? '') === 'aprovado'; });
$agendamentosFuturos = array_filter($meusAgendamentos, function($a) use ($hoje) { return ($a['data'] ?? '') > $hoje && ($a['status'] ?? '') === 'aprovado'; });
$agendamentosPassados = array_filter($meusAgendamentos, function($a) use ($hoje) { return ($a['data'] ?? '') < $hoje || in_array(($a['status'] ?? ''), ['concluido', 'cancelado', 'cancelado_pelo_cliente']); });

uasort($agendamentosHoje, function($a, $b) { return strcmp($a['hora'] ?? '', $b['hora'] ?? ''); });
uasort($agendamentosFuturos, function($a, $b) { return strtotime(($a['data'] ?? '') . ' ' . ($a['hora'] ?? '')) - strtotime(($b['data'] ?? '') . ' ' . ($b['hora'] ?? '')); });
uasort($agendamentosPassados, function($a, $b) { return strtotime(($b['data'] ?? '') . ' ' . ($b['hora'] ?? '')) - strtotime(($a['data'] ?? '') . ' ' . ($a['hora'] ?? '')); });

// CORREÇÃO: $agora não estava definido, então o "próximo cliente" nunca
// respeitava o horário atual. Agora escolhe o primeiro aprovado ainda por vir.
$agora = date('H:i');
$proximoCliente = null;
foreach($agendamentosHoje as $ag) { if (($ag['status'] ?? '') === 'aprovado' && substr($ag['hora'] ?? '', 0, 5) >= $agora) { $proximoCliente = $ag; break; } }
if (!$proximoCliente && !empty($agendamentosHoje)) { foreach($agendamentosHoje as $ag) { if (($ag['status'] ?? '') === 'aprovado') { $proximoCliente = $ag; break; } } }

$itemsPerPage = 10;
$totalItems = count($agendamentosPassados);
$totalPages = $totalItems > 0 ? ceil($totalItems / $itemsPerPage) : 1;
$currentPage = max(1, min((int)($_GET['page'] ?? 1), $totalPages));
$agendamentosPaginados = array_slice($agendamentosPassados, ($currentPage - 1) * $itemsPerPage, $itemsPerPage, true);

function calcularReceitas($agendamentos, $servicos, $combos, $barbeiro) {
    $bruto = 0; $liquido = 0;
    foreach($agendamentos as $ag) {
        $rec_serv = 0; $rec_prod = 0;
        $sids = explode(',', $ag['servicos_ids'] ?? '');
        foreach($sids as $sid) {
            $sid = trim($sid);
            if(isset($servicos[$sid])) { $rec_serv += (float)$servicos[$sid]['valor']; }
            elseif(isset($combos[$sid])) { $rec_serv += (float)$combos[$sid]['valor']; }
        }
        $prods = !empty($ag['produtos_vendidos']) ? json_decode($ag['produtos_vendidos'], true) : [];
        if(is_array($prods)) { foreach($prods as $p) { $rec_prod += (float)($p['valor'] ?? 0); } }
        
        $desc = (float)($ag['desconto_aplicado'] ?? 0);
        $rec_serv_liquido = max(0, $rec_serv - $desc);
        $calculoComissao = calcularComissaoAtendimento($ag, $barbeiro, $rec_serv, $rec_prod);

        $bruto += ($rec_serv_liquido + $rec_prod);
        $liquido += $calculoComissao['comissao_total'];
    }
    return ['bruto' => $bruto, 'liquido' => $liquido];
}

$ag_concluidos_hoje = array_filter($agendamentos_concluidos_total, function($ag) use ($hoje) { return ($ag['data'] ?? '') === $hoje; });
$ag_concluidos_semana = array_filter($agendamentos_concluidos_total, function($a) use ($semana_atual_inicio, $semana_atual_fim) { return ($a['data'] ?? '') >= $semana_atual_inicio && ($a['data'] ?? '') <= $semana_atual_fim; });
$ag_concluidos_mes = array_filter($agendamentos_concluidos_total, function($a) use ($mes_atual) { return substr(($a['data'] ?? ''), 0, 7) === $mes_atual; });

$rec_hoje = calcularReceitas($ag_concluidos_hoje, $servicosArr, $combosArr, $barbeiro_atual);
$rec_semana = calcularReceitas($ag_concluidos_semana, $servicosArr, $combosArr, $barbeiro_atual);
$rec_mes = calcularReceitas($ag_concluidos_mes, $servicosArr, $combosArr, $barbeiro_atual);

$percentual_meta = $meta_diaria > 0 ? min(100, round(($rec_hoje['liquido'] / $meta_diaria) * 100)) : 0;
$total_finalizados = count(array_filter($meusAgendamentos, function($a) { return in_array(($a['status'] ?? ''), ['concluido', 'cancelado', 'cancelado_pelo_cliente']); }));
$taxa_comparecimento = $total_finalizados > 0 ? round((count($agendamentos_concluidos_total) / $total_finalizados) * 100) : 0;
$avaliacoes_barbeiro = array_filter($avaliacoesArr, function($av) use ($barbeiro_id) { return ($av['barbeiro_id'] ?? '') === $barbeiro_id; });
$media_avaliacoes = count($avaliacoes_barbeiro) > 0 ? round(array_sum(array_column($avaliacoes_barbeiro, 'rating')) / count($avaliacoes_barbeiro), 1) : 'N/A';
$qtd_avaliacoes = count($avaliacoes_barbeiro);

// ==========================================================================
// MÉTRICAS ADICIONAIS PARA O PAINEL REFORMULADO
// ==========================================================================
$dia_semana_hoje = (int)date('w');
$dias_semana_curto = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

// Contagens e ticket do dia
$qtd_concluidos_hoje = count($ag_concluidos_hoje);
$qtd_restantes_hoje = count($agendamentosHoje);
$total_agendado_hoje = $qtd_concluidos_hoje + $qtd_restantes_hoje;
$ticket_medio_hoje = $qtd_concluidos_hoje > 0 ? ($rec_hoje['bruto'] / $qtd_concluidos_hoje) : 0;
$qtd_clientes_semana = count($ag_concluidos_semana);
$qtd_clientes_mes = count($ag_concluidos_mes);

// Gráfico dos últimos 7 dias (comissão líquida por dia)
$grafico_7dias = [];
$max_7dias = 0.0;
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $ags_dia = array_filter($agendamentos_concluidos_total, fn($a) => ($a['data'] ?? '') === $d);
    $rec = calcularReceitas($ags_dia, $servicosArr, $combosArr, $barbeiro_atual);
    $grafico_7dias[] = [
        'label' => date('d/m', strtotime($d)),
        'dia_semana' => $dias_semana_curto[(int)date('w', strtotime($d))],
        'liquido' => $rec['liquido'],
        'qtd' => count($ags_dia),
        'is_hoje' => ($d === $hoje),
    ];
    if ($rec['liquido'] > $max_7dias) $max_7dias = $rec['liquido'];
}
$total_7dias_liquido = array_sum(array_column($grafico_7dias, 'liquido'));

// Linha do tempo do dia: aprovados + concluídos de hoje, em ordem de horário
$timeline_hoje = array_filter($meusAgendamentos, fn($a) => ($a['data'] ?? '') === $hoje && in_array(($a['status'] ?? ''), ['aprovado', 'concluido'], true));
uasort($timeline_hoje, fn($a, $b) => strcmp($a['hora'] ?? '', $b['hora'] ?? ''));

// Grade do dia com horários livres (aba Agenda)
$expediente_hoje = getHorarioDeTrabalho($barbeiro_id, $dia_semana_hoje, $hoje);
$ocupados_hoje = getHorariosOcupados($barbeiro_id, $hoje);
$mapa_hora_ag = [];
foreach ($timeline_hoje as $a) { $mapa_hora_ag[substr($a['hora'] ?? '', 0, 5)] = $a; }
$slots_dia = [];
if ($expediente_hoje) {
    $ini = strtotime($expediente_hoje['inicio'] ?? '08:00');
    $fim = strtotime($expediente_hoje['fim'] ?? '18:00');
    for ($t = $ini; $t < $fim; $t = strtotime('+30 minutes', $t)) {
        $hm = date('H:i', $t);
        $slots_dia[] = [
            'hora' => $hm,
            'ag' => $mapa_hora_ag[$hm] ?? null,
            'ocupado' => in_array($hm, $ocupados_hoje, true),
            'passou' => ($hm < $agora),
        ];
    }
}
$slots_livres_hoje = count(array_filter($slots_dia, fn($s) => !$s['ocupado'] && !$s['passou']));

// Helper de valor de um agendamento (para a linha do tempo)
if (!function_exists('valorAgBarbeiro')) {
    function valorAgBarbeiro($ag, $servicosArr, $combosArr) {
        global $assinaturasArr, $planosArr;
        $total = 0;
        foreach (explode(',', $ag['servicos_ids'] ?? '') as $sid) {
            $sid = trim($sid);
            if (isset($servicosArr[$sid])) $total += (float)$servicosArr[$sid]['valor'];
            elseif (isset($combosArr[$sid])) $total += (float)$combosArr[$sid]['valor'];
        }
        $prods = !empty($ag['produtos_vendidos']) ? json_decode($ag['produtos_vendidos'], true) : [];
        if (is_array($prods)) $total += array_sum(array_column($prods, 'valor'));

        // Serviços cobertos pelo plano ativo do cliente são zerados na cobrança,
        // ainda que o desconto não esteja gravado no agendamento.
        $desconto = (float)($ag['desconto_aplicado'] ?? 0);
        $cid = $ag['cliente_id'] ?? '';
        if ($cid && is_array($assinaturasArr) && function_exists('descontoAssinaturaSobreItens')) {
            foreach ($assinaturasArr as $ass) {
                if ($ass['cliente_id'] == $cid && in_array($ass['status'], ['ativo', 'cancelamento_agendado'], true) && $ass['data_fim'] >= date('Y-m-d')) {
                    $planoServ = $planosArr[$ass['plano_id']]['servicos_ids'] ?? '';
                    if ($planoServ !== '') {
                        $calcVip = descontoAssinaturaSobreItens($ag['servicos_ids'] ?? '', array_filter(array_map('trim', explode(',', $planoServ))), $servicosArr, $combosArr);
                        $desconto = max($desconto, (float)$calcVip['desconto']);
                    }
                    break;
                }
            }
        }
        return max(0, $total - $desconto);
    }
}
if (!function_exists('nomesServicosBarbeiro')) {
    function nomesServicosBarbeiro($ag, $servicosArr, $combosArr) {
        $nomes = [];
        foreach (explode(',', $ag['servicos_ids'] ?? '') as $sid) {
            $sid = trim($sid);
            if (isset($servicosArr[$sid])) $nomes[] = $servicosArr[$sid]['nome'];
            elseif (isset($combosArr[$sid])) $nomes[] = $combosArr[$sid]['nome'] . ' (Combo)';
        }
        return implode(', ', $nomes);
    }
}

function renderAgendamentosTable($agendamentos, $servicosArr, $combosArr, $clientesArr, $contagem_visitas, $assinaturasArr, $tableId = '') {
    global $planosArr;
    
    if (empty($agendamentos)) {
        echo "<div class='empty-state'><i class='fa fa-calendar-times empty-state-icon'></i><p>Nenhum agendamento encontrado.</p></div>";
        return;
    }
?>
<div class="modern-table-wrapper">
    <table class="modern-table" <?= $tableId ? "id='{$tableId}'" : "" ?>>
        <thead><tr><th>Cliente</th><th>Data & Hora</th><th>Detalhes</th><th>Total</th><th>Status</th><th class="actions-cell">Ações</th></tr></thead>
        <tbody>
            <?php foreach ($agendamentos as $ag):
                $servicosIds = explode(',', $ag['servicos_ids'] ?? ''); $servicoNomes = []; $valor_total_bruto = 0;
                foreach ($servicosIds as $sid) {
                    $sid = trim($sid);
                    if (isset($servicosArr[$sid])) { $servicoNomes[] = $servicosArr[$sid]['nome']; $valor_total_bruto += (float)$servicosArr[$sid]['valor']; }
                    elseif (isset($combosArr[$sid])) { $servicoNomes[] = $combosArr[$sid]['nome'] . " (Combo)"; $valor_total_bruto += (float)$combosArr[$sid]['valor']; }
                }
                $produtos_vendidos = !empty($ag['produtos_vendidos']) ? json_decode($ag['produtos_vendidos'], true) : [];
                $valor_total_produtos = is_array($produtos_vendidos) ? array_sum(array_column($produtos_vendidos, 'valor')) : 0;
                $numero_whatsapp = preg_replace('/[^0-9]/', '', $ag['telefone'] ?? '');
                if (strlen($numero_whatsapp) == 10 || strlen($numero_whatsapp) == 11) { $numero_whatsapp = "55" . $numero_whatsapp; }

                $cid = $ag['cliente_id'] ?? '';

                $is_vip = false;
                $nomePlanoAtivo = 'Plano';
                $planoServicosVip = '';
                if ($cid) {
                    foreach($assinaturasArr as $ass) {
                        if ($ass['cliente_id'] == $cid && in_array($ass['status'], ['ativo', 'cancelamento_agendado'], true) && $ass['data_fim'] >= date('Y-m-d')) {
                            $is_vip = true;
                            if (isset($planosArr[$ass['plano_id']])) {
                                $nomePlanoAtivo = $planosArr[$ass['plano_id']]['nome'];
                                $planoServicosVip = $planosArr[$ass['plano_id']]['servicos_ids'] ?? '';
                            }
                            break;
                        }
                    }
                }
                // Zera na cobrança os serviços cobertos pelo plano ativo, mesmo que o
                // desconto não tenha sido gravado no agendamento.
                $desconto_aplicado = (float)($ag['desconto_aplicado'] ?? 0);
                if ($is_vip && $planoServicosVip !== '' && function_exists('descontoAssinaturaSobreItens')) {
                    $calcVip = descontoAssinaturaSobreItens($ag['servicos_ids'] ?? '', array_filter(array_map('trim', explode(',', $planoServicosVip))), $servicosArr, $combosArr);
                    $desconto_aplicado = max($desconto_aplicado, (float)$calcVip['desconto']);
                }
                $valor_final = max(0, $valor_total_bruto + $valor_total_produtos - $desconto_aplicado);
                $tipo_desconto = $ag['tipo_desconto'] ?? '';
                $tipo_desconto_formatado = $tipo_desconto;
                if ($tipo_desconto === 'assinatura_vip' || $tipo_desconto === 'plano' || $tipo_desconto === 'assinatura') {
                    $tipo_desconto_formatado = 'Barbearia por assinatura';
                } elseif ($tipo_desconto === 'adesao_plano') {
                    $tipo_desconto_formatado = 'Adesão de Plano';
                }
            ?>
            <tr class="t-row">
                <td data-label="Cliente" class="client-name-cell">
                    <strong class="client-name-text"><?= htmlspecialchars($ag['nome'] ?? 'Cliente') ?></strong>
                    <div class="client-phone-text"><i class="fa fa-phone"></i> <?= htmlspecialchars($ag['telefone'] ?? '') ?></div>
                </td>
                <td data-label="Data & Hora">
                    <div class="date-badge"><i class="fa fa-calendar-alt"></i> <?= htmlspecialchars(date('d/m/Y', strtotime($ag['data'] ?? ''))) ?></div>
                    <div class="time-badge"><i class="fa fa-clock"></i> <?= htmlspecialchars($ag['hora'] ?? '') ?></div>
                </td>
                <td data-label="Detalhes">
                    <div class="services-list-text"><?= htmlspecialchars(implode(', ', $servicoNomes)) ?></div>
                    <?php if (!empty($produtos_vendidos) && is_array($produtos_vendidos)): ?><div class="detalhe-pill produto-pill"><i class="fa fa-box-open"></i> Produtos: <?= count($produtos_vendidos) ?></div><?php endif; ?>
                    <?php if ($is_vip): ?>
                        <div class="detalhe-pill" style="background: #10b981; color: white; border: none; font-size: 0.75rem; padding: 3px 8px; border-radius: 6px; display: inline-block; margin-top: 4px;"><i class="fa fa-crown" title="Barbearia por assinatura"></i> Barbearia por assinatura: <?= htmlspecialchars($nomePlanoAtivo) ?></div>
                    <?php endif; ?>
                    <?php if ($desconto_aplicado > 0): ?>
                        <div class="detalhe-pill" style="background: #fef3c7; color: #d97706; border: none; font-size: 0.75rem; padding: 3px 8px; border-radius: 6px; display: inline-block; margin-top: 4px;"><i class="fa fa-tag"></i> Desc: R$ <?= number_format($desconto_aplicado, 2, ',', '.') ?> <?= $tipo_desconto_formatado ? "($tipo_desconto_formatado)" : "" ?></div>
                    <?php endif; ?>
                </td>
                <td data-label="Total"><strong class="price-text">R$ <?= number_format($valor_final, 2, ',', '.') ?></strong></td>
                <td data-label="Status"><span class="status-badge status-<?= htmlspecialchars($ag['status'] ?? '') ?>"><?= ucfirst(str_replace('_', ' ',($ag['status'] ?? ''))) ?></span></td>
                <td data-label="Ações" class="actions-cell">
                    <div class="action-buttons">
                        <?php if (($ag['status'] ?? '') === 'aprovado'): ?>
                            <button type="button" class="btn-action btn-primary-action" data-modal-target="#modal-comanda" data-id="<?= htmlspecialchars($ag['id']) ?>" title="Concluir (abrir comanda)"><i class="fa fa-check-double"></i></button>
                        <?php endif; ?>
                        <?php if(!empty($cid)): ?>
                            <button type="button" class="btn-action btn-secondary-action btn-ficha" data-modal-target="#modal-cliente-detalhes" data-cid="<?= $cid ?>" title="Detalhes do Cliente"><i class="fa fa-address-card"></i></button>
                        <?php endif; ?>
                        <a href="https://wa.me/<?= $numero_whatsapp ?>" target="_blank" class="btn-action btn-success" title="WhatsApp"><i class="fab fa-whatsapp"></i></a>
                        
                        <?php if (($ag['status'] ?? '') === 'aprovado'): ?>
                            <button type="button" class="btn-action btn-ia-action" data-modal-target="#modal-ia-whatsapp" data-nome="<?= htmlspecialchars($ag['nome'] ?? '') ?>" data-telefone="<?= $numero_whatsapp ?>" title="Assistente IA"><i class="fa fa-magic"></i></button>
                            <button type="button" class="btn-action btn-purple" data-modal-target="#modal-add-produto" data-id="<?= htmlspecialchars($ag['id']) ?>" title="Vender Produto"><i class="fa fa-shopping-bag"></i></button>
                            <button type="button" class="btn-action btn-dark-action" data-modal-target="#modal-reagendar" data-id="<?= htmlspecialchars($ag['id']) ?>" data-nome="<?= htmlspecialchars($ag['nome'] ?? '') ?>" data-servicos="<?= htmlspecialchars($ag['servicos_ids'] ?? '') ?>" data-servicos-nomes="<?= htmlspecialchars(nomesServicosBarbeiro($ag, $servicosArr, $combosArr)) ?>" data-data-fmt="<?= htmlspecialchars((!empty($ag['data']) ? date('d/m/Y', strtotime($ag['data'])) : '') . ' às ' . ($ag['hora'] ?? '')) ?>" title="Reagendar"><i class="fa fa-calendar-alt"></i></button>
                            <a href="barbeiro_actions.php?action=cancelar&id=<?= $ag['id'] ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn-action btn-danger btn-process" onclick="return confirm('Deseja realmente cancelar este agendamento?')" title="Cancelar"><i class="fa fa-times"></i></a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php }

function renderAgendamentosCards($agendamentos, $servicosArr, $combosArr, $clientesArr, $contagem_visitas, $assinaturasArr) {
    global $planosArr;

    if (empty($agendamentos)) {
        echo "<div class='empty-state'><i class='fa fa-mug-hot empty-state-icon'></i><p>Agenda livre por enquanto.</p></div>";
        return;
    }
    echo '<div class="cards-grid">';
    foreach ($agendamentos as $ag) {
        $servicosIds = explode(',', $ag['servicos_ids'] ?? ''); $servicoNomes = []; $valor_total_bruto = 0;
        foreach ($servicosIds as $sid) {
            $sid = trim($sid);
            if (isset($servicosArr[$sid])) { $servicoNomes[] = $servicosArr[$sid]['nome']; $valor_total_bruto += (float)$servicosArr[$sid]['valor']; }
            elseif (isset($combosArr[$sid])) { $servicoNomes[] = $combosArr[$sid]['nome'] . " (Combo)"; $valor_total_bruto += (float)$combosArr[$sid]['valor']; }
        }
        $produtos_vendidos = !empty($ag['produtos_vendidos']) ? json_decode($ag['produtos_vendidos'], true) : [];
        $valor_total_produtos = is_array($produtos_vendidos) ? array_sum(array_column($produtos_vendidos, 'valor')) : 0;
        $numero_whatsapp = preg_replace('/[^0-9]/', '', $ag['telefone'] ?? '');
        if (strlen($numero_whatsapp) == 10 || strlen($numero_whatsapp) == 11) { $numero_whatsapp = "55" . $numero_whatsapp; }

        $cid = $ag['cliente_id'] ?? '';

        $is_vip = false;
        $nomePlanoAtivo = 'Plano';
        $planoServicosVip = '';
        if ($cid) {
            foreach($assinaturasArr as $ass) {
                if ($ass['cliente_id'] == $cid && in_array($ass['status'], ['ativo', 'cancelamento_agendado'], true) && $ass['data_fim'] >= date('Y-m-d')) {
                    $is_vip = true;
                    if (isset($planosArr[$ass['plano_id']])) {
                        $nomePlanoAtivo = $planosArr[$ass['plano_id']]['nome'];
                        $planoServicosVip = $planosArr[$ass['plano_id']]['servicos_ids'] ?? '';
                    }
                    break;
                }
            }
        }
        $desconto_aplicado = (float)($ag['desconto_aplicado'] ?? 0);
        if ($is_vip && $planoServicosVip !== '' && function_exists('descontoAssinaturaSobreItens')) {
            $calcVip = descontoAssinaturaSobreItens($ag['servicos_ids'] ?? '', array_filter(array_map('trim', explode(',', $planoServicosVip))), $servicosArr, $combosArr);
            $desconto_aplicado = max($desconto_aplicado, (float)$calcVip['desconto']);
        }
        $valor_final = max(0, $valor_total_bruto + $valor_total_produtos - $desconto_aplicado);
        $tipo_desconto = $ag['tipo_desconto'] ?? '';
        $tipo_desconto_formatado = $tipo_desconto;
        if ($tipo_desconto === 'assinatura_vip' || $tipo_desconto === 'plano' || $tipo_desconto === 'assinatura') {
            $tipo_desconto_formatado = 'Barbearia por assinatura';
        } elseif ($tipo_desconto === 'adesao_plano') {
            $tipo_desconto_formatado = 'Adesão de Plano';
        }
        ?>
        <div class="appointment-card">
            <div class="card-header">
                <div class="time-badge"><i class="fa fa-clock"></i> <?= htmlspecialchars($ag['hora'] ?? '') ?> <span class="time-badge-date">(<?= date('d/m', strtotime($ag['data'] ?? '')) ?>)</span></div>
                <span class="status-badge status-<?= htmlspecialchars($ag['status'] ?? '') ?>"><?= ucfirst(str_replace('_', ' ',($ag['status'] ?? ''))) ?></span>
            </div>
            <div class="card-body">
                <h4 class="client-name"><?= htmlspecialchars($ag['nome'] ?? 'Cliente') ?></h4>
                <p class="client-phone"><i class="fa fa-phone"></i> <?= htmlspecialchars($ag['telefone'] ?? '') ?></p>
                <div class="services-list"><?= htmlspecialchars(implode(', ', $servicoNomes)) ?></div>
                
                <?php if ($is_vip): ?>
                    <div class="detalhe-pill" style="background: #10b981; color: white; border: none; font-size: 0.75rem; padding: 3px 8px; border-radius: 6px; display: inline-block; margin-top: 4px; margin-bottom: 4px;"><i class="fa fa-crown" title="Barbearia por assinatura"></i> Barbearia por assinatura: <?= htmlspecialchars($nomePlanoAtivo) ?></div>
                <?php endif; ?>
                <?php if ($desconto_aplicado > 0): ?>
                    <div class="detalhe-pill" style="background: #fef3c7; color: #d97706; border: none; font-size: 0.75rem; padding: 3px 8px; border-radius: 6px; display: inline-block; margin-top: 4px; margin-bottom: 4px;"><i class="fa fa-tag"></i> Desc: R$ <?= number_format($desconto_aplicado, 2, ',', '.') ?> <?= $tipo_desconto_formatado ? "($tipo_desconto_formatado)" : "" ?></div>
                <?php endif; ?>
                
                <div class="price-row"><span>Valor a cobrar:</span><strong>R$ <?= number_format($valor_final, 2, ',', '.') ?></strong></div>
            </div>
            <div class="card-footer">
                <button type="button" class="btn-action btn-primary-action" data-modal-target="#modal-comanda" data-id="<?= htmlspecialchars($ag['id']) ?>" title="Concluir (abrir comanda)"><i class="fa fa-check-double"></i></button>
                <?php if(!empty($cid)): ?>
                    <button type="button" class="btn-action btn-secondary-action btn-ficha" data-modal-target="#modal-cliente-detalhes" data-cid="<?= $cid ?>" title="Detalhes do Cliente"><i class="fa fa-address-card"></i></button>
                <?php endif; ?>
                <a href="https://wa.me/<?= $numero_whatsapp ?>" target="_blank" class="btn-action btn-success" title="WhatsApp"><i class="fab fa-whatsapp"></i></a>
                <button type="button" class="btn-action btn-ia-action" data-modal-target="#modal-ia-whatsapp" data-nome="<?= htmlspecialchars($ag['nome'] ?? '') ?>" data-telefone="<?= $numero_whatsapp ?>" title="Assistente IA"><i class="fa fa-magic"></i></button>
                <button type="button" class="btn-action btn-purple" data-modal-target="#modal-add-produto" data-id="<?= htmlspecialchars($ag['id']) ?>" title="Vender Produto"><i class="fa fa-shopping-bag"></i></button>
                <button type="button" class="btn-action btn-dark-action" data-modal-target="#modal-reagendar" data-id="<?= htmlspecialchars($ag['id']) ?>" data-nome="<?= htmlspecialchars($ag['nome'] ?? '') ?>" data-servicos="<?= htmlspecialchars($ag['servicos_ids'] ?? '') ?>" data-servicos-nomes="<?= htmlspecialchars(nomesServicosBarbeiro($ag, $servicosArr, $combosArr)) ?>" data-data-fmt="<?= htmlspecialchars((!empty($ag['data']) ? date('d/m/Y', strtotime($ag['data'])) : '') . ' às ' . ($ag['hora'] ?? '')) ?>" title="Reagendar"><i class="fa fa-calendar-alt"></i></button>
                <a href="barbeiro_actions.php?action=cancelar&id=<?= $ag['id'] ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn-action btn-danger btn-process" onclick="return confirm('Deseja realmente cancelar este agendamento?')" title="Cancelar"><i class="fa fa-times"></i></a>
            </div>
        </div>
        <?php
    }
    echo '</div>';
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Painel do Barbeiro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= assetUrl('css/style.css') ?>">
    <link rel="stylesheet" href="css/custom_theme.css.php">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= assetUrl('css/painel_barbeiro.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/notif_agendamentos.css') ?>">
    <style>
        .view-toggle { display: flex; gap: 5px; background: #f1f5f9; padding: 4px; border-radius: 8px; border: 1px solid #e2e8f0; }
        .btn-view-toggle { background: transparent; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer; color: #64748b; transition: 0.2s; font-size: 1rem; }
        .btn-view-toggle:hover { color: var(--secondary-color, #007bff); }
        .btn-view-toggle.active { background: white; color: var(--secondary-color, #007bff); box-shadow: 0 2px 4px rgba(0,0,0,0.05); }

    </style>
    <script>
        if (localStorage.getItem('theme') === 'dark') { document.documentElement.classList.add('dark-mode'); }
    </script>
</head>
<body class="barbeiro-page">

<!-- Notificações ricas de novos agendamentos (cartões flutuantes, responsivos) -->
<div id="novo-agendamento-stack" class="na-stack" aria-live="polite" aria-atomic="false"></div>
<audio id="audio-notificacao" src="https://assets.mixkit.co/sfx/preview/mixkit-software-interface-start-2574.mp3" preload="auto"></audio>

<div class="barbeiro-layout">
    <aside class="barbeiro-sidebar">
        <div class="sidebar-profile">
            <img src="<?= htmlspecialchars($foto_barbeiro) ?>" alt="Foto do Barbeiro">
            <h2><?= htmlspecialchars($barbeiro_atual['nome'] ?? 'Barbeiro') ?></h2>
            <span class="shop-name"><?= htmlspecialchars($configGeral['nome_barbearia'] ?? 'Painel Premium') ?></span>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-group-title">Menu Principal</div>
            <a href="#" class="sidebar-btn active" data-tab="inicio"><i class="fa fa-home"></i> <span>Dashboard</span></a>
            <a href="#" class="sidebar-btn" data-tab="agenda"><i class="fa fa-calendar-day"></i> <span>Minha Agenda</span></a>
            <a href="#" class="sidebar-btn d-desktop-only" data-tab="historico"><i class="fa fa-history"></i> <span>Histórico</span></a>
            <a href="#" class="sidebar-btn" data-tab="financeiro"><i class="fa fa-wallet"></i> <span>Meus Ganhos</span></a>
            
            <div class="nav-group-title" style="margin-top: 15px;">Configurações</div>
            <a href="#" class="sidebar-btn" data-modal-target="#modal-almoco"><i class="fa fa-hamburger"></i> <span>Pausa de Almoço</span></a>
            
            <a href="#" class="sidebar-btn" data-tab="perfil"><i class="fa fa-user-circle"></i> <span>Meu Perfil</span></a>
            
            <a href="#" data-modal-target="#modal-agendamento-manual" class="sidebar-btn btn-new-appointment d-desktop-only" style="margin-top: auto; margin-bottom: 10px;"><i class="fa fa-plus"></i> <span>Novo Agendamento</span></a>
            <a href="login.php?logout=1&token=<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>" id="btn-logout-barbeiro" class="sidebar-btn btn-danger"><i class="fa fa-sign-out-alt"></i> <span>Sair do Sistema</span></a>
        </nav>
    </aside>

    <main class="barbeiro-main">
        <header class="barbeiro-topbar">
            <div class="topbar-date">
                <div class="date-icon-bg"><i class="fa fa-calendar-day"></i></div>
                <span>Hoje, <?= date('d/m/Y') ?></span>
            </div>
            <div class="topbar-actions">
                <button id="btn-dark-mode" class="btn-icon-topbar" title="Alternar Tema Escuro"><i class="fa fa-moon"></i></button>
                <a href="#" data-modal-target="#modal-agendamento-manual" class="btn-primary d-mobile-only" style="margin:0; padding: 10px; border-radius: 50%; width: 42px; height: 42px; display: flex; align-items: center; justify-content: center;"><i class="fa fa-plus"></i></a>
                <a href="barbeiro.php" class="btn-secondary-solid"><i class="fa fa-sync-alt"></i> <span class="d-desktop-only">Atualizar</span></a>
            </div>
        </header>

        <div class="barbeiro-content">
            <?php if ($mensagem_sucesso): ?><div class="mensagem-saas success"><i class="fa fa-check-circle"></i> <?= $mensagem_sucesso ?></div><?php endif; ?>
            <?php if ($mensagem_erro): ?><div class="mensagem-saas error"><i class="fa fa-exclamation-triangle"></i> <?= $mensagem_erro ?></div><?php endif; ?>

            <div id="tab-inicio" class="tab-pane active">
                <div class="dash-greeting">
                    <div>
                        <h1 class="dash-hello">Olá, <?= htmlspecialchars(explode(' ', trim($barbeiro_atual['nome'] ?? 'Barbeiro'))[0]) ?> 👋</h1>
                        <p class="dash-sub"><?= $total_agendado_hoje > 0
                            ? "Você tem <strong>{$total_agendado_hoje}</strong> atendimento(s) hoje — <strong>{$qtd_restantes_hoje}</strong> ainda por fazer."
                            : "Nenhum atendimento marcado para hoje. Bom descanso!" ?></p>
                    </div>
                </div>

                <!-- KPIs do dia -->
                <div class="kpi-row">
                    <div class="kpi-tile">
                        <div class="kpi-tile-icon indigo"><i class="fa fa-calendar-check"></i></div>
                        <div class="kpi-tile-body">
                            <span class="kpi-tile-label">Atendimentos hoje</span>
                            <span class="kpi-tile-value"><?= $total_agendado_hoje ?></span>
                            <span class="kpi-tile-foot"><?= $qtd_concluidos_hoje ?> concluído(s) · <?= $qtd_restantes_hoje ?> restante(s)</span>
                        </div>
                    </div>
                    <div class="kpi-tile">
                        <div class="kpi-tile-icon green"><i class="fa fa-wallet"></i></div>
                        <div class="kpi-tile-body">
                            <span class="kpi-tile-label">Ganho líquido hoje</span>
                            <span class="kpi-tile-value">R$ <?= number_format($rec_hoje['liquido'], 2, ',', '.') ?></span>
                            <span class="kpi-tile-foot">Bruto: R$ <?= number_format($rec_hoje['bruto'], 2, ',', '.') ?></span>
                        </div>
                    </div>
                    <div class="kpi-tile">
                        <div class="kpi-tile-icon amber"><i class="fa fa-receipt"></i></div>
                        <div class="kpi-tile-body">
                            <span class="kpi-tile-label">Ticket médio hoje</span>
                            <span class="kpi-tile-value">R$ <?= number_format($ticket_medio_hoje, 2, ',', '.') ?></span>
                            <span class="kpi-tile-foot">Por atendimento concluído</span>
                        </div>
                    </div>
                    <div class="kpi-tile">
                        <div class="kpi-tile-icon blue"><i class="fa fa-star"></i></div>
                        <div class="kpi-tile-body">
                            <span class="kpi-tile-label">Avaliação média</span>
                            <span class="kpi-tile-value"><?= $media_avaliacoes ?><?= $media_avaliacoes !== 'N/A' ? ' <i class="fa fa-star" style="font-size:0.9rem;color:#f59e0b;"></i>' : '' ?></span>
                            <span class="kpi-tile-foot"><?= $qtd_avaliacoes ?> avaliação(ões) · <?= $taxa_comparecimento ?>% comparecimento</span>
                        </div>
                    </div>
                </div>

                <div class="goal-container">
                    <div class="goal-header">
                        <span class="goal-title"><i class="fa fa-bullseye"></i> Meta Diária: R$ <?= number_format($meta_diaria, 2, ',', '.') ?></span>
                        <span class="goal-percentage"><?= $percentual_meta ?>%</span>
                    </div>
                    <div class="progress-bar-bg"><div class="progress-bar-fill" style="width: <?= $percentual_meta ?>%"></div></div>
                    <div class="goal-footer">
                        <?php if ($percentual_meta >= 100): ?>
                            🎉 Meta batida! Você já faturou <strong>R$ <?= number_format($rec_hoje['liquido'], 2, ',', '.') ?></strong> líquidos hoje.
                        <?php else: ?>
                            Faltam <strong>R$ <?= number_format(max(0, $meta_diaria - $rec_hoje['liquido']), 2, ',', '.') ?></strong> para bater a meta de hoje.
                        <?php endif; ?>
                    </div>
                </div>

                <div class="dash-columns">
                    <div class="dash-col-main">
                        <?php if($proximoCliente):
                            $num_w = preg_replace('/[^0-9]/', '', $proximoCliente['telefone'] ?? ''); if (strlen($num_w) == 10 || strlen($num_w) == 11) $num_w = "55" . $num_w;
                            $serv_n = nomesServicosBarbeiro($proximoCliente, $servicosArr, $combosArr);
                        ?>
                        <div class="next-client-banner">
                            <div class="next-client-bg-glow"></div>
                            <div class="next-client-info">
                                <span class="next-client-label"><i class="fa fa-hourglass-half"></i> Próximo Atendimento</span>
                                <h2 class="next-client-name"><?= htmlspecialchars($proximoCliente['nome'] ?? '') ?></h2>
                                <div class="next-client-time"><i class="fa fa-clock"></i> <?= htmlspecialchars(substr($proximoCliente['hora'] ?? '', 0, 5)) ?></div>
                                <div class="next-client-services"><?= htmlspecialchars($serv_n) ?></div>
                            </div>
                            <div class="next-client-actions">
                                <a href="https://wa.me/<?= $num_w ?>" target="_blank" class="btn-next-action btn-next-whatsapp"><i class="fab fa-whatsapp"></i> Chamar no Zap</a>
                                <button type="button" class="btn-next-action btn-next-primary" data-modal-target="#modal-comanda" data-id="<?= htmlspecialchars($proximoCliente['id']) ?>"><i class="fa fa-check-double"></i> Finalizar Corte</button>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="empty-state">
                            <i class="fa fa-check-circle empty-state-icon" style="color: #10b981;"></i>
                            <h3>Tudo limpo por agora!</h3>
                            <p>Você não possui atendimentos pendentes neste momento.</p>
                        </div>
                        <?php endif; ?>

                        <!-- Linha do tempo do dia -->
                        <div class="section-header" style="margin-top: 32px;">
                            <div class="section-title"><i class="fa fa-stream"></i> Linha do tempo de hoje</div>
                        </div>
                        <?php if (empty($timeline_hoje)): ?>
                            <div class="empty-state"><i class="fa fa-mug-hot empty-state-icon"></i><p>Nenhum horário marcado para hoje.</p></div>
                        <?php else: ?>
                            <div class="timeline">
                                <?php foreach ($timeline_hoje as $ag):
                                    $t_serv = nomesServicosBarbeiro($ag, $servicosArr, $combosArr);
                                    $t_valor = valorAgBarbeiro($ag, $servicosArr, $combosArr);
                                    $t_hora = substr($ag['hora'] ?? '', 0, 5);
                                    $t_concluido = ($ag['status'] ?? '') === 'concluido';
                                    $t_agora = (!$t_concluido && $t_hora >= $agora);
                                    $t_num = preg_replace('/[^0-9]/', '', $ag['telefone'] ?? ''); if (strlen($t_num) == 10 || strlen($t_num) == 11) $t_num = "55" . $t_num;
                                ?>
                                <div class="timeline-item <?= $t_concluido ? 'is-done' : ($t_agora ? 'is-next' : 'is-pending') ?>">
                                    <div class="timeline-time"><?= $t_hora ?></div>
                                    <div class="timeline-marker"></div>
                                    <div class="timeline-card">
                                        <div class="timeline-card-main">
                                            <strong><?= htmlspecialchars($ag['nome'] ?? 'Cliente') ?></strong>
                                            <span><?= htmlspecialchars($t_serv) ?></span>
                                        </div>
                                        <div class="timeline-card-side">
                                            <span class="timeline-price">R$ <?= number_format($t_valor, 2, ',', '.') ?></span>
                                            <?php if ($t_concluido): ?>
                                                <span class="status-badge status-concluido">Concluído</span>
                                            <?php else: ?>
                                                <div class="timeline-actions">
                                                    <a href="https://wa.me/<?= $t_num ?>" target="_blank" class="btn-action btn-success" title="WhatsApp"><i class="fab fa-whatsapp"></i></a>
                                                    <button type="button" class="btn-action btn-primary-action" data-modal-target="#modal-comanda" data-id="<?= htmlspecialchars($ag['id']) ?>" title="Concluir (abrir comanda)"><i class="fa fa-check-double"></i></button>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="dash-col-side">
                        <!-- Mini gráfico 7 dias -->
                        <div class="mini-chart-card">
                            <div class="mini-chart-head">
                                <span class="mini-chart-title"><i class="fa fa-chart-column"></i> Últimos 7 dias</span>
                                <span class="mini-chart-total">R$ <?= number_format($total_7dias_liquido, 2, ',', '.') ?></span>
                            </div>
                            <div class="mini-chart-bars">
                                <?php foreach ($grafico_7dias as $g):
                                    $altura = $max_7dias > 0 ? max(4, round(($g['liquido'] / $max_7dias) * 100)) : 4;
                                ?>
                                <div class="mini-bar-col <?= $g['is_hoje'] ? 'is-hoje' : '' ?>" title="<?= $g['label'] ?>: R$ <?= number_format($g['liquido'], 2, ',', '.') ?> (<?= $g['qtd'] ?> atend.)">
                                    <div class="mini-bar-track">
                                        <div class="mini-bar-fill" style="height: <?= $altura ?>%"></div>
                                    </div>
                                    <span class="mini-bar-label"><?= $g['dia_semana'] ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Resumo rápido -->
                        <div class="quick-summary">
                            <div class="quick-summary-item">
                                <span class="qs-icon blue"><i class="fa fa-calendar-week"></i></span>
                                <div><span class="qs-label">Clientes na semana</span><strong class="qs-value"><?= $qtd_clientes_semana ?></strong></div>
                            </div>
                            <div class="quick-summary-item">
                                <span class="qs-icon green"><i class="fa fa-sack-dollar"></i></span>
                                <div><span class="qs-label">Líquido na semana</span><strong class="qs-value">R$ <?= number_format($rec_semana['liquido'], 2, ',', '.') ?></strong></div>
                            </div>
                            <div class="quick-summary-item">
                                <span class="qs-icon amber"><i class="fa fa-clock"></i></span>
                                <div><span class="qs-label">Horários livres hoje</span><strong class="qs-value"><?= $slots_livres_hoje ?></strong></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


            <div id="tab-agenda" class="tab-pane">
                <!-- Resumo do dia -->
                <div class="agenda-daybar">
                    <div class="agenda-daybar-item">
                        <span class="agenda-daybar-value"><?= $qtd_restantes_hoje ?></span>
                        <span class="agenda-daybar-label">A atender hoje</span>
                    </div>
                    <div class="agenda-daybar-item">
                        <span class="agenda-daybar-value"><?= $qtd_concluidos_hoje ?></span>
                        <span class="agenda-daybar-label">Concluídos</span>
                    </div>
                    <div class="agenda-daybar-item">
                        <span class="agenda-daybar-value"><?= $slots_livres_hoje ?></span>
                        <span class="agenda-daybar-label">Horários livres</span>
                    </div>
                    <div class="agenda-daybar-item">
                        <span class="agenda-daybar-value">R$ <?= number_format($rec_hoje['bruto'], 0, ',', '.') ?></span>
                        <span class="agenda-daybar-label">Faturamento bruto</span>
                    </div>
                </div>

                <div class="section-header flex-between">
                    <div class="section-title"><i class="fa fa-calendar-check"></i> Agenda de Hoje</div>
                    <div class="view-toggle">
                        <button type="button" class="btn-view-toggle active" data-agenda-view="timeline" title="Ver em Linha do Tempo"><i class="fa fa-stream"></i></button>
                        <button type="button" class="btn-view-toggle" data-agenda-view="lista" title="Ver em Lista"><i class="fa fa-list"></i></button>
                        <button type="button" class="btn-view-toggle" data-agenda-view="cards" title="Ver em Cartões"><i class="fa fa-th-large"></i></button>
                    </div>
                </div>

                <!-- Vista Linha do Tempo (grade do expediente com horários livres) -->
                <div class="hoje-view hoje-view-timeline">
                    <?php if (empty($slots_dia)): ?>
                        <div class="empty-state"><i class="fa fa-calendar-xmark empty-state-icon"></i><p>Sem expediente cadastrado para hoje. Fale com o gestor para configurar sua grade de horários.</p></div>
                    <?php else: ?>
                        <div class="day-grid">
                            <?php
                            $ja_desenhados = [];
                            foreach ($slots_dia as $slot):
                                $ag = $slot['ag'];
                                // Se um agendamento longo já ocupou este horário mas começou antes, marca como continuação
                                if ($ag):
                                    $g_serv = nomesServicosBarbeiro($ag, $servicosArr, $combosArr);
                                    $g_valor = valorAgBarbeiro($ag, $servicosArr, $combosArr);
                                    $g_concluido = ($ag['status'] ?? '') === 'concluido';
                                    $g_num = preg_replace('/[^0-9]/', '', $ag['telefone'] ?? ''); if (strlen($g_num) == 10 || strlen($g_num) == 11) $g_num = "55" . $g_num;
                            ?>
                                <div class="day-slot occupied <?= $g_concluido ? 'done' : '' ?>">
                                    <div class="day-slot-time"><?= $slot['hora'] ?></div>
                                    <div class="day-slot-content">
                                        <div class="day-slot-client">
                                            <strong><?= htmlspecialchars($ag['nome'] ?? 'Cliente') ?></strong>
                                            <span><?= htmlspecialchars($g_serv) ?></span>
                                        </div>
                                        <div class="day-slot-meta">
                                            <span class="day-slot-price">R$ <?= number_format($g_valor, 2, ',', '.') ?></span>
                                            <?php if ($g_concluido): ?>
                                                <span class="status-badge status-concluido">Concluído</span>
                                            <?php else: ?>
                                                <div class="day-slot-actions">
                                                    <a href="https://wa.me/<?= $g_num ?>" target="_blank" class="btn-action btn-success" title="WhatsApp"><i class="fab fa-whatsapp"></i></a>
                                                    <button type="button" class="btn-action btn-primary-action" data-modal-target="#modal-comanda" data-id="<?= htmlspecialchars($ag['id']) ?>" title="Concluir (abrir comanda)"><i class="fa fa-check-double"></i></button>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php elseif ($slot['ocupado']): ?>
                                <div class="day-slot busy">
                                    <div class="day-slot-time"><?= $slot['hora'] ?></div>
                                    <div class="day-slot-content"><span class="day-slot-busy-label"><i class="fa fa-lock"></i> Ocupado</span></div>
                                </div>
                            <?php else: ?>
                                <div class="day-slot free <?= $slot['passou'] ? 'past' : '' ?>">
                                    <div class="day-slot-time"><?= $slot['hora'] ?></div>
                                    <div class="day-slot-content">
                                        <span class="day-slot-free-label"><?= $slot['passou'] ? 'Vago (passou)' : 'Livre' ?></span>
                                        <?php if (!$slot['passou']): ?>
                                            <a href="#" data-modal-target="#modal-agendamento-manual" data-date="<?= htmlspecialchars($hoje) ?>" data-hora="<?= htmlspecialchars($slot['hora']) ?>" class="day-slot-add" title="Encaixar cliente"><i class="fa fa-plus"></i></a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="hoje-view hoje-view-lista" style="display: none;">
                    <?php renderAgendamentosTable($agendamentosHoje, $servicosArr, $combosArr, $clientesArr, $contagem_visitas, $assinaturasArr, 'tabela-agenda-hoje'); ?>
                </div>
                <div class="hoje-view hoje-view-cards" style="display: none;">
                    <?php renderAgendamentosCards($agendamentosHoje, $servicosArr, $combosArr, $clientesArr, $contagem_visitas, $assinaturasArr); ?>
                </div>

                <div class="section-header" style="margin-top: 40px;">
                    <div class="section-title"><i class="fa fa-calendar-day"></i> Próximos Dias</div>
                </div>

                <?php renderAgendamentosTable($agendamentosFuturos, $servicosArr, $combosArr, $clientesArr, $contagem_visitas, $assinaturasArr, 'tabela-agenda-futuro'); ?>
            </div>

            <div id="tab-historico" class="tab-pane">
                <div class="section-header flex-between">
                    <div class="section-title"><i class="fa fa-history"></i> Histórico Completo</div>
                    <div class="search-wrapper">
                        <i class="fa fa-search search-icon"></i>
                        <input type="text" id="search-historico" class="modern-input" placeholder="Pesquisar cliente..." style="padding-left: 40px; border-radius: 30px;">
                    </div>
                </div>
                <?php renderAgendamentosTable($agendamentosPaginados, $servicosArr, $combosArr, $clientesArr, $contagem_visitas, $assinaturasArr, 'tabela-historico'); ?>
                <?php if ($totalPages > 1): ?>
                <div class="pagination"><?php for ($i = 1; $i <= $totalPages; $i++) echo "<a href='?page=$i&tab=historico' class='".($i==$currentPage?'active':'')."'>$i</a>"; ?></div>
                <?php endif; ?>
            </div>

            <div id="tab-financeiro" class="tab-pane">
                <!-- Destaque do mês -->
                <div class="fin-hero">
                    <div class="fin-hero-glow"></div>
                    <div class="fin-hero-info">
                        <span class="fin-hero-label"><i class="fa fa-sack-dollar"></i> Comissão líquida neste mês</span>
                        <span class="fin-hero-value">R$ <?= number_format($rec_mes['liquido'], 2, ',', '.') ?></span>
                        <span class="fin-hero-foot"><?= $qtd_clientes_mes ?> atendimento(s) concluído(s) · Faturamento bruto R$ <?= number_format($rec_mes['bruto'], 2, ',', '.') ?></span>
                    </div>
                </div>

                <div class="section-header">
                    <div class="section-title"><i class="fa fa-wallet"></i> Ganhos por período (líquido)</div>
                </div>
                <div class="kpi-row">
                    <div class="kpi-tile">
                        <div class="kpi-tile-icon green"><i class="fa fa-calendar-day"></i></div>
                        <div class="kpi-tile-body">
                            <span class="kpi-tile-label">Hoje</span>
                            <span class="kpi-tile-value">R$ <?= number_format($rec_hoje['liquido'], 2, ',', '.') ?></span>
                            <span class="kpi-tile-foot"><?= $qtd_concluidos_hoje ?> atendimento(s)</span>
                        </div>
                    </div>
                    <div class="kpi-tile">
                        <div class="kpi-tile-icon blue"><i class="fa fa-calendar-week"></i></div>
                        <div class="kpi-tile-body">
                            <span class="kpi-tile-label">Nesta semana</span>
                            <span class="kpi-tile-value">R$ <?= number_format($rec_semana['liquido'], 2, ',', '.') ?></span>
                            <span class="kpi-tile-foot"><?= $qtd_clientes_semana ?> atendimento(s)</span>
                        </div>
                    </div>
                    <div class="kpi-tile">
                        <div class="kpi-tile-icon indigo"><i class="fa fa-calendar"></i></div>
                        <div class="kpi-tile-body">
                            <span class="kpi-tile-label">Neste mês</span>
                            <span class="kpi-tile-value">R$ <?= number_format($rec_mes['liquido'], 2, ',', '.') ?></span>
                            <span class="kpi-tile-foot"><?= $qtd_clientes_mes ?> atendimento(s)</span>
                        </div>
                    </div>
                </div>

                <!-- Gráfico dos últimos 7 dias (comissão líquida) -->
                <div class="section-header" style="margin-top: 8px;">
                    <div class="section-title"><i class="fa fa-chart-column"></i> Comissão líquida — últimos 7 dias</div>
                </div>
                <div class="fin-chart-card">
                    <div class="fin-chart">
                        <?php foreach ($grafico_7dias as $g):
                            $altura = $max_7dias > 0 ? max(3, round(($g['liquido'] / $max_7dias) * 100)) : 3;
                        ?>
                        <div class="fin-chart-col <?= $g['is_hoje'] ? 'is-hoje' : '' ?>">
                            <span class="fin-chart-val">R$ <?= number_format($g['liquido'], 0, ',', '.') ?></span>
                            <div class="fin-chart-track"><div class="fin-chart-fill" style="height: <?= $altura ?>%"></div></div>
                            <span class="fin-chart-day"><?= $g['dia_semana'] ?></span>
                            <span class="fin-chart-date"><?= $g['label'] ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Faturamento bruto (dinheiro que entrou para a barbearia) -->
                <div class="section-header" style="margin-top: 32px;">
                    <div class="section-title"><i class="fa fa-store"></i> Faturamento bruto (entrou no caixa)</div>
                </div>
                <div class="kpi-row">
                    <div class="kpi-tile">
                        <div class="kpi-tile-icon amber"><i class="fa fa-cash-register"></i></div>
                        <div class="kpi-tile-body">
                            <span class="kpi-tile-label">Bruto hoje</span>
                            <span class="kpi-tile-value">R$ <?= number_format($rec_hoje['bruto'], 2, ',', '.') ?></span>
                        </div>
                    </div>
                    <div class="kpi-tile">
                        <div class="kpi-tile-icon amber"><i class="fa fa-cash-register"></i></div>
                        <div class="kpi-tile-body">
                            <span class="kpi-tile-label">Bruto na semana</span>
                            <span class="kpi-tile-value">R$ <?= number_format($rec_semana['bruto'], 2, ',', '.') ?></span>
                        </div>
                    </div>
                    <div class="kpi-tile">
                        <div class="kpi-tile-icon amber"><i class="fa fa-cash-register"></i></div>
                        <div class="kpi-tile-body">
                            <span class="kpi-tile-label">Bruto no mês</span>
                            <span class="kpi-tile-value">R$ <?= number_format($rec_mes['bruto'], 2, ',', '.') ?></span>
                        </div>
                    </div>
                </div>

                <p class="fin-note"><i class="fa fa-circle-info"></i> "Líquido" é a sua comissão sobre os atendimentos; "bruto" é o total cobrado do cliente. As taxas de comissão são definidas pelo gestor.</p>
            </div>

            <div id="tab-perfil" class="tab-pane">
                <div class="saas-panel">
                    <div class="saas-panel-header">
                        <h3 class="saas-panel-title"><i class="fa fa-user-edit"></i> Editar Meu Perfil</h3>
                        <p class="saas-panel-subtitle">Mantenha seus dados de acesso sempre atualizados.</p>
                    </div>
                    
                    <form method="post" action="barbeiro_actions.php" enctype="multipart/form-data" class="saas-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="action" value="atualizar_perfil">
                        
                        <div class="profile-photo-uploader">
                            <img src="<?= htmlspecialchars($foto_barbeiro) ?>" id="preview-foto" class="profile-photo-preview">
                            <div class="uploader-actions">
                                <label for="foto_perfil" class="btn-secondary-solid"><i class="fa fa-camera"></i> Escolher Nova Foto</label>
                                <input type="file" id="foto_perfil" name="foto" accept="image/*" style="display:none;" onchange="document.getElementById('preview-foto').src = window.URL.createObjectURL(this.files[0])">
                                <span class="uploader-hint">Recomendado: Imagem quadrada, máx. 2MB.</span>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="modern-label">Nome Completo</label>
                                <input type="text" name="nome" value="<?= htmlspecialchars($barbeiro_atual['nome'] ?? '') ?>" required class="modern-input">
                            </div>
                            <div class="form-group">
                                <label class="modern-label">Nova Senha (Deixe em branco para manter)</label>
                                <input type="password" name="senha" class="modern-input" placeholder="Digite a nova senha">
                            </div>
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label class="modern-label">Meta de Ganho Diário (Líquido) - R$</label>
                                <input type="number" step="0.01" name="meta_diaria" value="<?= htmlspecialchars($barbeiro_atual['meta_diaria'] ?? 200) ?>" class="modern-input" placeholder="Ex: 200.00">
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn-primary"><i class="fa fa-save"></i> Salvar Alterações de Perfil</button>
                        </div>
                    </form>

                    <div class="readonly-metrics-box">
                        <h4 class="metrics-box-title"><i class="fa fa-info-circle"></i> Sua Taxa de Comissão</h4>
                        <div class="form-grid" style="margin-top: 15px;">
                            <div class="form-group">
                                <label class="modern-label">Sua Comissão (%)</label>
                                <input type="text" value="<?= htmlspecialchars($barbeiro_atual['comissao'] ?? 50) ?>%" class="modern-input readonly-input" readonly>
                            </div>
                            <div class="form-group">
                                <label class="modern-label">Atendimentos de Assinatura</label>
                                <div class="modern-input readonly-input commission-rule-display"><?= htmlspecialchars($descricao_comissao_assinatura) ?></div>
                            </div>
                        </div>
                        <p class="readonly-hint"><i class="fa fa-lock"></i> Este valor está bloqueado. Apenas o gestor da barbearia pode alterar as taxas de comissão.</p>
                    </div>
                </div>
            </div>
            
        </div>
    </main>
</div>

<?php require_once 'barbeiro_modals.php'; ?>

<?php include __DIR__ . '/partials/painel_barbeiro_script1.php'; ?>
<script src="<?= assetUrl('js/admin_detalhes.js') ?>"></script>

<?php include __DIR__ . '/partials/painel_barbeiro_script2.php'; ?>
<script src="<?= assetUrl('js/painel_barbeiro.js') ?>"></script>

<!-- Notificações de novos agendamentos (motor compartilhado com o painel admin) -->
<script>
    window.NA_CONFIG = { isAdmin: false, barbeiroId: "<?= htmlspecialchars($barbeiro_id, ENT_QUOTES) ?>", endpoint: 'check_new_appointments.php', pollMs: 15000 };
</script>
<script src="<?= assetUrl('js/notif_agendamentos.js') ?>"></script>
</body>
</html>
