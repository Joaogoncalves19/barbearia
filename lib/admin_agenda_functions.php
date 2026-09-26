<?php

function garantirEstruturaAgendaAdmin(): void
{
    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS agenda_operacao (
        agendamento_id TEXT PRIMARY KEY,
        confirmacao_status TEXT DEFAULT 'pendente',
        updated_at TEXT,
        updated_by TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agenda_historico (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        agendamento_id TEXT NOT NULL,
        acao TEXT NOT NULL,
        detalhes TEXT DEFAULT '',
        usuario TEXT DEFAULT '',
        created_at TEXT NOT NULL
    )");
}

function obterOperacoesAgenda(array $agendamentoIds = []): array
{
    garantirEstruturaAgendaAdmin();
    $pdo = getDB();
    $sql = "SELECT * FROM agenda_operacao";
    $params = [];
    if ($agendamentoIds) {
        $placeholders = implode(',', array_fill(0, count($agendamentoIds), '?'));
        $sql .= " WHERE agendamento_id IN ($placeholders)";
        $params = array_values($agendamentoIds);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $resultado = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $resultado[$row['agendamento_id']] = $row;
    }
    return $resultado;
}

function obterHistoricoAgenda(array $agendamentoIds = []): array
{
    garantirEstruturaAgendaAdmin();
    $pdo = getDB();
    $sql = "SELECT * FROM agenda_historico";
    $params = [];
    if ($agendamentoIds) {
        $placeholders = implode(',', array_fill(0, count($agendamentoIds), '?'));
        $sql .= " WHERE agendamento_id IN ($placeholders)";
        $params = array_values($agendamentoIds);
    }
    $sql .= " ORDER BY created_at DESC, id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $resultado = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $resultado[$row['agendamento_id']][] = $row;
    }
    return $resultado;
}

function registrarHistoricoAgenda(string $agendamentoId, string $acao, string $detalhes = '', string $usuario = ''): void
{
    garantirEstruturaAgendaAdmin();
    // Se o autor não for informado, tenta identificar pelo contexto de sessão.
    if ($usuario === '') {
        if (!empty($_SESSION['username'])) {
            $usuario = $_SESSION['username'];
        } elseif (!empty($_SESSION['barbeiro_nome'])) {
            $usuario = $_SESSION['barbeiro_nome'];
        } elseif (!empty($_SESSION['cliente_nome'])) {
            $usuario = $_SESSION['cliente_nome'] . ' (cliente)';
        } else {
            $usuario = 'Sistema';
        }
    }
    $stmt = getDB()->prepare("INSERT INTO agenda_historico
        (agendamento_id, acao, detalhes, usuario, created_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$agendamentoId, $acao, $detalhes, $usuario, date('Y-m-d H:i:s')]);
}

/**
 * Retorna o histórico de ações do dia (ordem cronológica decrescente),
 * já com o nome do cliente e do profissional resolvidos.
 */
function obterHistoricoDoDia(string $data): array
{
    garantirEstruturaAgendaAdmin();
    $pdo = getDB();
    $inicio = $data . ' 00:00:00';
    $fim = $data . ' 23:59:59';
    $stmt = $pdo->prepare("SELECT h.*, a.nome AS cliente_nome, a.barbeiro_id, a.data AS ag_data, a.hora AS ag_hora
        FROM agenda_historico h
        LEFT JOIN agendamentos a ON a.id = h.agendamento_id
        WHERE h.created_at BETWEEN ? AND ?
        ORDER BY h.created_at DESC, h.id DESC");
    $stmt->execute([$inicio, $fim]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function salvarOperacaoAgenda(string $agendamentoId, array $dados): void
{
    garantirEstruturaAgendaAdmin();
    $status = $dados['confirmacao_status'] ?? 'pendente';
    if (!in_array($status, ['pendente', 'enviado', 'confirmado', 'sem_resposta'], true)) {
        $status = 'pendente';
    }
    $usuario = $_SESSION['username'] ?? $_SESSION['barbeiro_nome'] ?? 'Admin';

    $stmt = getDB()->prepare("INSERT INTO agenda_operacao
        (agendamento_id, confirmacao_status, updated_at, updated_by)
        VALUES (?, ?, ?, ?)
        ON CONFLICT(agendamento_id) DO UPDATE SET
         confirmacao_status = excluded.confirmacao_status,
         updated_at = excluded.updated_at,
         updated_by = excluded.updated_by");
    $stmt->execute([
        $agendamentoId,
        $status,
        date('Y-m-d H:i:s'),
        $usuario,
    ]);
}

function calcularValorAgendamentoAgenda(array $agendamento, array $servicos, array $combos, array $planos): float
{
    $total = 0.0;
    foreach (explode(',', (string)($agendamento['servicos_ids'] ?? '')) as $id) {
        $id = trim($id);
        if (isset($servicos[$id])) {
            $total += (float)$servicos[$id]['valor'];
        } elseif (isset($combos[$id])) {
            $total += (float)$combos[$id]['valor'];
        }
    }
    $produtos = json_decode((string)($agendamento['produtos_vendidos'] ?? ''), true);
    if (is_array($produtos)) {
        foreach ($produtos as $produto) {
            $total += (float)($produto['valor'] ?? 0);
        }
    }
    $total -= (float)($agendamento['desconto_aplicado'] ?? 0);
    if (($agendamento['tipo_desconto'] ?? '') === 'adesao_plano') {
        $planoId = $agendamento['plano_provisorio'] ?? '';
        $total += (float)($planos[$planoId]['valor'] ?? 0);
    }
    return max(0, $total);
}
