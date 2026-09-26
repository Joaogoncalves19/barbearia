<?php
// --- AÇÃO: SALVAR AUSÊNCIA (FOLGA / FÉRIAS / AFASTAMENTO) ---
if ($action === 'salvar_ausencia') {
    $barbeiro_id = trim((string)($_POST['ausencia_barbeiro_id'] ?? ''));
    $data_inicio = trim((string)($_POST['ausencia_data_inicio'] ?? ''));
    $data_fim    = trim((string)($_POST['ausencia_data_fim'] ?? ''));
    $tipo        = trim((string)($_POST['ausencia_tipo'] ?? 'folga'));
    $motivo      = trim(htmlspecialchars((string)($_POST['ausencia_motivo'] ?? '')));

    if (!in_array($tipo, ['folga', 'ferias', 'atestado', 'outro'], true)) {
        $tipo = 'folga';
    }
    if ($barbeiro_id === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_inicio) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_fim)) {
        header('Location: admin.php?tab=barbeiros&error=' . urlencode('Selecione o profissional e um período válido.'));
        exit;
    }
    if ($data_fim < $data_inicio) {
        header('Location: admin.php?tab=barbeiros&error=' . urlencode('A data final não pode ser anterior à inicial.'));
        exit;
    }
    try {
        garantirTabelaAusencias();
        $stmt = getDB()->prepare("INSERT INTO barbeiro_ausencias (id, barbeiro_id, data_inicio, data_fim, tipo, motivo, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([gerarId('aus-'), $barbeiro_id, $data_inicio, $data_fim, $tipo, $motivo, date('Y-m-d H:i:s')]);
    } catch (PDOException $e) {
        log_activity("Erro ao salvar ausência: " . $e->getMessage());
        header('Location: admin.php?tab=barbeiros&error=' . urlencode('Não foi possível registrar a folga/férias.'));
        exit;
    }
    header('Location: admin.php?tab=barbeiros&success=' . urlencode('Folga/férias registrada com sucesso!'));
    exit;
}

// --- AÇÃO: EXCLUIR AUSÊNCIA ---
if ($action === 'excluir_ausencia') {
    $ausencia_id = $_REQUEST['id'] ?? $_REQUEST['ausencia_id'] ?? '';
    if (!empty($ausencia_id)) {
        try {
            garantirTabelaAusencias();
            getDB()->prepare("DELETE FROM barbeiro_ausencias WHERE id = ?")->execute([$ausencia_id]);
        } catch (PDOException $e) {
            log_activity("Erro ao excluir ausência: " . $e->getMessage());
        }
    }
    header('Location: admin.php?tab=barbeiros&success=' . urlencode('Registro removido.'));
    exit;
}

// --- AÇÃO: SALVAR BLOQUEIOS ---
if ($action === 'salvar_bloqueios') {
    $barbeiro_id = trim((string)($_POST['bloqueio_barbeiro_id'] ?? ''));
    $data = trim((string)($_POST['bloqueio_data_selecionada'] ?? ''));
    $horarios_selecionados = array_values(array_unique((array)($_POST['horarios_bloqueados'] ?? [])));
    
    $pdo = getDB();
    if ($barbeiro_id === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || $data < date('Y-m-d')) {
        header('Location: admin.php?tab=barbeiros&error=' . urlencode('Selecione um profissional e uma data válida.'));
        exit;
    }
    $expediente = getHorarioDeTrabalho($barbeiro_id, (int)date('w', strtotime($data)), $data);
    if (!$expediente) {
        header('Location: admin.php?tab=barbeiros&error=' . urlencode('O profissional não possui expediente nesta data.'));
        exit;
    }
    $permitidos = [];
    for ($cursor = strtotime($expediente['inicio']); $cursor < strtotime($expediente['fim']); $cursor = strtotime('+30 minutes', $cursor)) {
        $permitidos[] = date('H:i', $cursor);
    }
    foreach ($horarios_selecionados as $hora) {
        if (!preg_match('/^\d{2}:\d{2}$/', (string)$hora) || !in_array($hora, $permitidos, true)) {
            header('Location: admin.php?tab=barbeiros&error=' . urlencode('Um dos horários selecionados não pertence ao expediente.'));
            exit;
        }
    }
    $bloqueiosAnteriores = getHorariosBloqueados($barbeiro_id, $data);
    $ocupados = getHorariosOcupados($barbeiro_id, $data);
    foreach ($horarios_selecionados as $hora) {
        if (in_array($hora, $ocupados, true) && !in_array($hora, $bloqueiosAnteriores, true)) {
            header('Location: admin.php?tab=barbeiros&error=' . urlencode("O horário $hora já possui atendimento ou intervalo fixo."));
            exit;
        }
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS horarios_bloqueados (barbeiro_id TEXT, data TEXT, hora TEXT)");
        $pdo->beginTransaction();
        $stmtDel = $pdo->prepare("DELETE FROM horarios_bloqueados WHERE barbeiro_id = ? AND data = ?");
        $stmtDel->execute([$barbeiro_id, $data]);
        $stmtIns = $pdo->prepare("INSERT INTO horarios_bloqueados (barbeiro_id, data, hora) VALUES (?, ?, ?)");
        foreach ($horarios_selecionados as $hora) {
            $stmtIns->execute([$barbeiro_id, $data, $hora]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        log_activity("Erro ao salvar bloqueios no SQLite: " . $e->getMessage());
        header('Location: admin.php?tab=barbeiros&error=' . urlencode('Não foi possível salvar os bloqueios.'));
        exit;
    }
    header('Location: admin.php?tab=barbeiros&success=' . urlencode('Bloqueios de agenda salvos com sucesso!'));
    exit;
}

// --- AÇÃO: BLOQUEAR/DESBLOQUEAR MÊS INTEIRO ---
if ($action === 'acao_mes_inteiro') {
    $barbeiro_id = $_POST['bloqueio_barbeiro_id'];
    $mes = (int)$_POST['bloqueio_mes_atual']; 
    $ano = (int)$_POST['bloqueio_ano_atual'];
    $modo = $_POST['modo_mes']; 
    $mes_real = $mes + 1;
    $dias_no_mes = cal_days_in_month(CAL_GREGORIAN, $mes_real, $ano);
    $mes_str = str_pad($mes_real, 2, '0', STR_PAD_LEFT);
    
    $pdo = getDB();
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS horarios_bloqueados (barbeiro_id TEXT, data TEXT, hora TEXT)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS horarios_trabalho (barbeiro_id TEXT, dia TEXT, inicio TEXT, fim TEXT)");
        $pdo->beginTransaction();
        $stmtDel = $pdo->prepare("DELETE FROM horarios_bloqueados WHERE barbeiro_id = ? AND data LIKE ?");
        $stmtDel->execute([$barbeiro_id, "$ano-$mes_str-%"]);
        
        if ($modo === 'bloquear') {
            $stmtWork = $pdo->prepare("SELECT dia, inicio, fim FROM horarios_trabalho WHERE barbeiro_id = ?");
            $stmtWork->execute([$barbeiro_id]);
            $horarios_trabalho = [];
            while ($row = $stmtWork->fetch()) {
                $horarios_trabalho[$row['dia']] = ['inicio' => $row['inicio'], 'fim' => $row['fim']];
            }
            $stmtIns = $pdo->prepare("INSERT INTO horarios_bloqueados (barbeiro_id, data, hora) VALUES (?, ?, ?)");
            for ($dia = 1; $dia <= $dias_no_mes; $dia++) {
                $dia_str = str_pad($dia, 2, '0', STR_PAD_LEFT);
                $data = "$ano-$mes_str-$dia_str";
                $dia_semana = date('w', strtotime($data));
                
                if (isset($horarios_trabalho[$dia_semana])) {
                    $ocupadosData = getHorariosOcupados($barbeiro_id, $data);
                    $inicio = $horarios_trabalho[$dia_semana]['inicio'];
                    $fim = $horarios_trabalho[$dia_semana]['fim'];
                    $startMins = intval(substr($inicio, 0, 2)) * 60 + intval(substr($inicio, 3, 2));
                    $endMins = intval(substr($fim, 0, 2)) * 60 + intval(substr($fim, 3, 2));
                    for ($m = $startMins; $m < $endMins; $m += 30) {
                        $hora_formatada = sprintf('%02d:%02d', floor($m / 60), $m % 60);
                        if (!in_array($hora_formatada, $ocupadosData, true)) {
                            $stmtIns->execute([$barbeiro_id, $data, $hora_formatada]);
                        }
                    }
                }
            }
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        log_activity("Erro acao_mes_inteiro no SQLite: " . $e->getMessage());
        header('Location: admin.php?tab=barbeiros&error=' . urlencode('Não foi possível atualizar o mês.'));
        exit;
    }
    $msg = ($modo === 'bloquear') ? 'Mês inteiro bloqueado com sucesso!' : 'Mês inteiro desbloqueado com sucesso!';
    header('Location: admin.php?tab=barbeiros&success=' . urlencode($msg));
    exit;
}

// --- AÇÃO: SALVAR BARBEIRO (AGORA COM COMISSÃO DE PRODUTOS E SEGURANÇA MIME) ---
if ($action === 'salvar_barbeiro') {
    $id = $_POST['id'] ?: gerarId('br-');
    $nome = trim($_POST['nome']);
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    if (!empty($password) && !senhaAtendePolitica($password)) {
        $msgSenha = function_exists('mensagemPoliticaSenha') ? mensagemPoliticaSenha() : 'A senha deve ter no mínimo 8 caracteres, incluindo pelo menos uma letra e um número.';
        header('Location: admin.php?tab=barbeiros&error=' . urlencode('Senha do profissional inválida: ' . $msgSenha));
        exit;
    }
    $status = $_POST['status'] ?? 'ativo'; 
    $comissao = (int)($_POST['comissao'] ?? 0); 
    $comissao_produtos = isset($_POST['comissao_produtos']) ? 1 : 0; 
    $comissao_assinatura_tipo = $_POST['comissao_assinatura_tipo'] ?? 'padrao';
    if (!in_array($comissao_assinatura_tipo, ['padrao', 'percentual', 'fixo', 'nenhuma'], true)) {
        $comissao_assinatura_tipo = 'padrao';
    }
    $comissao_assinatura_valor = max(0, (float)str_replace(',', '.', $_POST['comissao_assinatura_valor'] ?? 0));
    if ($comissao_assinatura_tipo === 'percentual') {
        $comissao_assinatura_valor = min(100, $comissao_assinatura_valor);
    } elseif (!in_array($comissao_assinatura_tipo, ['fixo', 'percentual'], true)) {
        $comissao_assinatura_valor = 0;
    }
    $especialidades_ids_array = $_POST['especialidades_ids'] ?? []; 
    $servicos_ids_string = implode(',', $especialidades_ids_array); 
    
    $foto = $_POST['foto_atual'] ?? '';
    
    if (empty($foto) || $foto == 'uploads/default.png') {
        $foto = 'uploads/default-profile.jpg';
    }
    
    // Verificação estrita de segurança MIME para foto do profissional
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
        [$uploadOk, $uploadResultado] = salvarUploadSeguro(
            $_FILES['foto'],
            'barbeiro',
            ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
            3 * 1024 * 1024,
            'barbeiro-' . $id
        );
        if (!$uploadOk) {
            header('Location: admin.php?tab=barbeiros&error=' . urlencode($uploadResultado));
            exit;
        }
        $foto = $uploadResultado;
    }
    $pdo = getDB();
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS barbeiros (id TEXT PRIMARY KEY, nome TEXT, foto TEXT, username TEXT, password TEXT, status TEXT, servicos_ids TEXT, comissao INTEGER DEFAULT 0, comissao_produtos INTEGER DEFAULT 0, comissao_assinatura_tipo TEXT DEFAULT 'padrao', comissao_assinatura_valor REAL DEFAULT 0)");
    // Colunas de comissão garantidas por lib/migrations.php (via getDB()).

    $password_hash = '';
    if (!empty($password)) { 
        $password_hash = password_hash($password, PASSWORD_DEFAULT); 
    } else {
        $stmtGet = $pdo->prepare("SELECT password FROM barbeiros WHERE id = ?");
        $stmtGet->execute([$id]);
        if ($row = $stmtGet->fetch()) {
            $password_hash = $row['password'];
        }
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO barbeiros (
            id, nome, foto, username, password, status, servicos_ids, comissao,
            comissao_produtos, comissao_assinatura_tipo, comissao_assinatura_valor
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(id) DO UPDATE SET
            nome = excluded.nome,
            foto = excluded.foto,
            username = excluded.username,
            password = excluded.password,
            status = excluded.status,
            servicos_ids = excluded.servicos_ids,
            comissao = excluded.comissao,
            comissao_produtos = excluded.comissao_produtos,
            comissao_assinatura_tipo = excluded.comissao_assinatura_tipo,
            comissao_assinatura_valor = excluded.comissao_assinatura_valor
    ");
    $stmt->execute([
        $id, $nome, $foto, $username, $password_hash, $status, $servicos_ids_string,
        $comissao, $comissao_produtos, $comissao_assinatura_tipo, $comissao_assinatura_valor
    ]);
    
    header('Location: admin.php?tab=barbeiros&success=' . urlencode('Profissional salvo com sucesso!'));
    exit;
}

if ($action === 'excluir_barbeiro' && !empty($id)) { 
    $pdo = getDB();
    $stmt = $pdo->prepare("DELETE FROM barbeiros WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: admin.php?tab=barbeiros&success=' . urlencode('Profissional excluído do sistema.')); 
    exit; 
}

// --- AÇÃO: SALVAR SEMANA COMPLETA DE HORÁRIOS ---
if ($action === 'salvar_semana_horarios') {
    $barbeiro_id = $_POST['barbeiro_id'];
    $horarios_post = $_POST['horarios'] ?? []; 
    if (empty($barbeiro_id)) {
        header('Location: admin.php?tab=barbeiros&error=' . urlencode('Nenhum barbeiro foi selecionado.'));
        exit;
    }
    $pdo = getDB();
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS horarios_trabalho (barbeiro_id TEXT, dia TEXT, inicio TEXT, fim TEXT)");
        $pdo->beginTransaction();
        $stmtDel = $pdo->prepare("DELETE FROM horarios_trabalho WHERE barbeiro_id = ?");
        $stmtDel->execute([$barbeiro_id]);
        $stmtIns = $pdo->prepare("INSERT INTO horarios_trabalho (barbeiro_id, dia, inicio, fim) VALUES (?, ?, ?, ?)");
        foreach ($horarios_post as $dia => $dados) {
            if (isset($dados['ativo']) && $dados['ativo'] == '1') {
                $inicio = $dados['inicio'];
                $fim = $dados['fim'];
                if (!empty($inicio) && !empty($fim)) {
                    $stmtIns->execute([$barbeiro_id, $dia, $inicio, $fim]);
                }
            }
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        log_activity("Erro ao salvar semana de horarios no SQLite: " . $e->getMessage());
    }
    header('Location: admin.php?tab=barbeiros&success=' . urlencode('Grade de horários atualizada com sucesso!'));
    exit;
}
?>
