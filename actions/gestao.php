<?php

garantirEstruturaGestaoAdmin();
$pdo = getDB();
$redirect = 'admin.php?tab=dashboard';

function gestaoRedirect($mensagem, $erro = false, $secao = '') {
    $url = 'admin.php?tab=dashboard';
    if ($secao !== '') {
        $url .= '&secao=' . urlencode($secao);
    }
    $url .= $erro ? '&error=' : '&success=';
    header('Location: ' . $url . urlencode($mensagem));
    exit;
}

if ($action === 'gestao_reagendar_rapido') {
    $agendamentoId = trim($_POST['agendamento_id'] ?? '');
    $novaData = trim($_POST['nova_data'] ?? '');
    if ($agendamentoId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $novaData) || $novaData < date('Y-m-d')) {
        gestaoRedirect('Não foi possível alterar a data do agendamento.', true, 'agenda');
    }
    $stmt = $pdo->prepare("SELECT barbeiro_id, hora, status FROM agendamentos WHERE id = ? LIMIT 1");
    $stmt->execute([$agendamentoId]);
    $agendamento = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$agendamento || in_array($agendamento['status'], ['cancelado', 'rejeitado', 'concluido'], true)) {
        gestaoRedirect('Somente agendamentos ativos podem ser movidos.', true, 'agenda');
    }
    $conflito = $pdo->prepare("SELECT COUNT(*) FROM agendamentos
        WHERE id <> ? AND barbeiro_id = ? AND data = ? AND hora = ?
        AND status NOT IN ('cancelado', 'rejeitado')");
    $conflito->execute([$agendamentoId, $agendamento['barbeiro_id'], $novaData, $agendamento['hora']]);
    if ((int)$conflito->fetchColumn() > 0) {
        gestaoRedirect('Já existe um atendimento desse profissional no mesmo horário.', true, 'agenda');
    }
    $update = $pdo->prepare("UPDATE agendamentos SET data = ? WHERE id = ?");
    $update->execute([$novaData, $agendamentoId]);
    registrarAtividadeGestao('Agendamento movido na agenda visual', $agendamentoId . ' para ' . $novaData);
    gestaoRedirect('Agendamento movido com sucesso.', false, 'agenda');
}

if ($action === 'gestao_salvar_crm') {
    $clienteId = trim($_POST['cliente_id'] ?? '');
    if ($clienteId === '') {
        gestaoRedirect('Selecione um cliente.', true, 'crm');
    }
    $tags = array_filter(array_map('trim', explode(',', (string)($_POST['tags'] ?? ''))));
    $tags = array_slice(array_unique($tags), 0, 8);
    $status = $_POST['status_relacionamento'] ?? 'ativo';
    if (!in_array($status, ['vip', 'ativo', 'atencao', 'inativo'], true)) {
        $status = 'ativo';
    }
    $stmt = $pdo->prepare("INSERT INTO admin_crm_clientes
        (cliente_id, tags, observacoes, status_relacionamento, updated_at)
        VALUES (?, ?, ?, ?, ?)
        ON CONFLICT(cliente_id) DO UPDATE SET
            tags = excluded.tags,
            observacoes = excluded.observacoes,
            status_relacionamento = excluded.status_relacionamento,
            updated_at = excluded.updated_at");
    $stmt->execute([
        $clienteId,
        mb_substr(implode(', ', $tags), 0, 300),
        mb_substr(trim($_POST['observacoes'] ?? ''), 0, 1500),
        $status,
        date('Y-m-d H:i:s'),
    ]);
    registrarAtividadeGestao('Perfil CRM atualizado', $clienteId);
    header('Location: admin.php?tab=dashboard&secao=crm&cliente_360=' . urlencode($clienteId) . '&success=' . urlencode('Perfil 360° atualizado.'));
    exit;
}

if ($action === 'gestao_salvar_meta') {
    $barbeiroId = trim($_POST['barbeiro_id'] ?? '');
    if ($barbeiroId === '') {
        gestaoRedirect('Selecione um profissional.', true, 'equipe');
    }
    $stmt = $pdo->prepare("INSERT INTO admin_metas_equipe
        (barbeiro_id, mes_ano, meta_atendimentos, meta_faturamento, meta_avaliacao)
        VALUES (?, ?, ?, ?, ?)
        ON CONFLICT(barbeiro_id, mes_ano) DO UPDATE SET
            meta_atendimentos = excluded.meta_atendimentos,
            meta_faturamento = excluded.meta_faturamento,
            meta_avaliacao = excluded.meta_avaliacao");
    $stmt->execute([
        $barbeiroId,
        date('Y-m'),
        max(0, (int)($_POST['meta_atendimentos'] ?? 0)),
        max(0, (float)($_POST['meta_faturamento'] ?? 0)),
        min(5, max(0, (float)($_POST['meta_avaliacao'] ?? 0))),
    ]);
    registrarAtividadeGestao('Meta de equipe atualizada', $barbeiroId);
    gestaoRedirect('Metas atualizadas com sucesso.', false, 'equipe');
}

if ($action === 'gestao_salvar_retencao') {
    $clienteId = trim($_POST['cliente_id'] ?? '');
    $status = $_POST['status'] ?? 'pendente';
    if ($clienteId === '' || !in_array($status, ['pendente', 'contatado', 'recuperado', 'encerrado'], true)) {
        gestaoRedirect('Dados de retenção inválidos.', true, 'receita');
    }
    $stmt = $pdo->prepare("INSERT INTO admin_retencao
        (cliente_id, motivo, oferta, status, updated_at) VALUES (?, ?, ?, ?, ?)
        ON CONFLICT(cliente_id) DO UPDATE SET
            motivo = excluded.motivo, oferta = excluded.oferta,
            status = excluded.status, updated_at = excluded.updated_at");
    $stmt->execute([
        $clienteId,
        mb_substr(trim($_POST['motivo'] ?? ''), 0, 300),
        mb_substr(trim($_POST['oferta'] ?? ''), 0, 300),
        $status,
        date('Y-m-d H:i:s'),
    ]);
    registrarAtividadeGestao('Caso de retenção atualizado', $clienteId . ' · ' . $status);
    gestaoRedirect('Acompanhamento de retenção atualizado.', false, 'receita');
}

if ($action === 'gestao_conciliar_pagamento') {
    $referencia = trim($_POST['referencia'] ?? '');
    if ($referencia === '') {
        gestaoRedirect('Pagamento sem referência para conciliação.', true, 'receita');
    }
    $stmt = $pdo->prepare("INSERT INTO admin_conciliacao
        (referencia, gateway, status, observacao, updated_at) VALUES (?, ?, 'revisado', ?, ?)
        ON CONFLICT(referencia) DO UPDATE SET
            gateway = excluded.gateway, status = 'revisado',
            observacao = excluded.observacao, updated_at = excluded.updated_at");
    $stmt->execute([
        $referencia,
        trim($_POST['gateway'] ?? ''),
        mb_substr(trim($_POST['observacao'] ?? ''), 0, 500),
        date('Y-m-d H:i:s'),
    ]);
    registrarAtividadeGestao('Pagamento conciliado', $referencia);
    gestaoRedirect('Pagamento marcado como revisado.', false, 'receita');
}

if ($action === 'gestao_salvar_perfil_usuario') {
    if (($_SESSION['admin_role'] ?? 'proprietario') !== 'proprietario') {
        gestaoRedirect('Somente o proprietário pode alterar permissões.', true, 'acessos');
    }
    $username = trim($_POST['username'] ?? '');
    $role = $_POST['role'] ?? '';
    if ($username === '' || !array_key_exists($role, obterPerfisAdmin())) {
        gestaoRedirect('Usuário ou perfil inválido.', true, 'acessos');
    }
    $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE username = ?");
    $stmt->execute([$role, $username]);
    if (($_SESSION['username'] ?? '') === $username) {
        $_SESSION['admin_role'] = $role;
    }
    registrarAtividadeGestao('Perfil de acesso atualizado', $username . ' · ' . $role);
    gestaoRedirect('Permissões atualizadas.', false, 'acessos');
}

gestaoRedirect('Ação de gestão não reconhecida.', true);
