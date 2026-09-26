<?php
// ajax_comanda.php — dados da comanda de um atendimento (uso do painel ADMIN).
require_once 'functions.php';
iniciarSessaoSegura();
header('Content-Type: application/json');

// Apenas administradores autenticados.
if (!isset($_SESSION['loggedin'])) {
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

$id = $_GET['id'] ?? '';
if ($id === '') {
    echo json_encode(['success' => false, 'message' => 'Atendimento inválido.']);
    exit;
}

if (function_exists('garantirColunasComanda')) garantirColunasComanda();

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM agendamentos WHERE id = ?");
$stmt->execute([$id]);
$ag = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$ag) { echo json_encode(['success' => false, 'message' => 'Atendimento não encontrado.']); exit; }

$servicosArr = lerDados('servicos', ['id', 'nome', 'valor']);
$combosArr   = lerDados('combos', ['id', 'nome', 'valor']);

$itensServicos = [];
$subtotalServicos = 0;
foreach (array_filter(array_map('trim', explode(',', (string)($ag['servicos_ids'] ?? '')))) as $sid) {
    if (isset($servicosArr[$sid])) {
        $v = (float)$servicosArr[$sid]['valor'];
        $itensServicos[] = ['nome' => $servicosArr[$sid]['nome'], 'valor' => $v];
        $subtotalServicos += $v;
    } elseif (isset($combosArr[$sid])) {
        $v = (float)$combosArr[$sid]['valor'];
        $itensServicos[] = ['nome' => $combosArr[$sid]['nome'] . ' (Combo)', 'valor' => $v];
        $subtotalServicos += $v;
    }
}

$itensProdutos = [];
$subtotalProdutos = 0;
$prodJson = json_decode((string)($ag['produtos_vendidos'] ?? ''), true);
if (is_array($prodJson)) {
    foreach ($prodJson as $p) {
        $v = (float)($p['valor'] ?? 0);
        $itensProdutos[] = ['nome' => $p['nome'] ?? 'Produto', 'valor' => $v];
        $subtotalProdutos += $v;
    }
}

// Extras: serviços que o barbeiro do atendimento realiza (fallback: todos).
$servicosDisponiveis = [];
$espec = '';
if (!empty($ag['barbeiro_id'])) {
    $stmtB = $pdo->prepare("SELECT servicos_ids FROM barbeiros WHERE id = ?");
    $stmtB->execute([$ag['barbeiro_id']]);
    $espec = (string)($stmtB->fetchColumn() ?: '');
}
$idsEspec = array_filter(array_map('trim', explode(',', $espec)));
if (empty($idsEspec)) {
    $idsEspec = array_keys($servicosArr);
}
foreach ($idsEspec as $sid) {
    if (isset($servicosArr[$sid])) {
        $servicosDisponiveis[] = ['id' => $sid, 'nome' => $servicosArr[$sid]['nome'], 'valor' => (float)$servicosArr[$sid]['valor']];
    }
}

// Benefício de assinatura recalculado ao vivo: zera serviços cobertos pelo
// plano ativo mesmo que o desconto não tenha ficado gravado no agendamento.
$assinaturaCalc = calcularDescontoAssinaturaCliente($ag['cliente_id'] ?? '', (string)($ag['servicos_ids'] ?? ''));
$descontoGravado = max(0, (float)($ag['desconto_aplicado'] ?? 0));
$desconto = max($descontoGravado, (float)$assinaturaCalc['desconto']);

echo json_encode([
    'success' => true,
    'id' => $ag['id'],
    'cliente' => $ag['nome'] ?? 'Cliente',
    'hora' => $ag['hora'] ?? '',
    'servicos' => $itensServicos,
    'produtos' => $itensProdutos,
    'subtotal_servicos' => $subtotalServicos,
    'subtotal_produtos' => $subtotalProdutos,
    'desconto' => $desconto,
    'plano_nome' => $assinaturaCalc['plano_nome'],
    'gorjeta' => (float)($ag['gorjeta'] ?? 0),
    'forma_pagamento' => $ag['forma_pagamento'] ?? '',
    'servicos_disponiveis' => $servicosDisponiveis,
], JSON_UNESCAPED_UNICODE);
exit;
