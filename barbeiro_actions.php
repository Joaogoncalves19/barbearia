<?php
require_once 'functions.php';
iniciarSessaoSegura();
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['barbeiro_loggedin'])) { header('Location: login.php'); exit; }

require_once 'functions.php';

// Colunas de comissão/metas/notas garantidas por lib/migrations.php (via getDB()).
try {
    $pdo = getDB();
    if (function_exists('garantirColunasComanda')) garantirColunasComanda();
} catch(Exception $e) {}

$action = $_POST['action'] ?? $_GET['action'] ?? null;
$id = $_POST['id'] ?? $_GET['id'] ?? null;
$barbeiro_id = $_SESSION['barbeiro_id'];

// --- AJAX: DADOS DA COMANDA DE UM ATENDIMENTO ---
if ($action === 'comanda_dados' && !empty($id)) {
    header('Content-Type: application/json');
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM agendamentos WHERE id = ? AND barbeiro_id = ?");
    $stmt->execute([$id, $barbeiro_id]);
    $ag = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ag) { echo json_encode(['success' => false, 'message' => 'Atendimento não encontrado.']); exit; }

    $servicosArr = lerDados('servicos', ['id', 'nome', 'valor']);
    $combosArr   = lerDados('combos', ['id', 'nome', 'valor']);

    // Desconto de assinatura recalculado AO VIVO pela assinatura ativa do cliente.
    // Cobre casos em que o benefício não ficou gravado no agendamento (cliente
    // assinou depois de marcar, encaixe manual sem cliente selecionado, etc.).
    $assinaturaCalc = calcularDescontoAssinaturaCliente($ag['cliente_id'] ?? '', (string)($ag['servicos_ids'] ?? ''));
    $cobertosPlano  = $assinaturaCalc['cobertos'];

    // Itens de serviço/combo já no agendamento
    $itensServicos = [];
    $subtotalServicos = 0;
    foreach (array_filter(array_map('trim', explode(',', (string)($ag['servicos_ids'] ?? '')))) as $sid) {
        if (isset($servicosArr[$sid])) {
            $v = (float)$servicosArr[$sid]['valor'];
            $itensServicos[] = ['nome' => $servicosArr[$sid]['nome'], 'valor' => $v, 'coberto' => in_array($sid, $cobertosPlano, true)];
            $subtotalServicos += $v;
        } elseif (isset($combosArr[$sid])) {
            $v = (float)$combosArr[$sid]['valor'];
            $itensServicos[] = ['nome' => $combosArr[$sid]['nome'] . ' (Combo)', 'valor' => $v, 'coberto' => in_array($sid, $cobertosPlano, true)];
            $subtotalServicos += $v;
        }
    }

    // Produtos já vendidos na comanda
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

    // Serviços que este barbeiro pode oferecer como extra
    $stmtB = $pdo->prepare("SELECT servicos_ids FROM barbeiros WHERE id = ?");
    $stmtB->execute([$barbeiro_id]);
    $espec = (string)($stmtB->fetchColumn() ?: '');
    // Serviços cobertos pelo plano ativo, para marcar extras como "grátis".
    $servicosPlanoCobertos = [];
    if ($assinaturaCalc['plano_nome'] !== '') {
        $assinaturaAtual = getAssinaturaCliente($ag['cliente_id'] ?? '');
        if ($assinaturaAtual) {
            $planosArr = lerDados('planos', ['id', 'servicos_ids']) ?: [];
            if (isset($planosArr[$assinaturaAtual['plano_id']])) {
                $servicosPlanoCobertos = array_filter(array_map('trim', explode(',', (string)$planosArr[$assinaturaAtual['plano_id']]['servicos_ids'])));
            }
        }
    }

    $servicosDisponiveis = [];
    foreach (array_filter(array_map('trim', explode(',', $espec))) as $sid) {
        if (isset($servicosArr[$sid])) {
            $servicosDisponiveis[] = [
                'id' => $sid,
                'nome' => $servicosArr[$sid]['nome'],
                'valor' => (float)$servicosArr[$sid]['valor'],
                'coberto' => in_array($sid, $servicosPlanoCobertos, true),
            ];
        }
    }

    // Usa o maior desconto entre o gravado (fidelidade/cupom/etc.) e o benefício
    // vivo da assinatura — nunca cobra por um serviço coberto pelo plano ativo.
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
}

if (($action === 'concluir' || $action === 'cancelar') && !empty($id)) {
    // Segurança: valida token CSRF (aceita via GET ou POST) antes de alterar estado.
    if (!verify_csrf_token($_REQUEST['csrf_token'] ?? '')) {
        header('Location: barbeiro.php?error=' . urlencode('Ação bloqueada por segurança. Recarregue a página e tente novamente.'));
        exit;
    }
    $novoStatus = ($action === 'concluir') ? 'concluido' : 'cancelado';
    $pdo = getDB();
    $stmtGet = $pdo->prepare("SELECT * FROM agendamentos WHERE id = ? AND barbeiro_id = ?");
    $stmtGet->execute([$id, $barbeiro_id]);
    $agendamento_atual = $stmtGet->fetch();
    
    if ($agendamento_atual) {
        $status_anterior = $agendamento_atual['status'];
        $stmtUp = $pdo->prepare("UPDATE agendamentos SET status = ? WHERE id = ?");
        $stmtUp->execute([$novoStatus, $id]);
        if (function_exists('registrarHistoricoAgenda')) {
            $rotuloAcao = ($novoStatus === 'concluido') ? 'Atendimento concluído' : 'Agendamento cancelado';
            registrarHistoricoAgenda($id, $rotuloAcao, 'Ação pelo painel do profissional');
        }

        // Conclusão direta (fora da comanda): grava o benefício da assinatura para
        // que serviços cobertos fiquem zerados na cobrança e nos relatórios.
        if ($novoStatus === 'concluido' && $status_anterior !== 'concluido') {
            $assinaturaConclusao = calcularDescontoAssinaturaCliente($agendamento_atual['cliente_id'] ?? '', (string)($agendamento_atual['servicos_ids'] ?? ''));
            $descontoGravado = max(0, (float)($agendamento_atual['desconto_aplicado'] ?? 0));
            if ((float)$assinaturaConclusao['desconto'] > $descontoGravado) {
                $pdo->prepare("UPDATE agendamentos SET desconto_aplicado = ?, tipo_desconto = ? WHERE id = ?")
                    ->execute([(float)$assinaturaConclusao['desconto'], 'assinatura_vip', $id]);
                $agendamento_atual['desconto_aplicado'] = (float)$assinaturaConclusao['desconto'];
                $agendamento_atual['tipo_desconto'] = 'assinatura_vip';
            }
        }

        $cliente = null;
        if (!empty($agendamento_atual['cliente_id'])) {
            $stmtCli = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
            $stmtCli->execute([$agendamento_atual['cliente_id']]);
            $cliente = $stmtCli->fetch();
        }

        if ($novoStatus === 'concluido' && $status_anterior !== 'concluido') {
            aplicarEfeitosConclusaoAtendimento($pdo, $agendamento_atual, $cliente);
        }
        
        $configAgendamentoEmail = carregarConfigAgendamento(); 
        if (($configAgendamentoEmail['notif_aprovacao'] ?? 0) == 1 && $novoStatus === 'cancelado') {
             $servicosArr = lerDados('servicos', ['id', 'nome']); $combosArr = lerDados('combos', ['id', 'nome']); $barbeirosArr = lerDados('barbeiros', ['id', 'nome']);
             $dados_email = [
                'nome_cliente' => $agendamento_atual['nome'], 'data_agendamento' => date('d/m/Y', strtotime($agendamento_atual['data'])), 'hora_agendamento' => $agendamento_atual['hora'],
                'servicos' => array_map(function($sid) use ($servicosArr, $combosArr) { $sid = trim($sid); return $servicosArr[$sid]['nome'] ?? $combosArr[$sid]['nome'] ?? 'Removido'; }, explode(',', $agendamento_atual['servicos_ids'])),
                'barbeiro' => $barbeirosArr[$agendamento_atual['barbeiro_id']]['nome'] ?? 'Não especificado', 'tipo_desconto' => $agendamento_atual['tipo_desconto'] ?? '' 
            ];
            enviarEmail($agendamento_atual['email'], 'Seu Agendamento foi Cancelado', 'cancelado', $dados_email);
        }

        if ($cliente && $novoStatus === 'cancelado') {
            criarNotificacao($cliente['id'], "Seu agendamento para ".date('d/m/Y', strtotime($agendamento_atual['data']))." às ".$agendamento_atual['hora']." foi CANCELADO pelo estabelecimento.");
        }
    }
    header('Location: barbeiro.php?success=' . urlencode('Status atualizado.'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { header('Location: barbeiro.php?error=' . urlencode('Falha de segurança.')); exit; }

    // --- AÇÃO: REAGENDAR (unificado no painel do barbeiro) ---
    if ($action === 'reagendar_agendamento') {
        $agendamento_id = $_POST['agendamento_id'] ?? '';
        $nova_data      = $_POST['reagendar_data'] ?? '';
        $novo_horario   = $_POST['reagendar_horario'] ?? '';

        if (empty($agendamento_id) || empty($nova_data) || empty($novo_horario)) {
            header('Location: barbeiro.php?error=' . urlencode('Escolha a nova data e o horário.'));
            exit;
        }

        $pdo = getDB();
        $stmtGet = $pdo->prepare("SELECT * FROM agendamentos WHERE id = ? AND barbeiro_id = ?");
        $stmtGet->execute([$agendamento_id, $barbeiro_id]);
        $ag = $stmtGet->fetch(PDO::FETCH_ASSOC);
        if (!$ag) {
            header('Location: barbeiro.php?error=' . urlencode('Agendamento não encontrado.'));
            exit;
        }

        // Verificação de ocupação no novo horário (ignora os slots do próprio agendamento).
        $servicosArr = lerDados('servicos', ['id', 'slots']);
        $combosArr   = lerDados('combos', ['id', 'servicos_ids']);
        $slotsNec = 0;
        foreach (array_filter(array_map('trim', explode(',', (string)$ag['servicos_ids']))) as $itemId) {
            if (isset($servicosArr[$itemId])) {
                $slotsNec += max(1, (int)($servicosArr[$itemId]['slots'] ?? 1));
            } elseif (isset($combosArr[$itemId])) {
                foreach (array_filter(array_map('trim', explode(',', $combosArr[$itemId]['servicos_ids'] ?? ''))) as $cs) {
                    $slotsNec += max(1, (int)($servicosArr[$cs]['slots'] ?? 1));
                }
            } else { $slotsNec++; }
        }
        $slotsNec = max(1, $slotsNec);

        // Slots que o próprio agendamento ocupa hoje (para não bloquear contra si mesmo).
        $slotsProprios = [];
        if (($ag['data'] ?? '') === $nova_data) {
            $iniProp = ((int)substr($ag['hora'], 0, 2) * 60) + (int)substr($ag['hora'], 3, 2);
            for ($s = 0; $s < $slotsNec; $s++) {
                $slotsProprios[] = sprintf('%02d:%02d', intdiv($iniProp + $s * 30, 60), ($iniProp + $s * 30) % 60);
            }
        }
        $ocupados = array_diff(getHorariosOcupados($barbeiro_id, $nova_data), $slotsProprios);
        $iniNovo = ((int)substr($novo_horario, 0, 2) * 60) + (int)substr($novo_horario, 3, 2);
        for ($s = 0; $s < $slotsNec; $s++) {
            $hSlot = sprintf('%02d:%02d', intdiv($iniNovo + $s * 30, 60), ($iniNovo + $s * 30) % 60);
            if (in_array($hSlot, $ocupados, true)) {
                header('Location: barbeiro.php?error=' . urlencode('O novo horário está ocupado. Escolha outro.'));
                exit;
            }
        }

        $stmtUp = $pdo->prepare("UPDATE agendamentos SET data = ?, hora = ?, status = 'aprovado' WHERE id = ?");
        $stmtUp->execute([$nova_data, $novo_horario, $agendamento_id]);
        if (function_exists('registrarHistoricoAgenda')) {
            registrarHistoricoAgenda($agendamento_id, 'Agendamento reagendado', 'Novo horário: ' . date('d/m/Y', strtotime($nova_data)) . ' às ' . $novo_horario);
        }

        // E-mail + notificação ao cliente.
        if (!empty($ag['email']) && filter_var($ag['email'], FILTER_VALIDATE_EMAIL)) {
            $servicosNomes = lerDados('servicos', ['id', 'nome']);
            $combosNomes   = lerDados('combos', ['id', 'nome']);
            $barbeirosNomes = lerDados('barbeiros', ['id', 'nome']);
            $nomesServ = array_map(function ($sid) use ($servicosNomes, $combosNomes) {
                $sid = trim($sid);
                return $servicosNomes[$sid]['nome'] ?? ($combosNomes[$sid]['nome'] ?? 'Serviço');
            }, array_filter(explode(',', (string)$ag['servicos_ids'])));
            enviarEmail($ag['email'], 'Seu Agendamento foi Reagendado!', 'reagendamento', [
                'nome_cliente' => $ag['nome'],
                'data_agendamento' => date('d/m/Y', strtotime($nova_data)),
                'hora_agendamento' => $novo_horario,
                'servicos' => $nomesServ,
                'barbeiro' => $barbeirosNomes[$ag['barbeiro_id']]['nome'] ?? 'Não especificado',
                'tipo_desconto' => $ag['tipo_desconto'] ?? ''
            ]);
        }
        if (!empty($ag['cliente_id'])) {
            criarNotificacao($ag['cliente_id'], "Seu agendamento foi REAGENDADO para " . date('d/m/Y', strtotime($nova_data)) . " às " . $novo_horario . ".");
        }

        $_SESSION['agendamento_sucesso'] = "Agendamento de " . $ag['nome'] . " reagendado com sucesso!";
        header('Location: barbeiro.php');
        exit;
    }

    // --- AÇÃO: AGENDAMENTO MANUAL (unificado no painel do barbeiro) ---
    if ($action === 'agendamento_manual') {
        $nome       = trim($_POST['manual_nome'] ?? '');
        $email      = trim($_POST['manual_email'] ?? '');
        $telefone   = limparTelefone($_POST['manual_telefone'] ?? '');
        $data       = $_POST['manual_data'] ?? '';
        $hora       = $_POST['manual_horario'] ?? '';
        $servicos_ids = $_POST['manual_servicos'] ?? '';
        $cliente_id_cad = $_POST['manual_cliente_id'] ?? '';

        // Se escolheu um cliente cadastrado, usa os dados oficiais dele.
        if (!empty($cliente_id_cad)) {
            $pdoCli = getDB();
            $stmtCli = $pdoCli->prepare("SELECT nome, telefone, email FROM clientes WHERE id = ?");
            $stmtCli->execute([$cliente_id_cad]);
            if ($cli = $stmtCli->fetch(PDO::FETCH_ASSOC)) {
                if ($nome === '') $nome = $cli['nome'] ?? '';
                if ($telefone === '') $telefone = limparTelefone($cli['telefone'] ?? '');
                if ($email === '') $email = $cli['email'] ?? '';
            }
        }

        if (empty($nome) || empty($telefone) || empty($data) || empty($hora) || empty($servicos_ids)) {
            header('Location: barbeiro.php?error=' . urlencode('Preencha nome, telefone, serviços, data e horário.'));
            exit;
        }

        $pdo = getDB();

        // Segurança: os serviços precisam ser do repertório deste barbeiro.
        $servicosArr = lerDados('servicos', ['id', 'nome', 'valor', 'slots']);
        $combosArr   = lerDados('combos', ['id', 'nome', 'valor', 'servicos_ids']);
        $stmtEsp = $pdo->prepare("SELECT servicos_ids FROM barbeiros WHERE id = ?");
        $stmtEsp->execute([$barbeiro_id]);
        $espec = array_filter(array_map('trim', explode(',', (string)($stmtEsp->fetchColumn() ?: ''))));
        $idsSel = array_values(array_filter(array_map('trim', explode(',', $servicos_ids))));
        foreach ($idsSel as $sid) {
            if (!in_array($sid, $espec, true)) {
                header('Location: barbeiro.php?error=' . urlencode('Você não realiza um dos serviços selecionados.'));
                exit;
            }
        }
        $servicos_ids = implode(',', $idsSel);

        // Duração total (slots) e verificação de ocupação (não sobrescreve horário).
        $slotsNecessarios = 0;
        foreach ($idsSel as $itemId) {
            if (isset($servicosArr[$itemId])) {
                $slotsNecessarios += max(1, (int)($servicosArr[$itemId]['slots'] ?? 1));
            } elseif (isset($combosArr[$itemId])) {
                foreach (array_filter(array_map('trim', explode(',', $combosArr[$itemId]['servicos_ids'] ?? ''))) as $cs) {
                    $slotsNecessarios += max(1, (int)($servicosArr[$cs]['slots'] ?? 1));
                }
            } else {
                $slotsNecessarios++;
            }
        }
        $slotsNecessarios = max(1, $slotsNecessarios);
        $ocupados = getHorariosOcupados($barbeiro_id, $data);
        $inicioMin = ((int)substr($hora, 0, 2) * 60) + (int)substr($hora, 3, 2);
        for ($s = 0; $s < $slotsNecessarios; $s++) {
            $hSlot = sprintf('%02d:%02d', intdiv($inicioMin + $s * 30, 60), ($inicioMin + $s * 30) % 60);
            if (in_array($hSlot, $ocupados, true)) {
                header('Location: barbeiro.php?error=' . urlencode('O horário escolhido ficou indisponível. Selecione outro.'));
                exit;
            }
        }

        // Desconto de assinatura: serviços cobertos pelo plano ativo do cliente
        // entram zerados (cobre também assinantes com cancelamento agendado).
        $assinaturaManual = calcularDescontoAssinaturaCliente($cliente_id_cad, $servicos_ids);
        $desconto_aplicado = $assinaturaManual['desconto'];
        $tipo_desconto     = $assinaturaManual['tipo'];

        $id_agendamento = 'AG-' . strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6));
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS agendamentos (id TEXT PRIMARY KEY, nome TEXT, email TEXT, telefone TEXT, barbeiro_id TEXT, servicos_ids TEXT, data TEXT, hora TEXT, status TEXT, desconto_aplicado TEXT, tipo_desconto TEXT, observacoes TEXT, produtos_vendidos TEXT, plano_provisorio TEXT, cliente_id TEXT, data_criacao TEXT)");
            $stmt = $pdo->prepare("INSERT INTO agendamentos (id, nome, email, telefone, barbeiro_id, servicos_ids, data, hora, status, desconto_aplicado, tipo_desconto, observacoes, produtos_vendidos, plano_provisorio, cliente_id, data_criacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'aprovado', ?, ?, '', '', '', ?, ?)");
            $stmt->execute([$id_agendamento, $nome, $email, $telefone, $barbeiro_id, $servicos_ids, $data, $hora, $desconto_aplicado, $tipo_desconto, $cliente_id_cad, date('Y-m-d H:i:s')]);
            if (function_exists('registrarHistoricoAgenda')) {
                registrarHistoricoAgenda($id_agendamento, 'Agendamento criado', 'Encaixe manual pelo profissional');
            }
        } catch (PDOException $e) {
            if (function_exists('log_activity')) log_activity("Erro no agendamento manual (barbeiro): " . $e->getMessage());
            header('Location: barbeiro.php?error=' . urlencode('Erro ao salvar o agendamento.'));
            exit;
        }

        $_SESSION['agendamento_sucesso'] = "Agendamento manual para " . $nome . " criado com sucesso!";
        header('Location: barbeiro.php');
        exit;
    }

    if ($action === 'salvar_nota_cliente') {
        $cliente_id = $_POST['cliente_id'];
        $notas = $_POST['anotacao'] ?? $_POST['notas'] ?? '';
        
        $pdo = getDB();
        $stmt = $pdo->prepare("UPDATE clientes SET notas_barbeiro = ? WHERE id = ?");
        $stmt->execute([$notas, $cliente_id]);
        header('Location: barbeiro.php?tab=agenda&success=' . urlencode('Anotações salvas com sucesso!'));
        exit;
    }

    if ($action === 'fechar_comanda') {
        $agendamento_id  = $_POST['agendamento_id'] ?? '';
        $servicos_extras = array_values(array_unique(array_filter(array_map('trim', (array)($_POST['servicos_extras'] ?? [])))));
        $gorjeta         = max(0, (float)str_replace(',', '.', (string)($_POST['gorjeta'] ?? 0)));
        $forma_pagamento = (string)($_POST['forma_pagamento'] ?? '');
        if (!in_array($forma_pagamento, ['dinheiro', 'pix', 'debito', 'credito', 'outro', ''], true)) {
            $forma_pagamento = '';
        }

        $pdo = getDB();
        $stmtGet = $pdo->prepare("SELECT * FROM agendamentos WHERE id = ? AND barbeiro_id = ?");
        $stmtGet->execute([$agendamento_id, $barbeiro_id]);
        $agendamento_atual = $stmtGet->fetch(PDO::FETCH_ASSOC);

        if (!$agendamento_atual) {
            header('Location: barbeiro.php?error=' . urlencode('Atendimento não encontrado.'));
            exit;
        }

        // Valida serviços extras: precisam existir e ser especialidade do barbeiro.
        if (!empty($servicos_extras)) {
            $servicosArr = lerDados('servicos', ['id', 'nome']);
            $stmtB = $pdo->prepare("SELECT servicos_ids FROM barbeiros WHERE id = ?");
            $stmtB->execute([$barbeiro_id]);
            $espec = array_filter(array_map('trim', explode(',', (string)($stmtB->fetchColumn() ?: ''))));
            $servicos_extras = array_values(array_filter($servicos_extras, function ($sid) use ($servicosArr, $espec) {
                return isset($servicosArr[$sid]) && in_array($sid, $espec, true);
            }));
        }

        // Anexa os serviços extras aos serviços já existentes.
        $idsAtuais = array_filter(array_map('trim', explode(',', (string)($agendamento_atual['servicos_ids'] ?? ''))));
        $novosIds  = array_values(array_unique(array_merge($idsAtuais, $servicos_extras)));
        $servicos_ids_final = implode(',', $novosIds);

        $status_anterior = $agendamento_atual['status'] ?? '';

        // Recalcula o benefício da assinatura sobre os serviços finais (já com os
        // extras) e persiste o desconto, para que serviços cobertos pelo plano
        // fiquem zerados na cobrança, nos relatórios e no histórico. Mantém um
        // desconto gravado maior (fidelidade/cupom) se for o caso.
        $assinaturaFechamento = calcularDescontoAssinaturaCliente($agendamento_atual['cliente_id'] ?? '', $servicos_ids_final);
        $descontoGravado = max(0, (float)($agendamento_atual['desconto_aplicado'] ?? 0));
        if ((float)$assinaturaFechamento['desconto'] > $descontoGravado) {
            $desconto_final = (float)$assinaturaFechamento['desconto'];
            $tipo_desconto_final = 'assinatura_vip';
        } else {
            $desconto_final = $descontoGravado;
            $tipo_desconto_final = (string)($agendamento_atual['tipo_desconto'] ?? '');
        }

        $stmtUp = $pdo->prepare("UPDATE agendamentos SET servicos_ids = ?, gorjeta = ?, forma_pagamento = ?, comanda_fechada_em = ?, desconto_aplicado = ?, tipo_desconto = ?, status = 'concluido' WHERE id = ?");
        $stmtUp->execute([$servicos_ids_final, $gorjeta, $forma_pagamento, date('Y-m-d H:i:s'), $desconto_final, $tipo_desconto_final, $agendamento_id]);
        if (function_exists('registrarHistoricoAgenda')) {
            $detComanda = 'Comanda: R$ ' . number_format($gorjeta, 2, ',', '.') . ' de gorjeta';
            if ($forma_pagamento !== '' && function_exists('rotuloFormaPagamento')) {
                $detComanda .= ' · ' . rotuloFormaPagamento($forma_pagamento);
            }
            registrarHistoricoAgenda($agendamento_id, 'Comanda fechada (atendimento concluído)', $detComanda);
        }

        // Efeitos de conclusão (apenas se ainda não estava concluído).
        if ($status_anterior !== 'concluido') {
            $cliente = null;
            if (!empty($agendamento_atual['cliente_id'])) {
                $stmtCli = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
                $stmtCli->execute([$agendamento_atual['cliente_id']]);
                $cliente = $stmtCli->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            $agendamento_atual['servicos_ids'] = $servicos_ids_final;
            aplicarEfeitosConclusaoAtendimento($pdo, $agendamento_atual, $cliente);
        }

        header('Location: barbeiro.php?success=' . urlencode('Comanda fechada e atendimento concluído!'));
        exit;
    }

    if ($action === 'adicionar_produto') {
        $agendamento_id = $_POST['agendamento_id']; $produto_id = $_POST['produto_id']; $qtd_vendida = (int)($_POST['qtd_vendida'] ?? 1);
        $agendamentosArr = lerDados('agendamentos', ['id', 'barbeiro_id', 'produtos_vendidos']);
        
        if (!empty($agendamento_id) && !empty($produto_id) && $qtd_vendida > 0 && isset($agendamentosArr[$agendamento_id]) && $agendamentosArr[$agendamento_id]['barbeiro_id'] === $barbeiro_id) {
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT * FROM produtos WHERE id = ?"); $stmt->execute([$produto_id]); $produto = $stmt->fetch();

            if ($produto && $produto['quantidade'] >= $qtd_vendida) {
                $stmtAg = $pdo->prepare("SELECT produtos_vendidos FROM agendamentos WHERE id = ?"); $stmtAg->execute([$agendamento_id]); $agRow = $stmtAg->fetch();
                $produtos_atuais = !empty($agRow['produtos_vendidos']) ? json_decode($agRow['produtos_vendidos'], true) : [];
                $produtos_atuais[] = ['nome' => $produto['nome']." (".$qtd_vendida."x)", 'valor' => (float)$produto['valor'] * $qtd_vendida, 'custo' => (float)($produto['custo'] ?? 0) * $qtd_vendida, 'produto_id' => $produto_id];
                
                $pdo->prepare("UPDATE agendamentos SET produtos_vendidos = ? WHERE id = ?")->execute([json_encode($produtos_atuais), $agendamento_id]);
                $pdo->prepare("UPDATE produtos SET quantidade = ? WHERE id = ?")->execute([$produto['quantidade'] - $qtd_vendida, $produto_id]);

                $pdo->exec("CREATE TABLE IF NOT EXISTS estoque_logs (id TEXT PRIMARY KEY, produto_id TEXT, tipo TEXT, quantidade INTEGER, motivo TEXT, data_hora TEXT, usuario TEXT)");
                $pdo->prepare("INSERT INTO estoque_logs (id, produto_id, tipo, quantidade, motivo, data_hora, usuario) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([gerarId('log-'), $produto_id, 'saida', $qtd_vendida, "Venda no Agendamento ".$agendamento_id, date('Y-m-d H:i:s'), 'Barbeiro']);

                header('Location: barbeiro.php?success=' . urlencode('Produto adicionado.')); exit;
            } else { header('Location: barbeiro.php?error=' . urlencode('Estoque insuficiente.')); exit; }
        }
        header('Location: barbeiro.php?error=' . urlencode('Erro na venda.')); exit;
    }

    if ($action === 'salvar_config_almoco') {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS config_almoco_barbeiro (barbeiro_id TEXT PRIMARY KEY, status TEXT, horario TEXT)");
        $pdo->prepare("INSERT OR REPLACE INTO config_almoco_barbeiro (barbeiro_id, status, horario) VALUES (?, ?, ?)")->execute([$barbeiro_id, $_POST['status_almoco'], $_POST['horario_almoco']]);
        header('Location: barbeiro.php?success=' . urlencode('Pausa de almoço salva com sucesso.')); exit;
    }

    if ($action === 'atualizar_perfil') {
        $nome = trim($_POST['nome']); 
        $senha = trim($_POST['senha']);
        if (!empty($senha) && !senhaAtendePolitica($senha)) {
            header('Location: barbeiro.php?tab=perfil&error=' . urlencode('A nova senha deve ter pelo menos 8 caracteres.'));
            exit;
        }
        $meta_diaria = isset($_POST['meta_diaria']) ? (float)$_POST['meta_diaria'] : 200;
        
        $pdo = getDB();
        $caminho_foto = null;
        if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
            [$uploadOk, $uploadResultado] = salvarUploadSeguro(
                $_FILES['foto'],
                'perfil-' . $barbeiro_id,
                ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
                3 * 1024 * 1024,
                'barbeiro-' . $barbeiro_id
            );
            if ($uploadOk) {
                $caminho_foto = $uploadResultado;
            }
        }

        $sql = "UPDATE barbeiros SET nome = ?, meta_diaria = ?";
        $params = [$nome, $meta_diaria];
        
        if (!empty($senha)) { 
            $sql .= ", password = ?"; 
            $params[] = password_hash($senha, PASSWORD_DEFAULT); 
        }
        if ($caminho_foto) { 
            $sql .= ", foto = ?"; 
            $params[] = $caminho_foto; 
        }
        $sql .= " WHERE id = ?"; 
        $params[] = $barbeiro_id;
        
        $pdo->prepare($sql)->execute($params);
        
        header('Location: barbeiro.php?tab=perfil&success=' . urlencode('Perfil atualizado com sucesso!'));
        exit;
    }
}
header('Location: barbeiro.php');
