<?php
// check_new_appointments.php
// Endpoint de polling das notificações de novos agendamentos.
// Além da contagem/última data (compatibilidade), devolve `recent`: uma lista
// enriquecida dos agendamentos mais novos (cliente, serviços, data/hora,
// profissional e status) para o cartão de notificação detalhado no frontend.
header('Content-Type: application/json');
require_once 'functions.php';
iniciarSessaoSegura();

if (empty($_SESSION['loggedin']) && empty($_SESSION['barbeiro_loggedin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso nao autorizado.']);
    exit;
}

// Barbeiro só enxerga os próprios agendamentos; admin enxerga tudo.
$barbeiro_id = $_GET['barbeiro_id'] ?? null;
if (!empty($_SESSION['barbeiro_loggedin']) && empty($_SESSION['loggedin'])) {
    $barbeiro_id = $_SESSION['barbeiro_id'] ?? $barbeiro_id;
}
$ehAdmin = !empty($_SESSION['loggedin']);

/**
 * Converte os IDs de serviços/combos de um agendamento numa string legível.
 */
function _naNomesServicos($csvIds, array $servicosArr, array $combosArr) {
    $nomes = [];
    foreach (array_filter(array_map('trim', explode(',', (string)$csvIds))) as $sid) {
        if (isset($servicosArr[$sid])) {
            $nomes[] = $servicosArr[$sid]['nome'];
        } elseif (isset($combosArr[$sid])) {
            $nomes[] = $combosArr[$sid]['nome'];
        }
    }
    return $nomes ? implode(', ', $nomes) : 'Serviço';
}

/**
 * Rótulo amigável do status do agendamento.
 */
function _naRotuloStatus($status) {
    $mapa = [
        'aprovado'    => 'Confirmado',
        'pendente'    => 'Aguardando aprovação',
        'concluido'   => 'Concluído',
        'cancelado'   => 'Cancelado',
        'rejeitado'   => 'Rejeitado',
    ];
    $s = (string)$status;
    return $mapa[$s] ?? ucfirst($s ?: 'Agendado');
}

try {
    $pdo = getDB();
    $latest_date = date('Y-m-d');

    // Descobre se existe a coluna data_criacao para ordenar/exibir "quando marcou".
    $temDataCriacao = false;
    try {
        foreach ($pdo->query("PRAGMA table_info(agendamentos)") as $col) {
            if (($col['name'] ?? '') === 'data_criacao') { $temDataCriacao = true; break; }
        }
    } catch (Exception $e) { /* ignora */ }

    $where = "status != 'aguardando_pagamento'";
    $paramsBase = [];
    if ($barbeiro_id) {
        $where .= " AND barbeiro_id = ?";
        $paramsBase[] = $barbeiro_id;
    }

    // Contagem total (baseline que o frontend compara a cada polling).
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM agendamentos WHERE $where");
    $stmtCount->execute($paramsBase);
    $count = (int)$stmtCount->fetchColumn();

    // Agendamentos mais recentes (por ordem de inserção).
    $stmtRecent = $pdo->prepare(
        "SELECT id, nome, servicos_ids, data, hora, barbeiro_id, status" .
        ($temDataCriacao ? ", data_criacao" : "") .
        " FROM agendamentos WHERE $where ORDER BY ROWID DESC LIMIT 8"
    );
    $stmtRecent->execute($paramsBase);
    $linhas = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($linhas)) {
        $latest_date = $linhas[0]['data'] ?: $latest_date;
    }

    // Mapas de apoio para nomes (uma leitura só).
    $servicosArr = lerDados('servicos', ['id', 'nome']) ?: [];
    $combosArr   = lerDados('combos', ['id', 'nome']) ?: [];
    $barbeirosArr = ($ehAdmin && !$barbeiro_id) ? (lerDados('barbeiros', ['id', 'nome']) ?: []) : [];

    $recent = [];
    foreach ($linhas as $ag) {
        $recent[] = [
            'id'        => $ag['id'],
            'cliente'   => $ag['nome'] ?: 'Cliente',
            'servicos'  => _naNomesServicos($ag['servicos_ids'] ?? '', $servicosArr, $combosArr),
            'data'      => $ag['data'] ?? '',
            'hora'      => $ag['hora'] ?? '',
            'barbeiro'  => $barbeirosArr[$ag['barbeiro_id']]['nome'] ?? '',
            'status'        => $ag['status'] ?? '',
            'status_label'  => _naRotuloStatus($ag['status'] ?? ''),
            'criado'    => $temDataCriacao ? ($ag['data_criacao'] ?? '') : '',
        ];
    }

    echo json_encode([
        'appointment_count' => $count,
        'latest_date'       => $latest_date,
        'recent'            => $recent,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    // Falha silenciosa para não quebrar o polling no frontend.
    echo json_encode(['error' => 'Erro ao consultar o banco de dados.']);
}
