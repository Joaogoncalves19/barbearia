<?php
// barbearia/cliente_actions.php
$action = $_POST['action'] ?? $_GET['action'] ?? null;
$is_ajax = isset($_POST['is_ajax']) && $_POST['is_ajax'] === '1';

function retornarResposta($tipo, $msg, $is_ajax) {
    if ($is_ajax) {
        echo json_encode(['status' => ($tipo === 'sucesso' ? 'success' : 'error'), 'message' => $msg]);
        exit;
    } else {
        global $mensagem, $mensagem_tipo;
        $mensagem = $msg;
        $mensagem_tipo = $tipo;
    }
}

/**
 * Seguranca (S-04): valida o reagendamento pedido pelo cliente com as mesmas
 * regras do agendamento online (processar_agendamento.php). Antes, data e hora
 * iam direto para o banco: horario ocupado, fora do expediente, texto
 * arbitrario e ate agendamento cancelado voltavam como "aprovado".
 *
 * @return string Mensagem de erro para o cliente, ou '' se estiver tudo certo.
 */
function validarReagendamentoCliente(array $ag, $nova_data, $novo_horario) {
    if (!in_array($ag['status'] ?? '', ['aprovado', 'pendente'], true)) {
        return 'Este agendamento não pode mais ser reagendado.';
    }
    if (!is_string($nova_data) || !is_string($novo_horario)
        || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $nova_data, $d)
        || !checkdate((int) $d[2], (int) $d[3], (int) $d[1])
        || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $novo_horario, $h)) {
        return 'Data ou horário inválido.';
    }
    if ((int) $h[2] % 30 !== 0) {
        return 'Escolha um dos horários disponíveis.';
    }

    $inicioOriginal = strtotime(($ag['data'] ?? '') . ' ' . ($ag['hora'] ?? ''));
    if ($inicioOriginal === false || $inicioOriginal < time()) {
        return 'Este agendamento já passou e não pode ser reagendado.';
    }

    $inicio = strtotime($nova_data . ' ' . $novo_horario);
    $cfg = carregarConfigAgendamento();
    $minAntecedencia = (int) ($cfg['antecedencia_minima_minutos'] ?? 0);
    $maxDias = (int) ($cfg['antecedencia_maxima'] ?? 0);
    if ($inicio < time()) {
        return 'Esse horário já passou. Escolha um horário futuro.';
    }
    if ($minAntecedencia > 0 && $inicio < time() + ($minAntecedencia * 60)) {
        return 'É necessário reagendar com pelo menos ' . $minAntecedencia . ' minuto(s) de antecedência.';
    }
    if ($maxDias > 0 && strtotime($nova_data) > strtotime('+' . $maxDias . ' days', strtotime(date('Y-m-d')))) {
        return 'Só é possível agendar com até ' . $maxDias . ' dia(s) de antecedência.';
    }

    // Duracao em slots de 30 min (mesma conta de getHorariosOcupados()).
    $barbeiroId = (string) ($ag['barbeiro_id'] ?? '');
    $servicosArr = lerDados('servicos', ['id', 'slots']);
    $combosArr = lerDados('combos', ['id', 'servicos_ids']);
    $slots = 0;
    foreach (array_filter(array_map('trim', explode(',', (string) ($ag['servicos_ids'] ?? '')))) as $itemId) {
        if (isset($servicosArr[$itemId])) {
            $slots += max(1, (int) ($servicosArr[$itemId]['slots'] ?? 1));
        } elseif (isset($combosArr[$itemId])) {
            foreach (array_filter(array_map('trim', explode(',', (string) $combosArr[$itemId]['servicos_ids']))) as $sid) {
                $slots += max(1, (int) ($servicosArr[$sid]['slots'] ?? 1));
            }
        } else {
            $slots++;
        }
    }
    $slots = max(1, $slots);

    // Expediente do dia (getHorarioDeTrabalho ja devolve null em folga/ferias).
    $expediente = getHorarioDeTrabalho($barbeiroId, (int) date('w', $inicio), $nova_data);
    if (!$expediente) {
        return 'O profissional não atende nesse dia. Escolha outra data.';
    }
    $fim = $inicio + ($slots * 30 * 60);
    if ($inicio < strtotime($nova_data . ' ' . $expediente['inicio']) || $fim > strtotime($nova_data . ' ' . $expediente['fim'])) {
        return 'O horário escolhido não pertence ao expediente do profissional.';
    }

    // Ocupacao, ignorando os slots do proprio agendamento (remarcar no mesmo dia).
    $ocupados = getHorariosOcupados($barbeiroId, $nova_data);
    if (($ag['data'] ?? '') === $nova_data) {
        $iniProprio = (int) date('G', $inicioOriginal) * 60 + (int) date('i', $inicioOriginal);
        $proprios = [];
        for ($i = 0; $i < $slots; $i++) {
            $m = $iniProprio + $i * 30;
            $proprios[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        }
        $ocupados = array_diff($ocupados, $proprios);
    }
    $iniNovo = (int) $h[1] * 60 + (int) $h[2];
    for ($i = 0; $i < $slots; $i++) {
        $m = $iniNovo + $i * 30;
        if (in_array(sprintf('%02d:%02d', intdiv($m, 60), $m % 60), $ocupados, true)) {
            return 'Esse horário já está ocupado. Escolha outro.';
        }
    }
    return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action && $action !== 'marcar_lidas') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        // retornarResposta encerra a execução no fluxo AJAX; no fluxo normal
        // é preciso interromper explicitamente para não executar a ação.
        retornarResposta('erro', 'Sessão expirada ou token de segurança inválido. Recarregue a página.', $is_ajax);
        header('Location: cliente?error=csrf_invalido');
        exit;
    }
}

if ($action === 'marcar_lidas') { 
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        retornarResposta('erro', 'Sessão expirada ou token de segurança inválido. Recarregue a página.', $is_ajax);
        exit;
    }
    marcarNotificacoesComoLidas($cliente_id);
    exit;
}

if ($action === 'cancelar' && isset($_GET['id'])) {
    if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
        header('Location: cliente?error=csrf_invalido');
        exit;
    }

    $agendamento_id = $_GET['id'];
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM agendamentos WHERE id = ?");
    $stmt->execute([$agendamento_id]);
    $ag = $stmt->fetch();
    
    if ($ag) {
        $is_meu_agendamento = false;
        if (!empty($ag['cliente_id'])) {
            $is_meu_agendamento = ($ag['cliente_id'] === $cliente_id);
        } else {
            $telAgendamento = limparTelefone($ag['telefone']);
            $telSessao = telefoneClienteDaSessao(); // lido do banco pelo ID, nunca a copia velha da sessao
            $is_meu_agendamento = ($telAgendamento === $telSessao);
        }
        
        if ($is_meu_agendamento) {
            $stmtUp = $pdo->prepare("UPDATE agendamentos SET status = 'cancelado_pelo_cliente' WHERE id = ?");
            $stmtUp->execute([$agendamento_id]);
            if (function_exists('registrarHistoricoAgenda')) {
                registrarHistoricoAgenda($agendamento_id, 'Agendamento cancelado', 'Cancelado pelo próprio cliente', ($_SESSION['cliente_nome'] ?? 'Cliente') . ' (cliente)');
            }
            criarNotificacao($cliente_id, "Você cancelou seu agendamento de ".date('d/m/Y', strtotime($ag['data']))." às ".$ag['hora'].".");
        }
    }
    header('Location: cliente?cancelado=1');
    exit;
}

if ($action === 'salvar_perfil') {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
    $stmt->execute([$cliente_id]);
    $cliente = $stmt->fetch();

    if($cliente) {
        $telefone_antigo_limpo = limparTelefone($cliente['telefone']);
        $novo_nome = trim($_POST['nome']); 
        $novo_telefone = limparTelefone($_POST['telefone']);
        $foto_nome = $cliente['foto_perfil'];
        
        if (!empty($_POST['foto_perfil_base64'])) {
            [$uploadOk, $uploadResultado] = salvarImagemBase64Segura($_POST['foto_perfil_base64'], 'perfil-' . $cliente_id, 3 * 1024 * 1024, 'cliente-' . $cliente_id);
            if ($uploadOk) {
                $foto_nome = $uploadResultado;
            }
        } elseif (isset($_FILES['foto_perfil']) && $_FILES['foto_perfil']['error'] == 0) {
            [$uploadOk, $uploadResultado] = salvarUploadSeguro(
                $_FILES['foto_perfil'],
                'perfil-' . $cliente_id,
                ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
                3 * 1024 * 1024,
                'cliente-' . $cliente_id
            );
            if ($uploadOk) {
                $foto_nome = $uploadResultado;
            }
        }

        try {
            $pdo->beginTransaction();
            $stmtUp = $pdo->prepare("UPDATE clientes SET nome = ?, telefone = ?, foto_perfil = ? WHERE id = ?");
            $stmtUp->execute([$novo_nome, $novo_telefone, $foto_nome, $cliente_id]);
            
            if ($telefone_antigo_limpo !== $novo_telefone || $cliente['nome'] !== $novo_nome) {
                $stmtHist = $pdo->prepare("UPDATE agendamentos SET nome = ?, telefone = ? WHERE cliente_id = ?");
                $stmtHist->execute([$novo_nome, $novo_telefone, $cliente_id]);
            }
            $pdo->commit();
            $_SESSION['cliente_nome'] = $novo_nome; 
            $_SESSION['cliente_telefone'] = $novo_telefone;
            
            retornarResposta('sucesso', 'Perfil atualizado com sucesso!', $is_ajax);
        } catch (PDOException $e) {
            $pdo->rollBack(); 
            log_activity("Erro ao atualizar perfil: " . $e->getMessage());
            retornarResposta('erro', 'Erro interno ao atualizar perfil.', $is_ajax);
        }
    }
}

if ($action === 'alterar_senha') {
    if (isset($_SESSION['senha_bloqueio_ate']) && time() < $_SESSION['senha_bloqueio_ate']) {
        $minutos = ceil(($_SESSION['senha_bloqueio_ate'] - time()) / 60);
        retornarResposta('erro', "Muitas tentativas. Tente novamente em $minutos minuto(s).", $is_ajax);
    }

    $cliente = getClientById($cliente_id);
    
    if (!password_verify($_POST['senha_atual'], $cliente['password_hash'])) {
        $_SESSION['senha_tentativas'] = ($_SESSION['senha_tentativas'] ?? 0) + 1;
        if ($_SESSION['senha_tentativas'] >= 5) {
            $_SESSION['senha_bloqueio_ate'] = time() + (15 * 60);
            retornarResposta('erro', "Bloqueado por 15 minutos por segurança.", $is_ajax);
        } else {
            retornarResposta('erro', "Senha atual incorreta.", $is_ajax);
        }
    } elseif ($_POST['nova_senha'] !== $_POST['confirma_nova_senha']) {
        retornarResposta('erro', "As novas senhas não coincidem.", $is_ajax);
    } elseif (!senhaAtendePolitica($_POST['nova_senha'])) {
        retornarResposta('erro', "A nova senha deve ter pelo menos 8 caracteres.", $is_ajax);
    } else {
        unset($_SESSION['senha_tentativas'], $_SESSION['senha_bloqueio_ate']);
        $hash = password_hash($_POST['nova_senha'], PASSWORD_DEFAULT);
        $pdo = getDB(); 
        $stmt = $pdo->prepare("UPDATE clientes SET password_hash = ? WHERE id = ?");
        $stmt->execute([$hash, $cliente_id]); 
        revogarTokensCliente($cliente_id);
        retornarResposta('sucesso', "Senha alterada com segurança!", $is_ajax);
    }
}

if ($action === 'reagendar_agendamento') {
    $agendamento_id = $_POST['reagendar_agendamento_id'] ?? '';
    $nova_data = $_POST['reagendar_data'] ?? '';
    $novo_horario = $_POST['reagendar_horario'] ?? '';
    
    $pdo = getDB();
    $stmtCheck = $pdo->prepare("SELECT id, cliente_id, telefone, barbeiro_id, servicos_ids, data, hora, status FROM agendamentos WHERE id = ?");
    $stmtCheck->execute([$agendamento_id]);
    $ag = $stmtCheck->fetch();
    
    if ($ag) {
        // --- CORREÇÃO: Prevenção contra IDOR (Insecure Direct Object Reference) ---
        $is_meu_agendamento = false;
        if (!empty($ag['cliente_id'])) {
            $is_meu_agendamento = ($ag['cliente_id'] === $cliente_id);
        } else {
            $telAgendamento = limparTelefone($ag['telefone']);
            $telSessao = telefoneClienteDaSessao(); // lido do banco pelo ID, nunca a copia velha da sessao
            $is_meu_agendamento = ($telAgendamento === $telSessao);
        }

        $erroReagendamento = $is_meu_agendamento ? validarReagendamentoCliente($ag, $nova_data, $novo_horario) : '';
        $gravou = false;
        if ($is_meu_agendamento && $erroReagendamento === '') {
            // O status fica como esta (so aprovado/pendente chegam aqui): antes o
            // UPDATE forcava 'aprovado', o que reativava agendamentos cancelados.
            try {
                $stmtUp = $pdo->prepare("UPDATE agendamentos SET data = ?, hora = ? WHERE id = ?");
                $stmtUp->execute([$nova_data, $novo_horario, $agendamento_id]);
                $gravou = true;
            } catch (PDOException $e) {
                // Indice unico de horario: outra pessoa pegou o horario agora.
                log_activity('Reagendamento pelo cliente recusado pelo banco: ' . $e->getMessage());
                $erroReagendamento = 'Esse horário acabou de ser reservado por outra pessoa. Escolha outro.';
            }
        }

        if ($is_meu_agendamento && $erroReagendamento !== '') {
            retornarResposta('erro', $erroReagendamento, $is_ajax);
        } elseif ($gravou) {
            if (function_exists('registrarHistoricoAgenda')) {
                registrarHistoricoAgenda($agendamento_id, 'Agendamento reagendado', 'Novo horário: ' . date('d/m/Y', strtotime($nova_data)) . ' às ' . $novo_horario, ($_SESSION['cliente_nome'] ?? 'Cliente') . ' (cliente)');
            }
            criarNotificacao($cliente_id, "Agendamento reagendado para ".date('d/m/Y', strtotime($nova_data))." às ".$novo_horario.".");
            retornarResposta('sucesso', "Horário reagendado com sucesso!", $is_ajax);
        } else {
            retornarResposta('erro', "Acesso Negado: Você não tem permissão para alterar este agendamento.", $is_ajax);
        }
    } else {
        retornarResposta('erro', "Erro ao localizar o agendamento.", $is_ajax);
    }
}

if ($action === 'excluir_conta') {
    $cliente = getClientById($cliente_id);
    $senha = $_POST['senha_confirma'] ?? '';
    $confirmacao = trim($_POST['confirmacao_texto'] ?? '');

    if (!$cliente) {
        retornarResposta('erro', 'Conta não encontrada.', $is_ajax);
    } elseif (empty($cliente['password_hash']) || !password_verify($senha, $cliente['password_hash'])) {
        retornarResposta('erro', 'Senha incorreta. A conta não foi excluída.', $is_ajax);
    } elseif (mb_strtoupper($confirmacao) !== 'EXCLUIR') {
        retornarResposta('erro', 'Digite EXCLUIR para confirmar a exclusão.', $is_ajax);
    } else {
        [$ok, $msg] = excluirContaClientePermanente($cliente_id);
        if ($ok) {
            if (function_exists('revogarCookieLembrarClienteAtual')) {
                revogarCookieLembrarClienteAtual();
            }
            $_SESSION = [];
            session_destroy();
            if ($is_ajax) {
                echo json_encode([
                    'status'   => 'success',
                    'message'  => 'Sua conta foi excluída permanentemente. Sentiremos sua falta!',
                    'redirect' => 'index'
                ]);
                exit;
            }
            header('Location: index?conta_excluida=1');
            exit;
        }
        retornarResposta('erro', $msg, $is_ajax);
    }
}
?>
