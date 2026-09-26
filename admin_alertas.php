<?php
/**
 * Endpoint JSON dos alertas operacionais do topo do painel admin.
 *
 * O sino no cabeçalho consulta este arquivo periodicamente (e ao voltar
 * o foco para a aba) para não ficar mostrando pendências de quando a
 * página foi aberta. Carrega só as tabelas usadas pelos alertas.
 */

require_once 'functions.php';
iniciarSessaoSegura();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'sessao_expirada']);
    exit;
}

date_default_timezone_set('America/Sao_Paulo');

try {
    garantirEstruturaGestaoAdmin();
    $_SESSION['admin_role'] = obterPerfilAdminUsuario($_SESSION['username'] ?? '');

    $agendamentosArr = lerDados('agendamentos', ['id', 'nome', 'barbeiro_id', 'data', 'hora', 'status']);
    $clientesArr     = lerDados('clientes', ['id', 'nome', 'data_nascimento']);
    $avaliacoesArr   = lerDados('avaliacoes', ['id', 'rating']);

    try {
        $produtosArr = lerDados('produtos', ['id', 'nome', 'quantidade', 'estoque_minimo']);
    } catch (Exception $e) {
        $produtosArr = [];
    }

    // Um perfil sem acesso à aba de destino não deve ver o alerta dela.
    $alertas = filtrarAlertasPorPermissaoAdmin(
        obterAlertasGestaoAdmin($agendamentosArr, $clientesArr, $avaliacoesArr, $produtosArr)
    );

    $resumo = resumirAlertasGestaoAdmin($alertas);

    echo json_encode([
        'ok'            => true,
        'alertas'       => $alertas,
        'resumo'        => $resumo,
        'atualizado_em' => date('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    if (function_exists('log_activity')) {
        log_activity('FALHA: admin_alertas.php - ' . $e->getMessage());
    }
    echo json_encode(['ok' => false, 'erro' => 'falha_interna']);
}
