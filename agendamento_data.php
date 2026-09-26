<?php
// Define o fuso horário no início do script para garantir consistência nas datas
date_default_timezone_set('America/Sao_Paulo');

// Função para definir ícones baseados no nome do serviço
function obterIconeServico($nomeServico) {
    $nome = mb_strtolower($nomeServico, 'UTF-8');
    if (strpos($nome, 'barba') !== false || strpos($nome, 'barboterapia') !== false) return 'fa-user-tie'; 
    if (strpos($nome, 'corte') !== false || strpos($nome, 'cabelo') !== false || strpos($nome, 'degradê') !== false || strpos($nome, 'degrade') !== false || strpos($nome, 'máquina') !== false || strpos($nome, 'tesoura') !== false || strpos($nome, 'social') !== false) return 'fa-scissors';
    if (strpos($nome, 'sobrancelha') !== false) return 'fa-eye';
    if (strpos($nome, 'pezinho') !== false || strpos($nome, 'acabamento') !== false || strpos($nome, 'risco') !== false || strpos($nome, 'listra') !== false) return 'fa-grip-lines';
    if (strpos($nome, 'luzes') !== false || strpos($nome, 'colorimetria') !== false || strpos($nome, 'pigmentação') !== false || strpos($nome, 'platinado') !== false || strpos($nome, 'tintura') !== false || strpos($nome, 'coloração') !== false) return 'fa-palette';
    if (strpos($nome, 'progressiva') !== false || strpos($nome, 'relaxamento') !== false || strpos($nome, 'química') !== false || strpos($nome, 'alisamento') !== false || strpos($nome, 'selagem') !== false) return 'fa-flask';
    if (strpos($nome, 'limpeza') !== false || strpos($nome, 'pele') !== false || strpos($nome, 'máscara') !== false || strpos($nome, 'mascara') !== false || strpos($nome, 'spa') !== false || strpos($nome, 'hidratacao') !== false || strpos($nome, 'hidratação') !== false) return 'fa-spa';
    if (strpos($nome, 'infantil') !== false || strpos($nome, 'kids') !== false || strpos($nome, 'criança') !== false) return 'fa-child';
    if (strpos($nome, 'combo') !== false || strpos($nome, '+') !== false || strpos($nome, 'pacote') !== false) return 'fa-star';
    return 'fa-cut';
}

// Geração de Token CSRF para segurança do formulário
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (isset($_GET['stripe_success']) && isset($_GET['ag_id'])) {
    $_SESSION['mensagem_sucesso'] = "Quase lá! O seu pagamento está sendo processado de forma segura e a sua assinatura será ativada automaticamente em instantes.";
}

if (isset($_GET['erro_stripe'])) {
    // A assinatura é 100% online. Quando o checkout é cancelado/abortado, o
    // agendamento que ficou "aguardando_pagamento" é descartado (libera o
    // horário e não gera pendências para acertar presencialmente). A mensagem
    // ao cliente é montada em agendamento.php.
    unset($_SESSION['mensagem_sucesso']);
    $ag_id_cancelado = preg_replace('/[^A-Z0-9\-]/i', '', $_GET['ag_id'] ?? '');
    if ($ag_id_cancelado !== '' && !empty($_SESSION['cliente_id'])) {
        try {
            $pdoCancel = getDB();
            $stmtCancel = $pdoCancel->prepare("DELETE FROM agendamentos WHERE id = ? AND cliente_id = ? AND status = 'aguardando_pagamento'");
            $stmtCancel->execute([$ag_id_cancelado, $_SESSION['cliente_id']]);
        } catch (Exception $e) {
            if (function_exists('log_activity')) log_activity('Falha ao limpar agendamento pendente apos cancelamento Stripe: ' . $e->getMessage());
        }
    }
}

if (isset($_GET['reagendar_id'])) {
    $_SESSION['reagendar_id'] = $_GET['reagendar_id'];
}

$cliente_logado = isset($_SESSION['cliente_logado']) && $_SESSION['cliente_logado'];
$form_disabled_class = $cliente_logado ? '' : 'form-disabled';
$input_disabled_attr = $cliente_logado ? '' : 'disabled';

$configGeral = carregarConfigGeral();
$configAgendamento = carregarConfigAgendamento();

// Carrega a cor de destaque (accent) configurada no painel
$themeConfig = function_exists('_lerConfigSQLite') ? _lerConfigSQLite('theme_config', []) : [];
$app_accent = $themeConfig['secondary_color'] ?? '#f59e0b';

$keys_agendamentos_completo = ['id', 'nome', 'email', 'telefone', 'barbeiro_id', 'servicos_ids', 'data', 'hora', 'status', 'desconto_aplicado', 'tipo_desconto', 'observacoes', 'produtos_vendidos', 'plano_provisorio', 'cliente_id', 'data_criacao'];

$barbeirosArr = lerDados('barbeiros', ['id', 'nome', 'foto', 'username', 'status', 'servicos_ids']);
$barbeirosAtivosArr = array_filter($barbeirosArr, function($barbeiro) {
    $status = $barbeiro['status'] ?? 'ativo';
    return (empty($status) || $status === 'ativo');
});

$servicosArr = lerDados('servicos', ['id', 'nome', 'valor', 'slots', 'categoria_id']);
$combosArr = lerDados('combos', ['id', 'nome', 'servicos_ids', 'valor', 'categoria_id']);
$categoriasArr = lerDados('categorias', ['id', 'nome', 'ordem']);
$planosArr = lerDados('planos', ['id', 'nome', 'valor', 'servicos_ids']);

// A adesão à assinatura é 100% online. Só oferecemos o plano no agendamento
// quando há um meio de pagamento online (Stripe) configurado.
$configStripeAg = function_exists('_lerConfigSQLite') ? _lerConfigSQLite('config_stripe', ['secret_key' => '']) : ['secret_key' => ''];
$stripeConfigurado = !empty(trim($configStripeAg['secret_key'] ?? ''));

foreach ($combosArr as $comboId => &$combo) {
    $slots_combo = 0;
    $servicos_do_combo = explode(',', $combo['servicos_ids']);
    foreach ($servicos_do_combo as $sid_combo) {
        $sid_limpo = trim($sid_combo);
        if (isset($servicosArr[$sid_limpo])) {
            $slots = (int)($servicosArr[$sid_limpo]['slots'] ?? 1);
            $slots_combo += ($slots > 0) ? $slots : 1;
        } else {
            $slots_combo += 1;
        }
    }
    $combo['slots'] = $slots_combo;
}
unset($combo); 

$avaliacoesArr = lerDados('avaliacoes', ['id', 'agendamento_id', 'cliente_id', 'barbeiro_id', 'rating', 'comment', 'timestamp']);
$clientesArr = lerDados('clientes', ['id', 'nome']);

$assinaturaAtiva = null;
$ultimo_agendamento = null;

if ($cliente_logado) {
    $assinaturaAtiva = getAssinaturaCliente($_SESSION['cliente_id']);
    
    // Buscar último agendamento para o "1-Clique"
    $pdo = getDB();
    try {
        $stmtUltimo = $pdo->prepare("SELECT barbeiro_id, servicos_ids FROM agendamentos WHERE cliente_id = ? AND status NOT IN ('cancelado', 'cancelado_pelo_cliente', 'reprovado') ORDER BY data DESC, hora DESC LIMIT 1");
        $stmtUltimo->execute([$_SESSION['cliente_id']]);
        $ultimo_agendamento = $stmtUltimo->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Erro ao procurar último agendamento do cliente ID {$_SESSION['cliente_id']}: " . $e->getMessage());
    }
}

$config_fidelidade = getFidelityConfig();
$config_aniversario = getAniversarioConfig();

$itensAgrupados = [];
foreach ($categoriasArr as $catId => $cat) {
    $itensAgrupados[$catId] = [
        'nome' => $cat['nome'],
        'ordem' => (int)($cat['ordem'] ?? 99),
        'servicos' => [],
        'combos' => []
    ];
}
$itensAgrupados['sem_categoria'] = [
    'nome' => 'Outros',
    'ordem' => 999,
    'servicos' => [],
    'combos' => []
];

foreach ($servicosArr as $servico) {
    $catId = $servico['categoria_id'] ?? 'sem_categoria';
    if (empty($catId) || !isset($itensAgrupados[$catId])) $catId = 'sem_categoria';
    $itensAgrupados[$catId]['servicos'][] = $servico;
}

foreach ($combosArr as $combo) {
    $catId = $combo['categoria_id'] ?? 'sem_categoria';
    if (empty($catId) || !isset($itensAgrupados[$catId])) $catId = 'sem_categoria';
    $itensAgrupados[$catId]['combos'][] = $combo;
}

$itensAgrupados = array_filter($itensAgrupados, function($cat) {
    return !empty($cat['servicos']) || !empty($cat['combos']);
});

uasort($itensAgrupados, fn($a, $b) => $a['ordem'] <=> $b['ordem']);
