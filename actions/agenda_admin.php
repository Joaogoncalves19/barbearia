<?php

garantirEstruturaAgendaAdmin();
garantirEstruturaGestaoAdmin();
$pdo = getDB();

function agendaAdminRedirect(string $mensagem, bool $erro = false, array $extra = []): void
{
    $params = array_merge([
        'tab' => 'agendamentos',
        $erro ? 'error' : 'success' => $mensagem,
    ], $extra);
    header('Location: admin.php?' . http_build_query($params));
    exit;
}

function agendaAdminDataRetorno(): array
{
    $data = $_REQUEST['return_date'] ?? '';
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) ? ['filtro_data' => $data] : [];
}

if ($action === 'gestao_salvar_espera') {
    $nome = trim((string)($_POST['nome'] ?? ''));
    $telefone = preg_replace('/\D+/', '', (string)($_POST['telefone'] ?? ''));
    if ($nome === '' || $telefone === '') {
        agendaAdminRedirect('Informe o nome e o telefone do cliente.', true);
    }
    $stmt = $pdo->prepare("INSERT INTO admin_lista_espera
        (cliente_id, nome, telefone, barbeiro_id, data_preferida, periodo, observacoes, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'aguardando', ?)");
    $stmt->execute([
        trim((string)($_POST['cliente_id'] ?? '')),
        mb_substr($nome, 0, 120),
        $telefone,
        trim((string)($_POST['barbeiro_id'] ?? '')),
        trim((string)($_POST['data_preferida'] ?? '')),
        in_array($_POST['periodo'] ?? '', ['manha', 'tarde', 'noite', 'qualquer'], true)
            ? $_POST['periodo']
            : 'qualquer',
        mb_substr(trim((string)($_POST['observacoes'] ?? '')), 0, 500),
        date('Y-m-d H:i:s'),
    ]);
    registrarAtividadeGestao('Cliente adicionado à lista de espera', $nome);
    agendaAdminRedirect('Cliente adicionado à lista de espera.');
}

if ($action === 'gestao_status_espera') {
    $status = $_POST['status'] ?? '';
    if (!in_array($status, ['aguardando', 'contatado', 'agendado', 'removido'], true)) {
        agendaAdminRedirect('Status inválido.', true);
    }
    $stmt = $pdo->prepare("UPDATE admin_lista_espera SET status = ? WHERE id = ?");
    $stmt->execute([$status, (int)($_POST['espera_id'] ?? 0)]);
    registrarAtividadeGestao('Lista de espera atualizada', 'Status: ' . $status);
    agendaAdminRedirect('Lista de espera atualizada.');
}

if ($action === 'agenda_marcar_confirmacao') {
    $agendamentoId = trim((string)($_POST['agendamento_id'] ?? ''));
    $status = $_POST['confirmacao_status'] ?? 'enviado';
    $existe = $pdo->prepare("SELECT COUNT(*) FROM agendamentos WHERE id = ?");
    $existe->execute([$agendamentoId]);
    if (!$existe->fetchColumn()) {
        agendaAdminRedirect('Agendamento não encontrado.', true, agendaAdminDataRetorno());
    }
    salvarOperacaoAgenda($agendamentoId, ['confirmacao_status' => $status]);
    registrarHistoricoAgenda($agendamentoId, 'Confirmação atualizada', ucfirst(str_replace('_', ' ', $status)));
    agendaAdminRedirect('Situação da confirmação registrada.', false, agendaAdminDataRetorno());
}

if ($action === 'agenda_acao_lote') {
    $ids = array_values(array_unique(array_filter((array)($_POST['agendamentos'] ?? []))));
    $acaoLote = $_POST['acao_lote'] ?? '';
    if (!$ids || !in_array($acaoLote, ['cancelar', 'lembrete'], true)) {
        agendaAdminRedirect('Selecione horários e uma ação válida.', true, agendaAdminDataRetorno());
    }
    $barbeiros = lerDados('barbeiros', ['id', 'nome']);
    $servicos = lerDados('servicos', ['id', 'nome']);
    $combos = lerDados('combos', ['id', 'nome']);
    $alterados = 0;
    foreach ($ids as $agendamentoId) {
        $stmt = $pdo->prepare("SELECT * FROM agendamentos WHERE id = ?");
        $stmt->execute([$agendamentoId]);
        $agendamento = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$agendamento) continue;

        if ($acaoLote === 'cancelar') {
            $pdo->prepare("UPDATE agendamentos SET status = 'cancelado' WHERE id = ?")->execute([$agendamentoId]);
            // O ID e o identificador imutavel do cliente: busca por ele primeiro.
            // Antes isto passava o ID para getClientePorEmailTelefoneOuCPF(), que
            // procura por e-mail/telefone/CPF -- entao um ID como 'CL-A7K2P9' virava
            // a busca por telefone '729' e nunca encontrava (ou pior, encontrava
            // outro cliente). Os campos abaixo ficam como fallback para agendamento
            // feito sem cadastro, onde nao existe cliente_id.
            $cliente = !empty($agendamento['cliente_id'])
                ? getClientById($agendamento['cliente_id'])
                : null;
            if (!$cliente) $cliente = getClientePorEmailTelefoneOuCPF($agendamento['telefone'] ?? '');
            if (!$cliente) $cliente = getClientePorEmailTelefoneOuCPF($agendamento['email'] ?? '');
            if ($cliente) {
                criarNotificacao($cliente['id'], 'Seu agendamento de ' . date('d/m/Y', strtotime($agendamento['data'])) . ' às ' . $agendamento['hora'] . ' foi cancelado pelo estabelecimento.');
            }
        } elseif ($acaoLote === 'lembrete') {
            salvarOperacaoAgenda($agendamentoId, ['confirmacao_status' => 'enviado']);
        }

        if (in_array($acaoLote, ['cancelar', 'lembrete'], true)
            && !empty($agendamento['email']) && filter_var($agendamento['email'], FILTER_VALIDATE_EMAIL)) {
            $nomesServicos = [];
            foreach (explode(',', (string)$agendamento['servicos_ids']) as $servicoId) {
                $servicoId = trim($servicoId);
                if (isset($servicos[$servicoId])) $nomesServicos[] = $servicos[$servicoId]['nome'];
                elseif (isset($combos[$servicoId])) $nomesServicos[] = $combos[$servicoId]['nome'] . ' (Combo)';
            }
            $dadosEmail = [
                'nome_cliente' => $agendamento['nome'],
                'data_agendamento' => date('d/m/Y', strtotime($agendamento['data'])),
                'hora_agendamento' => $agendamento['hora'],
                'servicos' => $nomesServicos,
                'barbeiro' => $barbeiros[$agendamento['barbeiro_id']]['nome'] ?? 'Não especificado',
                'tipo_desconto' => $agendamento['tipo_desconto'] ?? '',
            ];
            enviarEmail(
                $agendamento['email'],
                $acaoLote === 'cancelar' ? 'Informações sobre seu Agendamento' : 'Lembrete do seu Agendamento',
                $acaoLote === 'cancelar' ? 'cancelado' : 'lembrete',
                $dadosEmail
            );
        }
        registrarHistoricoAgenda($agendamentoId, 'Ação em lote', ucfirst($acaoLote));
        $alterados++;
    }
    agendaAdminRedirect("$alterados agendamentos atualizados.", false, agendaAdminDataRetorno());
}
