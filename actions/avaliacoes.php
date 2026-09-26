<?php
// --- AÇÃO: ENVIAR LEMBRETE DE AVALIAÇÃO ---
if ($action === 'enviar_lembretes_avaliacao') {
    $pdo = getDB();
    // Busca IDs de agendamentos que já possuem avaliação
    $stmtAvaliacoes = $pdo->query("SELECT agendamento_id FROM avaliacoes");
    $agendamentos_avaliados_ids = $stmtAvaliacoes->fetchAll(PDO::FETCH_COLUMN);
    
    $placeholders = '';
    if (!empty($agendamentos_avaliados_ids)) {
        $placeholders = implode(',', array_fill(0, count($agendamentos_avaliados_ids), '?'));
        $sql = "SELECT * FROM agendamentos WHERE status = 'concluido' AND id NOT IN ($placeholders)";
        $stmtAg = $pdo->prepare($sql);
        $stmtAg->execute($agendamentos_avaliados_ids);
    } else {
        $stmtAg = $pdo->query("SELECT * FROM agendamentos WHERE status = 'concluido'");
    }
    
    $agendamentosParaLembrar = $stmtAg->fetchAll();

    if (empty($agendamentosParaLembrar)) {
        header('Location: admin.php?tab=avaliacoes&error=' . urlencode('Nenhum agendamento pendente de avaliação.'));
        exit;
    }
    $total_enviados = 0;
    foreach ($agendamentosParaLembrar as $ag) {
        if (!filter_var($ag['email'], FILTER_VALIDATE_EMAIL)) { continue; }
        $dados_email = ['nome_cliente' => $ag['nome'], 'data_agendamento' => date('d/m/Y', strtotime($ag['data'])), 'barbeiro' => $barbeirosArr[$ag['barbeiro_id']]['nome'] ?? 'Nosso Time'];
        if (enviarEmail($ag['email'], 'Como foi sua experiência? Deixe sua avaliação!', 'lembrete_avaliacao', $dados_email)) { $total_enviados++; }
    }
    header('Location: admin.php?tab=avaliacoes&success=' . urlencode("Lembretes enviados com sucesso para $total_enviados cliente(s)."));
    exit;
}

// --- AÇÕES DE AVALIAÇÃO (Atualizado para SQLite Nativo) ---
if ($action === 'toggle_destaque_avaliacao' && !empty($id)) {
    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS avaliacoes_destacadas (id_avaliacao TEXT PRIMARY KEY)");
    $stmt = $pdo->prepare("SELECT id_avaliacao FROM avaliacoes_destacadas WHERE id_avaliacao = ?");
    $stmt->execute([$id]);
    if ($stmt->fetch()) {
        $stmtDel = $pdo->prepare("DELETE FROM avaliacoes_destacadas WHERE id_avaliacao = ?");
        $stmtDel->execute([$id]);
        $msg = "Avaliação removida dos destaques.";
    } else {
        $stmtIns = $pdo->prepare("INSERT INTO avaliacoes_destacadas (id_avaliacao) VALUES (?)");
        $stmtIns->execute([$id]);
        $msg = "Avaliação fixada nos destaques!";
    }
    header('Location: admin.php?tab=avaliacoes&success=' . urlencode($msg)); 
    exit;
}

if ($action === 'salvar_resposta_avaliacao') {
    $id_avaliacao = $_POST['id_avaliacao'] ?? null; 
    $texto_resposta = trim($_POST['texto_resposta']);
    
    if (!empty($id_avaliacao) && !empty($texto_resposta)) {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS respostas_avaliacoes (id_resposta TEXT PRIMARY KEY, id_avaliacao TEXT, texto_resposta TEXT, timestamp TEXT)");
        
        $stmtGet = $pdo->prepare("SELECT id_resposta FROM respostas_avaliacoes WHERE id_avaliacao = ?");
        $stmtGet->execute([$id_avaliacao]);
        $row = $stmtGet->fetch();
        $timestamp = date('Y-m-d H:i:s');
        
        if ($row) {
            $stmtUp = $pdo->prepare("UPDATE respostas_avaliacoes SET texto_resposta = ?, timestamp = ? WHERE id_resposta = ?");
            $stmtUp->execute([$texto_resposta, $timestamp, $row['id_resposta']]);
        } else {
            $id_nova_resposta = gerarId('resp-');
            $stmtIns = $pdo->prepare("INSERT INTO respostas_avaliacoes (id_resposta, id_avaliacao, texto_resposta, timestamp) VALUES (?, ?, ?, ?)");
            $stmtIns->execute([$id_nova_resposta, $id_avaliacao, $texto_resposta, $timestamp]);
        }

        // Notifica o cliente por e-mail (se possível) que a avaliação foi respondida.
        if (empty($_POST['nao_notificar'])) {
            try {
                $stmtAv = $pdo->prepare("SELECT cliente_id FROM avaliacoes WHERE id = ?");
                $stmtAv->execute([$id_avaliacao]);
                $cid = $stmtAv->fetchColumn();
                if ($cid) {
                    $stmtCli = $pdo->prepare("SELECT nome, email FROM clientes WHERE id = ?");
                    $stmtCli->execute([$cid]);
                    $cli = $stmtCli->fetch(PDO::FETCH_ASSOC);
                    if ($cli && filter_var($cli['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                        enviarEmail($cli['email'], 'Respondemos sua avaliação', 'resposta_avaliacao', [
                            'nome_cliente' => $cli['nome'],
                            'resposta' => $_POST['texto_resposta'] ?? '',
                        ]);
                    }
                }
            } catch (Exception $e) { /* não bloqueia o salvamento */ }
        }
    }
    header('Location: admin.php?tab=avaliacoes&success=' . urlencode('Resposta da avaliação salva e publicada!'));
    exit;
}

if ($action === 'excluir_resposta_avaliacao' && !empty($id)) {
    $pdo = getDB();
    $pdo->prepare("DELETE FROM respostas_avaliacoes WHERE id_avaliacao = ?")->execute([$id]);
    header('Location: admin.php?tab=avaliacoes&success=' . urlencode('Resposta removida.'));
    exit;
}

if ($action === 'excluir_avaliacao' && !empty($id)) {
    $pdo = getDB();
    $stmt1 = $pdo->prepare("DELETE FROM avaliacoes WHERE id = ?");
    $stmt1->execute([$id]);
    $stmt2 = $pdo->prepare("DELETE FROM avaliacoes_destacadas WHERE id_avaliacao = ?");
    $stmt2->execute([$id]);
    $stmt3 = $pdo->prepare("DELETE FROM respostas_avaliacoes WHERE id_avaliacao = ?");
    $stmt3->execute([$id]);
    header('Location: admin.php?tab=avaliacoes&success=' . urlencode('Avaliação apagada permanentemente.'));
    exit;
}

/* =====================================================================
   LEMBRETES DE AVALIAÇÃO — envio em lote com progresso (reusa motor de campanhas)
   ===================================================================== */
function _av_pendentes_para_lembrete($pdo) {
    $avaliados = $pdo->query("SELECT agendamento_id FROM avaliacoes")->fetchAll(PDO::FETCH_COLUMN);
    $avaliadosSet = array_flip($avaliados);
    $ags = $pdo->query("SELECT * FROM agendamentos WHERE status = 'concluido'")->fetchAll(PDO::FETCH_ASSOC);
    $dest = [];
    foreach ($ags as $ag) {
        if (isset($avaliadosSet[$ag['id']])) { continue; }
        if (!filter_var($ag['email'] ?? '', FILTER_VALIDATE_EMAIL)) { continue; }
        $dest[] = ['id' => $ag['cliente_id'] ?? '', 'nome' => $ag['nome'] ?? 'Cliente', 'email' => $ag['email'], 'ref' => $ag['id']];
    }
    return $dest;
}

if ($action === 'lembrete_iniciar') {
    header('Content-Type: application/json');
    $pdo = getDB();
    $comEmail = _av_pendentes_para_lembrete($pdo); // pendentes já filtrados por e-mail válido

    if (empty($comEmail)) {
        echo json_encode(['success' => false, 'error' => 'Os atendimentos pendentes não têm e-mail válido cadastrado. Use o botão de WhatsApp na lista individual para pedir a avaliação.']);
        exit;
    }

    // Anti-reenvio: exclui clientes já lembrados nos últimos 15 dias (só quando há cliente_id)
    $recentes = function_exists('marketingClientesContatadosRecentemente') ? marketingClientesContatadosRecentemente('lembrete_avaliacao', 15) : [];
    $dest = array_values(array_filter($comEmail, fn($d) => $d['id'] === '' || !isset($recentes[$d['id']])));

    if (empty($dest)) {
        echo json_encode(['success' => false, 'error' => 'Todos os clientes com e-mail já foram lembrados nos últimos 15 dias. Você pode usar o WhatsApp na lista individual.']);
        exit;
    }

    $campanha_id = marketingCriarCampanha('lembrete_avaliacao', 'lembrete_avaliacao', 'Como foi sua experiência? Deixe sua avaliação!', '', 'pendentes', $dest, [], $_SESSION['username'] ?? '');
    echo json_encode(['success' => true, 'campanha_id' => $campanha_id, 'total' => count($dest)]);
    exit;
}

if ($action === 'lembrete_individual') {
    header('Content-Type: application/json');
    $ag_id = $_POST['agendamento_id'] ?? '';
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM agendamentos WHERE id = ?");
    $stmt->execute([$ag_id]);
    $ag = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ag || ($ag['status'] ?? '') !== 'concluido' || !filter_var($ag['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Agendamento inválido ou sem e-mail.']); exit;
    }
    $dest = [['id' => $ag['cliente_id'] ?? '', 'nome' => $ag['nome'] ?? 'Cliente', 'email' => $ag['email'], 'ref' => $ag['id']]];
    $campanha_id = marketingCriarCampanha('lembrete_avaliacao', 'lembrete_avaliacao', 'Como foi sua experiência? Deixe sua avaliação!', '', 'individual', $dest, [], $_SESSION['username'] ?? '');
    $res = marketingProcessarLote($campanha_id, 5);
    echo json_encode(['success' => (($res['enviados'] ?? 0) > 0), 'enviados' => $res['enviados'] ?? 0, 'error' => (($res['enviados'] ?? 0) > 0) ? '' : 'Falha ao enviar (verifique o SMTP).']);
    exit;
}

/* =====================================================================
   EXPORTAÇÃO CSV
   ===================================================================== */
if ($action === 'exportar_avaliacoes') {
    $pdo = getDB();
    $avs = $pdo->query("SELECT * FROM avaliacoes ORDER BY timestamp DESC")->fetchAll(PDO::FETCH_ASSOC);
    $clientes = lerDados('clientes', ['id', 'nome']);
    $barbeiros = lerDados('barbeiros', ['id', 'nome']);
    $respostas = [];
    foreach ($pdo->query("SELECT id_avaliacao, texto_resposta FROM respostas_avaliacoes")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $respostas[$r['id_avaliacao']] = $r['texto_resposta'];
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="avaliacoes_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF"); // BOM p/ Excel
    fputcsv($out, ['Data', 'Cliente', 'Barbeiro', 'Nota', 'Comentario', 'Resposta'], ';');
    foreach ($avs as $av) {
        fputcsv($out, [
            date('d/m/Y H:i', strtotime($av['timestamp'])),
            $clientes[$av['cliente_id']]['nome'] ?? 'Cliente',
            $barbeiros[$av['barbeiro_id']]['nome'] ?? 'N/A',
            (int)$av['rating'],
            $av['comment'] ?? '',
            $respostas[$av['id']] ?? '',
        ], ';');
    }
    fclose($out);
    exit;
}
?>