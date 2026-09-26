<?php
// assistente.php — Assistente do site (baseado em REGRAS; IA opcional como reforço).
// OBS: nome sem a palavra "chat" de propósito — o firewall do InfinityFree bloqueia
// (403) URLs que contenham "chat" (ex.: chatbot.php). Não renomear de volta.
// Somente leitura do banco + orientação para os fluxos seguros existentes.
require_once 'functions.php';
require_once __DIR__ . '/lib/chatbot_agent.php';
iniciarSessaoSegura();
date_default_timezone_set('America/Sao_Paulo');

// Em produção, um warning/notice/erro do PHP no meio da saída quebraria o
// JSON.parse do navegador (erro "problema de conexão"). Garantimos que a
// resposta seja SEMPRE JSON válido: silenciamos a exibição de erros, ligamos
// um buffer de saída (descartado antes de imprimir o JSON) e registramos uma
// rede de segurança para erros fatais.
@ini_set('display_errors', '0');
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
ob_start();
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
        if (ob_get_length() !== false) { @ob_clean(); }
        echo json_encode([
            'reply'   => 'Tive um problema técnico aqui. Tente novamente em instantes. 🙏',
            'options' => [['label' => '↩️ Menu', 'intent' => 'menu']],
        ], JSON_UNESCAPED_UNICODE);
    }
});

// Cliente logado? (agendamento pelo chat exige cadastro — logado OU telefone cadastrado)
$clienteLogado = null;
if (!empty($_SESSION['cliente_logado']) && !empty($_SESSION['cliente_id'])) {
    try {
        $stmtCL = getDB()->prepare("SELECT id, nome, telefone FROM clientes WHERE id = ? AND (status IS NULL OR status != 'inativo') LIMIT 1");
        $stmtCL->execute([$_SESSION['cliente_id']]);
        $clienteLogado = $stmtCL->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) { $clienteLogado = null; }
}

$cfgBot = carregarConfigChatbot();
if (empty($cfgBot['ativo'])) {
    echo json_encode(['reply' => 'O assistente está desativado no momento.', 'options' => []]);
    exit;
}

// Aceita tanto JSON (corpo cru) quanto formulário (application/x-www-form-urlencoded).
// Alguns hosts (ex.: InfinityFree) bloqueiam POST com Content-Type application/json
// no firewall (403), então o widget envia como formulário e lemos de $_POST.
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
    if (isset($input['payload']) && is_string($input['payload'])) {
        $decodedPayload = json_decode($input['payload'], true);
        $input['payload'] = is_array($decodedPayload) ? $decodedPayload : [];
    }
}
if (!is_array($input)) $input = [];
$mensagem = trim((string)($input['message'] ?? ''));
$intent   = trim((string)($input['intent'] ?? ''));
$payload  = (isset($input['payload']) && is_array($input['payload'])) ? $input['payload'] : [];
if (mb_strlen($mensagem) > 500) $mensagem = mb_substr($mensagem, 0, 500);

// ---------------------------------------------------------------------------
// LIMITE DE USO POR IP
// Este endpoint e publico, chama IA cobrada por token e (com o agente ligado)
// escreve na agenda. Contamos DEPOIS de ler o corpo para nao gastar contador
// com requisicao vazia, e ANTES de qualquer roteamento/chamada de IA.
// A resposta de bloqueio e uma mensagem normal do bot (JSON valido), para o
// widget seguir funcionando em vez de mostrar "problema de conexao".
// ---------------------------------------------------------------------------
if ($mensagem !== '' || $intent !== '') {
    $botBloqueadoAte = function_exists('assistenteBloqueadoAte') ? assistenteBloqueadoAte() : 0;
    if ($botBloqueadoAte > 0) {
        $minutosBot = max(1, (int) ceil(($botBloqueadoAte - time()) / 60));
        echo json_encode([
            'reply'   => "Recebi muitas mensagens seguidas por aqui. 😅 Aguarde {$minutosBot} minuto(s) e tente de novo — ou agende direto pela página de agendamento do site.",
            'options' => [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (function_exists('registrarUsoAssistente')) {
        registrarUsoAssistente();
    }
}

$configGeral = carregarConfigGeral();
$nomeBarbearia = $configGeral['nome_barbearia'] ?? 'a barbearia';

// ---------- helpers ----------
function _botNorm($s) {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
    return $s;
}
function _botMenuOptions($logged = null) {
    // Quando não informado, detecta pela sessão atual (global definido no topo).
    if ($logged === null) $logged = !empty($GLOBALS['clienteLogado']);
    $opts = [
        ['label' => '✂️ Serviços e preços', 'intent' => 'servicos'],
        ['label' => '📅 Horários livres', 'intent' => 'disponibilidade'],
        ['label' => '🗓️ Agendar agora', 'intent' => 'agendar'],
        ['label' => '👑 Planos/assinatura', 'intent' => 'planos'],
        ['label' => '💈 Profissionais', 'intent' => 'profissionais'],
        ['label' => '🕒 Funcionamento', 'intent' => 'horarios'],
        ['label' => '📍 Endereço e contato', 'intent' => 'contato'],
    ];
    if ($logged) {
        array_unshift($opts,
            ['label' => '📋 Meus agendamentos', 'intent' => 'meus_agendamentos'],
            ['label' => '⭐ Meus pontos', 'intent' => 'meus_pontos']
        );
    }
    return $opts;
}
function _botResp($reply, $options = [], $extra = []) {
    // Descarta qualquer saída acidental (warnings/BOM) antes de imprimir o JSON.
    if (ob_get_length() !== false && ob_get_length() > 0) { @ob_clean(); }
    echo json_encode(array_merge(['reply' => $reply, 'options' => $options], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Cria o agendamento a partir do fluxo do chatbot (com validações e checagem de ocupação).
 * @return array ['ok'=>bool, 'erro'=>string, 'id'=>string]
 */
function _botCriarAgendamento($p) {
    $sid  = (string)($p['servico_id'] ?? '');
    $bid  = (string)($p['barbeiro_id'] ?? '');
    $data = (string)($p['data'] ?? '');
    $hora = (string)($p['hora'] ?? '');
    $nome = trim((string)($p['cliente_nome'] ?? ''));
    $tel  = limparTelefone((string)($p['cliente_telefone'] ?? ''));

    if ($sid === '' || $bid === '' || $nome === '' || $tel === '' ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || !preg_match('/^\d{2}:\d{2}$/', $hora)) {
        return ['ok' => false, 'erro' => 'Dados do agendamento incompletos.'];
    }
    if (strtotime("$data $hora") < time()) {
        return ['ok' => false, 'erro' => 'Esse horário já passou.'];
    }

    $pdo = getDB();
    // Serviço válido?
    $servicosArr = lerDados('servicos', ['id', 'nome', 'slots', 'valor']);
    if (!isset($servicosArr[$sid])) return ['ok' => false, 'erro' => 'Serviço indisponível.'];
    $slots = max(1, (int)($servicosArr[$sid]['slots'] ?? 1));

    // Barbeiro ativo e realiza o serviço?
    $stmtB = $pdo->prepare("SELECT nome, status, servicos_ids FROM barbeiros WHERE id = ?");
    $stmtB->execute([$bid]);
    $barb = $stmtB->fetch(PDO::FETCH_ASSOC);
    if (!$barb || ($barb['status'] ?? 'ativo') === 'inativo') return ['ok' => false, 'erro' => 'Profissional indisponível.'];
    $esp = array_filter(array_map('trim', explode(',', (string)($barb['servicos_ids'] ?? ''))));
    if (!in_array($sid, $esp, true)) return ['ok' => false, 'erro' => 'O profissional não realiza esse serviço.'];

    // Dentro do expediente?
    $exp = getHorarioDeTrabalho($bid, (int)date('w', strtotime($data)), $data);
    if (!$exp) return ['ok' => false, 'erro' => 'O profissional não atende nesse dia.'];

    // Checagem de ocupação (todos os slots necessários).
    $ocupados = getHorariosOcupados($bid, $data);
    $ini = ((int)substr($hora, 0, 2) * 60) + (int)substr($hora, 3, 2);
    for ($k = 0; $k < $slots; $k++) {
        $hk = sprintf('%02d:%02d', intdiv($ini + $k * 30, 60), ($ini + $k * 30) % 60);
        if (in_array($hk, $ocupados, true) || $hk >= substr($exp['fim'], 0, 5)) {
            return ['ok' => false, 'erro' => 'Esse horário acabou de ficar indisponível.'];
        }
    }

    // Vincula a um cliente existente pelo telefone (se houver).
    $cliente_id = '';
    try {
        $stmtC = $pdo->prepare("SELECT id FROM clientes WHERE telefone = ? LIMIT 1");
        $stmtC->execute([$tel]);
        $cliente_id = (string)($stmtC->fetchColumn() ?: '');
    } catch (Exception $e) {}

    // Benefício de assinatura: o mesmo cálculo do agendamento online
    // (processar_agendamento.php) e da comanda, centralizado em
    // calcularDescontoAssinaturaCliente(). Sem isto o agendamento nascia com
    // desconto 0 e o assinante era cobrado pelo serviço que o plano cobre.
    $desconto_aplicado = 0.0;
    $tipo_desconto = '';
    $assinatura_calc = ['desconto' => 0.0, 'tipo' => '', 'plano_nome' => '', 'a_pagar' => (float)($servicosArr[$sid]['valor'] ?? 0)];
    if ($cliente_id !== '' && function_exists('calcularDescontoAssinaturaCliente')) {
        $calc = calcularDescontoAssinaturaCliente($cliente_id, $sid);
        if (!empty($calc['desconto']) && $calc['desconto'] > 0) {
            $desconto_aplicado = (float)$calc['desconto'];
            $tipo_desconto = (string)$calc['tipo'];
            $assinatura_calc = $calc;
        }
    }

    $id_ag = 'AG-' . strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6));
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS agendamentos (id TEXT PRIMARY KEY, nome TEXT, email TEXT, telefone TEXT, barbeiro_id TEXT, servicos_ids TEXT, data TEXT, hora TEXT, status TEXT, desconto_aplicado TEXT, tipo_desconto TEXT, observacoes TEXT, produtos_vendidos TEXT, plano_provisorio TEXT, cliente_id TEXT, data_criacao TEXT)");
        $stmt = $pdo->prepare("INSERT INTO agendamentos (id, nome, email, telefone, barbeiro_id, servicos_ids, data, hora, status, desconto_aplicado, tipo_desconto, observacoes, produtos_vendidos, plano_provisorio, cliente_id, data_criacao) VALUES (?, ?, '', ?, ?, ?, ?, ?, 'aprovado', ?, ?, 'Agendado pelo assistente do site', '', '', ?, ?)");
        $stmt->execute([$id_ag, htmlspecialchars($nome), $tel, $bid, $sid, $data, $hora, $desconto_aplicado, $tipo_desconto, $cliente_id, date('Y-m-d H:i:s')]);
        if (function_exists('registrarHistoricoAgenda')) {
            registrarHistoricoAgenda($id_ag, 'Agendamento criado', 'Feito pelo chatbot do site', $nome . ' (chatbot)');
        }
        if ($cliente_id !== '' && function_exists('criarNotificacao')) {
            criarNotificacao($cliente_id, "Agendamento confirmado para " . date('d/m/Y', strtotime($data)) . " às $hora. Até logo!");
        }
    } catch (Exception $e) {
        return ['ok' => false, 'erro' => 'Erro ao salvar. Tente novamente.'];
    }
    return ['ok' => true, 'id' => $id_ag,
        'desconto' => $desconto_aplicado, 'tipo_desconto' => $tipo_desconto,
        'plano_nome' => (string)($assinatura_calc['plano_nome'] ?? ''),
        'a_pagar' => (float)($assinatura_calc['a_pagar'] ?? 0)];
}

/**
 * Cria o agendamento e responde (confirmação ou erro). Encerra a execução.
 */
function _botConfirmarAgendamento($payload) {
    $res = _botCriarAgendamento($payload);
    if ($res['ok']) {
        $linhaAssinatura = '';
        if (!empty($res['desconto']) && $res['desconto'] > 0) {
            $linhaAssinatura = '👑 Incluso na sua assinatura'
                . (!empty($res['plano_nome']) ? ' <em>(' . htmlspecialchars($res['plano_nome']) . ')</em>' : '')
                . ' — nada a pagar pelo serviço.<br>';
        }
        _botResp('✅ <strong>Agendamento confirmado!</strong><br><br>'
            . '✂️ ' . htmlspecialchars($payload['servico_nome'] ?? 'Serviço') . '<br>'
            . '💈 ' . htmlspecialchars($payload['barbeiro_nome'] ?? '') . '<br>'
            . '📅 ' . date('d/m/Y', strtotime($payload['data'])) . ' às ' . htmlspecialchars($payload['hora']) . '<br>'
            . $linhaAssinatura . '<br>'
            . 'Até logo, ' . htmlspecialchars($payload['cliente_nome'] ?? '') . '! 😊', [
            ['label' => '👤 Ver na Minha Conta', 'url' => 'cliente'],
            ['label' => '↩️ Menu', 'intent' => 'menu'],
        ]);
    }
    _botResp('😕 ' . htmlspecialchars($res['erro'] ?? 'Não consegui concluir.') . ' Vamos tentar outro horário?', [
        ['label' => '📅 Escolher outro horário', 'intent' => 'book_hora', 'payload' => $payload],
        ['label' => '➡️ Abrir agenda completa', 'url' => 'agendamento'],
        ['label' => '↩️ Menu', 'intent' => 'menu'],
    ]);
}

/** Define a sessão do cliente autenticado (mesmas chaves do login_cliente). */
function _botDefinirSessaoCliente($cli) {
    $_SESSION['cliente_logado']   = true;
    $_SESSION['cliente_id']       = $cli['id'];
    $_SESSION['cliente_nome']     = $cli['nome'];
    $_SESSION['cliente_email']    = $cli['email'] ?? '';
    $_SESSION['cliente_telefone'] = $cli['telefone'] ?? '';
}

/** Remove do payload os campos temporários de login/cadastro (não vazam ao reiniciar). */
function _botLimparAuth($p) {
    foreach (['login_id', 'reg_nome', 'reg_email', 'reg_cpf', 'reg_telefone', 'reg_nascimento'] as $k) {
        unset($p[$k]);
    }
    return $p;
}

/** Há um agendamento pendente aguardando autenticação dentro do payload? */
function _botTemBookingPendente($p) {
    return !empty($p['servico_id']) && !empty($p['barbeiro_id']) && !empty($p['data']) && !empty($p['hora']);
}

/**
 * Após login/cadastro: se havia um agendamento pendente, conclui-o; senão, saúda.
 * Encerra a execução.
 */
function _botAposAutenticar($cli, $payload) {
    if (_botTemBookingPendente($payload)) {
        $tel = limparTelefone((string)($cli['telefone'] ?? ''));
        $payload['cliente_nome'] = $cli['nome'];
        if (strlen($tel) >= 10) {
            $payload['cliente_telefone'] = $tel;
            _botConfirmarAgendamento($payload); // encerra
        }
        _botResp('✅ Você entrou como <strong>' . htmlspecialchars($cli['nome']) . '</strong>! Só falta confirmar seu <strong>telefone/WhatsApp</strong> (com DDD): 📱',
            [['label' => '✖️ Cancelar', 'intent' => 'menu']], ['await' => 'telefone_self', 'payload' => $payload]);
    }
    _botResp('✅ Você entrou como <strong>' . htmlspecialchars($cli['nome']) . '</strong>! Como posso ajudar? 😊', _botMenuOptions());
}

/** Converte data digitada (DD/MM/AAAA ou AAAA-MM-DD) em AAAA-MM-DD, ou '' se inválida. */
function _botParseData($s) {
    $s = trim($s);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return $s;
    }
    if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})$#', $s, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    return '';
}

/**
 * Cria uma conta de cliente pelo chatbot (status 'ativo', sem confirmação por e-mail).
 * @return array ['ok'=>bool, 'erro'=>string, 'cliente'=>array]
 */
function _botCriarConta($p, $senha) {
    $nome = trim((string)($p['reg_nome'] ?? ''));
    $email = trim((string)($p['reg_email'] ?? ''));
    $cpf  = limparTelefone((string)($p['reg_cpf'] ?? ''));
    $tel  = limparTelefone((string)($p['reg_telefone'] ?? ''));
    $nasc = (string)($p['reg_nascimento'] ?? '');

    if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !validarCPF($cpf) || strlen($tel) < 10 || !senhaAtendePolitica($senha)) {
        return ['ok' => false, 'erro' => 'Dados de cadastro incompletos.'];
    }
    // Revalida unicidade (evita corrida entre etapas).
    if (getClientePorEmailTelefoneOuCPF($email) || getClientePorEmailTelefoneOuCPF($cpf) || getClientePorEmailTelefoneOuCPF($tel)) {
        return ['ok' => false, 'erro' => 'Já existe uma conta com esses dados.'];
    }

    $nomeDb = htmlspecialchars($nome); // mesma convenção do registro
    $id = 'CL-' . strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6));
    $codigo = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6));
    $hash = password_hash($senha, PASSWORD_DEFAULT);
    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS clientes (id TEXT PRIMARY KEY, nome TEXT, email TEXT, telefone TEXT, password_hash TEXT, data_nascimento TEXT, foto_perfil TEXT, codigo_indicacao TEXT, cpf TEXT, indicado_por_id TEXT, status TEXT, confirmation_token TEXT)");
        $stmt = $pdo->prepare("INSERT INTO clientes (id, nome, email, telefone, password_hash, data_nascimento, foto_perfil, codigo_indicacao, cpf, indicado_por_id, status, confirmation_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '', 'ativo', '')");
        $stmt->execute([$id, $nomeDb, $email, $tel, $hash, $nasc, 'uploads/default-profile.jpg', $codigo, $cpf]);
    } catch (Exception $e) {
        return ['ok' => false, 'erro' => 'Não consegui criar a conta agora. Tente novamente.'];
    }
    return ['ok' => true, 'cliente' => ['id' => $id, 'nome' => $nomeDb, 'email' => $email, 'telefone' => $tel, 'status' => 'ativo']];
}

/**
 * Lista os agendamentos de um cliente (por cliente_id ou telefone), já com nomes
 * de serviço/profissional resolvidos e ordenados por data. Só considera status
 * úteis ('aprovado'/'pendente').
 * @return array de ['id','data','hora','ts','barbeiro','servicos','status']
 */
function _botAgendamentosCliente($cli, $somenteFuturos = true) {
    if (empty($cli['id'])) return [];
    $servicos  = lerDados('servicos', ['id', 'nome', 'valor']);
    $combos    = lerDados('combos', ['id', 'nome']);
    $barbeiros = lerDados('barbeiros', ['id', 'nome']);
    $ags = lerDados('agendamentos', ['id', 'nome', 'telefone', 'barbeiro_id', 'servicos_ids', 'data', 'hora', 'status', 'cliente_id']);
    $tel = limparTelefone((string)($cli['telefone'] ?? ''));
    $agora = time();
    $out = [];
    foreach ($ags as $ag) {
        $pertence = !empty($ag['cliente_id'])
            ? ($ag['cliente_id'] === $cli['id'])
            : ($tel !== '' && limparTelefone((string)($ag['telefone'] ?? '')) === $tel);
        if (!$pertence) continue;
        if (!in_array($ag['status'] ?? '', ['aprovado', 'pendente'], true)) continue;
        $ts = strtotime(($ag['data'] ?? '') . ' ' . ($ag['hora'] ?? ''));
        if ($somenteFuturos && $ts < $agora) continue;
        $nomes = [];
        foreach (explode(',', (string)($ag['servicos_ids'] ?? '')) as $sid) {
            $sid = trim($sid);
            if (isset($servicos[$sid])) $nomes[] = $servicos[$sid]['nome'];
            elseif (isset($combos[$sid])) $nomes[] = $combos[$sid]['nome'];
        }
        $out[] = [
            'id' => $ag['id'], 'data' => $ag['data'], 'hora' => $ag['hora'], 'ts' => $ts,
            'barbeiro' => $barbeiros[$ag['barbeiro_id']]['nome'] ?? '—',
            'servicos' => $nomes ? implode(', ', $nomes) : 'Serviço',
            'status' => $ag['status'],
        ];
    }
    usort($out, fn($a, $b) => $a['ts'] - $b['ts']);
    return $out;
}

/**
 * Monta um resumo textual do cliente logado (próximo agendamento, pontos de
 * fidelidade, assinatura) para alimentar o contexto da IA — nunca vaza dados de
 * outro cliente, pois usa apenas a conta da sessão atual.
 */
function _botResumoCliente($cli) {
    if (empty($cli['id'])) return 'Visitante não logado.';
    $linhas = ['Cliente logado: ' . ($cli['nome'] ?? '')];
    $ags = _botAgendamentosCliente($cli, true);
    if ($ags) {
        $p = $ags[0];
        $linhas[] = 'Próximo agendamento: ' . $p['servicos'] . ' com ' . $p['barbeiro']
            . ' em ' . date('d/m/Y', strtotime($p['data'])) . ' às ' . substr((string)$p['hora'], 0, 5)
            . ' (status ' . $p['status'] . ').';
        if (count($ags) > 1) $linhas[] = 'Agendamentos futuros no total: ' . count($ags) . '.';
    } else {
        $linhas[] = 'Não possui agendamentos futuros.';
    }
    if (function_exists('getFidelidadeRegras') && function_exists('getClientFidelityPoints')) {
        $r = getFidelidadeRegras();
        if (!empty($r['ativado'])) {
            $pt = (int) getClientFidelityPoints($cli['id']);
            $nec = (int) $r['pontos_necessarios'];
            $faltam = max(0, $nec - $pt);
            $linhas[] = "Fidelidade: $pt de $nec pontos" . ($faltam > 0 ? " (faltam $faltam para a recompensa)." : " — já pode resgatar a recompensa!");
        }
    }
    if (function_exists('getAssinaturaCliente')) {
        $ass = getAssinaturaCliente($cli['id']);
        if ($ass) {
            $planos = lerDados('planos', ['id', 'nome']);
            $nome = $planos[$ass['plano_id']]['nome'] ?? 'Assinatura';
            $linhas[] = 'Assinatura ativa: ' . $nome . ' (válida até ' . date('d/m/Y', strtotime($ass['data_fim'])) . ').';
        } else {
            $linhas[] = 'Sem assinatura ativa.';
        }
    }
    return implode("\n", $linhas);
}

/** Dias desde o último atendimento concluído do cliente (ou null se nenhum). */
function _botDiasUltimoAtendimento($cli) {
    if (empty($cli['id'])) return null;
    $ags = lerDados('agendamentos', ['id', 'telefone', 'data', 'hora', 'status', 'cliente_id']);
    $tel = limparTelefone((string)($cli['telefone'] ?? ''));
    $ultimo = 0;
    foreach ($ags as $ag) {
        $meu = !empty($ag['cliente_id']) ? ($ag['cliente_id'] === $cli['id']) : ($tel !== '' && limparTelefone((string)($ag['telefone'] ?? '')) === $tel);
        if (!$meu || ($ag['status'] ?? '') !== 'concluido') continue;
        $ts = strtotime(($ag['data'] ?? '') . ' ' . ($ag['hora'] ?? ''));
        if ($ts > $ultimo) $ultimo = $ts;
    }
    return $ultimo ? (int) floor((time() - $ultimo) / 86400) : null;
}

/**
 * Saudação proativa e personalizada: cumprimenta pelo nome e destaca o próximo
 * agendamento, os pontos de fidelidade ou uma sugestão (voltar a agendar).
 */
function _botSaudacaoPersonalizada($cli, $nomeBarbearia, $saudacaoCustom = '') {
    if (empty($cli['id'])) {
        return trim((string)$saudacaoCustom) ?: ('Olá! 👋 Sou o assistente da <strong>' . htmlspecialchars($nomeBarbearia) . '</strong>. Posso agendar, tirar dúvidas, ver seus pontos e muito mais. Como posso ajudar?');
    }
    $primeiro = htmlspecialchars(explode(' ', trim((string)($cli['nome'] ?? 'cliente')))[0]);
    $msg = "Olá, <strong>$primeiro</strong>! 👋 Que bom te ver por aqui.<br>";
    $ags = _botAgendamentosCliente($cli, true);
    if (!empty($ags)) {
        $p = $ags[0];
        $msg .= '📅 Seu próximo horário: <strong>' . htmlspecialchars($p['servicos']) . '</strong> com ' . htmlspecialchars($p['barbeiro'])
            . ' em <strong>' . date('d/m', strtotime($p['data'])) . ' às ' . substr((string)$p['hora'], 0, 5) . '</strong>.';
    } else {
        $sugeriu = false;
        if (function_exists('getFidelidadeRegras')) {
            $r = getFidelidadeRegras();
            if (!empty($r['ativado']) && function_exists('getClientFidelityPoints')) {
                $pt = (int) getClientFidelityPoints($cli['id']);
                $nec = max(1, (int)$r['pontos_necessarios']);
                $faltam = max(0, $nec - $pt);
                if ($pt > 0) { $msg .= "⭐ Você tem <strong>$pt</strong> ponto(s) de fidelidade" . ($faltam > 0 ? " — faltam $faltam para a recompensa!" : ' — já pode resgatar! 🎁'); $sugeriu = true; }
            }
        }
        if (!$sugeriu) {
            $dias = _botDiasUltimoAtendimento($cli);
            if ($dias !== null && $dias >= 20) $msg .= "✂️ Já faz <strong>$dias dias</strong> do seu último corte. Que tal agendar o próximo?";
            else $msg .= 'Posso agendar um horário, mostrar serviços ou tirar dúvidas. O que você precisa?';
        }
    }
    return $msg;
}

// ---------- resolução de intenção por palavra-chave (quando não veio via botão) ----------
// Com a IA ligada (agente), o texto livre NÃO é mapeado por palavra-chave: vai
// direto ao agente, que entende a frase inteira e executa ações. Sem IA, o
// reconhecimento por palavra-chave abaixo mantém o bot funcional por regras.
$iaAtiva = !empty($cfgBot['modo_ia']) && function_exists('iaTemChaveConfigurada') && iaTemChaveConfigurada();
$agenteAtivo = $iaAtiva && !empty($cfgBot['modo_agente']);
$exigirLoginVisitante = !empty($cfgBot['exigir_login_visitante']);

// Configurável: exigir que o visitante entre na conta antes de conversar por
// texto livre (botões de informação continuam liberados).
if ($exigirLoginVisitante && !$clienteLogado && $intent === '' && $mensagem !== '') {
    _botResp('Para conversar com o assistente, entre na sua conta ou crie uma — leva menos de 1 minuto. 🔒😊', [
        ['label' => '🔑 Entrar', 'intent' => 'login'],
        ['label' => '📝 Criar conta', 'intent' => 'registrar'],
    ]);
}

if (!$iaAtiva && $intent === '' && $mensagem !== '') {
    $n = _botNorm($mensagem);
    // Ações diretas (fluxos estruturados).
    $acoesDiretas = [
        'agendar'         => ['agendar','marcar','marcacao','marcação','quero agendar','reservar','marca um','agenda um'],
        'disponibilidade' => ['horario livre','horarios livres','disponibilidade','tem vaga','tem horario','tem horário','disponivel','disponível','horarios disponiveis'],
        'registrar'       => ['cadastrar','cadastro','criar conta','nova conta','me registrar','registrar'],
        'login'           => ['fazer login','quero entrar','logar'],
        'meus_agendamentos' => ['meus agendamentos','meu agendamento','proximo corte','próximo corte','proximo horario','próximo horário','minha agenda','quando eu marquei','quando é meu'],
        'meus_pontos'     => ['meus pontos','quantos pontos','minha fidelidade','pontos de fidelidade','minha pontuacao','minha pontuação','meu saldo de pontos'],
        'minha_assinatura'=> ['minha assinatura','meu plano','tenho plano','minha mensalidade','sou assinante'],
        'cliente'         => ['minha conta','cancelar','remarcar','reagendar'],
        'saudacao'        => ['menu','ajuda'],
    ];
    foreach ($acoesDiretas as $key => $termos) {
        foreach ($termos as $t) { if (mb_strpos($n, _botNorm($t)) !== false) { $intent = $key; break 2; } }
    }
    // Perguntas informativas: se a IA estiver ligada, deixa a IA responder (mais natural);
    // senão, usa o reconhecimento por palavra-chave.
    if ($intent === '' && !$iaAtiva) {
        $regras = [
            'servicos'      => ['preco','preço','valor','servico','serviço','corte','barba','quanto custa','tabela'],
            'planos'        => ['plano','assinatura','mensal','mensalidade','vip'],
            'profissionais' => ['barbeiro','profissional','quem atende','equipe'],
            'horarios'      => ['funciona','aberto','abre','fecha','horario de funcionamento','que horas'],
            'contato'       => ['telefone','contato','whatsapp','zap','falar','numero','número'],
            'endereco'      => ['endereco','endereço','onde fica','onde','local','localizacao','mapa','como chegar'],
            'pagamento'     => ['pagar','pagamento','pix','cartao','cartão','dinheiro','forma de pagamento'],
            'saudacao'      => ['ola','olá','oi','bom dia','boa tarde','boa noite'],
        ];
        foreach ($regras as $key => $termos) {
            foreach ($termos as $t) { if (mb_strpos($n, _botNorm($t)) !== false) { $intent = $key; break 2; } }
        }
    }
}

// ---------- intenções ----------
switch ($intent) {

    case 'servicos': {
        $servicos = lerDados('servicos', ['id', 'nome', 'valor', 'slots']);
        if (empty($servicos)) _botResp('Ainda não há serviços cadastrados. Fale conosco pelo WhatsApp. 😊', _botMenuOptions());
        $linhas = '';
        foreach ($servicos as $s) {
            $dur = max(1, (int)($s['slots'] ?? 1)) * 30;
            $linhas .= '• <strong>' . htmlspecialchars($s['nome']) . '</strong> — R$ ' . number_format((float)$s['valor'], 2, ',', '.') . " <span style=\"opacity:.7\">($dur min)</span><br>";
        }
        _botResp('Estes são os nossos serviços:<br><br>' . $linhas, [
            ['label' => '🗓️ Agendar', 'intent' => 'agendar'],
            ['label' => '📅 Ver horários livres', 'intent' => 'disponibilidade'],
            ['label' => '↩️ Menu', 'intent' => 'menu'],
        ]);
    }

    case 'planos': {
        $planos = lerDados('planos', ['id', 'nome', 'valor', 'servicos_ids']);
        if (empty($planos)) _botResp('No momento não temos planos de assinatura ativos. Posso te mostrar os serviços avulsos? ✂️', [['label'=>'Ver serviços','intent'=>'servicos'],['label'=>'↩️ Menu','intent'=>'menu']]);
        $linhas = '';
        foreach ($planos as $p) {
            $linhas .= '• <strong>' . htmlspecialchars($p['nome']) . '</strong> — R$ ' . number_format((float)$p['valor'], 2, ',', '.') . '/mês<br>';
        }
        _botResp('Temos <strong>Barbearia por Assinatura</strong>! Planos:<br><br>' . $linhas . '<br>Com o plano, os serviços inclusos saem sem custo adicional. 👑', [
            ['label' => '🗓️ Assinar/agendar', 'intent' => 'agendar'],
            ['label' => '↩️ Menu', 'intent' => 'menu'],
        ]);
    }

    case 'profissionais': {
        $barbeiros = lerDados('barbeiros', ['id', 'nome', 'status']);
        $ativos = array_filter($barbeiros, fn($b) => ($b['status'] ?? 'ativo') !== 'inativo');
        if (empty($ativos)) _botResp('Nossa equipe será divulgada em breve. 💈', _botMenuOptions());
        $linhas = '';
        foreach ($ativos as $b) $linhas .= '• ' . htmlspecialchars($b['nome']) . '<br>';
        _botResp('Nossa equipe de profissionais:<br><br>' . $linhas, [
            ['label' => '📅 Ver horários livres', 'intent' => 'disponibilidade'],
            ['label' => '↩️ Menu', 'intent' => 'menu'],
        ]);
    }

    case 'horarios': {
        // Deriva o funcionamento a partir dos horários de trabalho cadastrados.
        $dias = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
        $porDia = [];
        try {
            foreach (getDB()->query("SELECT dia, MIN(inicio) ini, MAX(fim) fim FROM horarios_trabalho GROUP BY dia") as $r) {
                $porDia[(int)$r['dia']] = ($r['ini'] ?? '') . ' às ' . ($r['fim'] ?? '');
            }
        } catch (Exception $e) {}
        if (empty($porDia)) {
            _botResp('Para confirmar nossos horários de funcionamento, fale conosco pelo WhatsApp ou telefone. 🕒', [['label'=>'📍 Contato','intent'=>'contato'],['label'=>'↩️ Menu','intent'=>'menu']]);
        }
        $linhas = '';
        for ($d = 0; $d <= 6; $d++) {
            $linhas .= '• <strong>' . $dias[$d] . ':</strong> ' . (isset($porDia[$d]) ? $porDia[$d] : 'Fechado') . '<br>';
        }
        _botResp('Nosso funcionamento (pode variar por profissional):<br><br>' . $linhas, [
            ['label' => '📅 Ver horários livres', 'intent' => 'disponibilidade'],
            ['label' => '↩️ Menu', 'intent' => 'menu'],
        ]);
    }

    case 'contato':
    case 'endereco': {
        $tel = $configGeral['telefone_contato'] ?? '';
        $end = $configGeral['endereco'] ?? '';
        $wpp = !empty($configGeral['link_whatsapp']) ? preg_replace('/\D/', '', $configGeral['link_whatsapp']) : '';
        $reply = '📍 <strong>Endereço:</strong> ' . htmlspecialchars($end ?: 'não informado') . '<br>';
        $reply .= '📞 <strong>Telefone:</strong> ' . htmlspecialchars($tel ?: 'não informado');
        $opts = [];
        if ($end) $opts[] = ['label' => '🗺️ Abrir no mapa', 'url' => 'https://www.google.com/maps/search/?api=1&query=' . urlencode($end)];
        if ($wpp) $opts[] = ['label' => '💬 WhatsApp', 'url' => 'https://wa.me/55' . $wpp];
        $opts[] = ['label' => '↩️ Menu', 'intent' => 'menu'];
        _botResp($reply, $opts);
    }

    case 'pagamento': {
        _botResp('Aceitamos <strong>Dinheiro</strong>, <strong>PIX</strong> e <strong>Cartão</strong> (débito/crédito). Planos de assinatura são cobrados mensalmente. 💳', [
            ['label' => '👑 Ver planos', 'intent' => 'planos'],
            ['label' => '↩️ Menu', 'intent' => 'menu'],
        ]);
    }

    case 'agendar': {
        $servicos = lerDados('servicos', ['id', 'nome', 'valor', 'slots']);
        if (empty($servicos)) _botResp('No momento não consigo iniciar o agendamento por aqui. Use a agenda online. 🗓️', [['label'=>'➡️ Abrir agendamento','url'=>'agendamento'],['label'=>'↩️ Menu','intent'=>'menu']]);
        // Assinante logado: mostra "Incluso no plano" em vez do preço de tabela
        // nos serviços que o plano cobre (o mesmo que a agenda online faz).
        $cobertosPlanoBot = [];
        if ($clienteLogado && function_exists('getAssinaturaCliente')) {
            $assBot = getAssinaturaCliente($clienteLogado['id']);
            if ($assBot) {
                $planosBot = lerDados('planos', ['id', 'nome', 'valor', 'servicos_ids']) ?: [];
                $planoBot = $planosBot[$assBot['plano_id']] ?? null;
                if ($planoBot) $cobertosPlanoBot = array_filter(array_map('trim', explode(',', (string)($planoBot['servicos_ids'] ?? ''))));
            }
        }
        $opts = [];
        foreach ($servicos as $id => $s) {
            $rotuloPreco = in_array($id, $cobertosPlanoBot, true)
                ? '👑 Incluso no plano'
                : 'R$ ' . number_format((float)$s['valor'], 0, ',', '.');
            $opts[] = ['label' => htmlspecialchars($s['nome']) . ' · ' . $rotuloPreco, 'intent' => 'book_barbeiro',
                'payload' => ['servico_id' => $id, 'servico_nome' => $s['nome'], 'servico_slots' => max(1, (int)($s['slots'] ?? 1))]];
        }
        $opts[] = ['label' => '➡️ Prefiro a agenda completa', 'url' => 'agendamento'];
        $opts[] = ['label' => '↩️ Menu', 'intent' => 'menu'];
        _botResp('Vamos agendar aqui mesmo! 🗓️ Qual serviço você quer?', $opts);
    }

    case 'book_barbeiro': {
        $sid = (string)($payload['servico_id'] ?? '');
        if ($sid === '') _botResp('Vamos recomeçar o agendamento?', [['label'=>'🗓️ Agendar','intent'=>'agendar'],['label'=>'↩️ Menu','intent'=>'menu']]);
        $barbeiros = lerDados('barbeiros', ['id', 'nome', 'status', 'servicos_ids']);
        $aptos = array_filter($barbeiros, function ($b) use ($sid) {
            if (($b['status'] ?? 'ativo') === 'inativo') return false;
            $esp = array_filter(array_map('trim', explode(',', (string)($b['servicos_ids'] ?? ''))));
            return in_array($sid, $esp, true);
        });
        if (empty($aptos)) _botResp('Nenhum profissional disponível para esse serviço no momento. Tente a agenda completa. 🙏', [['label'=>'➡️ Abrir agendamento','url'=>'agendamento'],['label'=>'↩️ Menu','intent'=>'menu']]);
        $opts = [];
        foreach ($aptos as $bid => $b) {
            $pl = $payload; $pl['barbeiro_id'] = $bid; $pl['barbeiro_nome'] = $b['nome'];
            $opts[] = ['label' => htmlspecialchars($b['nome']), 'intent' => 'book_data', 'payload' => $pl];
        }
        $opts[] = ['label' => '↩️ Menu', 'intent' => 'menu'];
        _botResp('Ótima escolha! Com qual profissional? 💈', $opts);
    }

    case 'book_data': {
        if (empty($payload['servico_id']) || empty($payload['barbeiro_id'])) _botResp('Vamos recomeçar?', [['label'=>'🗓️ Agendar','intent'=>'agendar'],['label'=>'↩️ Menu','intent'=>'menu']]);
        $opts = [];
        $nomesDia = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
        for ($i = 0; $i <= 5; $i++) {
            $d = date('Y-m-d', strtotime("+$i day"));
            $lbl = ($i === 0 ? 'Hoje' : ($i === 1 ? 'Amanhã' : $nomesDia[(int)date('w', strtotime($d))])) . ' ' . date('d/m', strtotime($d));
            $pl = $payload; $pl['data'] = $d;
            $opts[] = ['label' => $lbl, 'intent' => 'book_hora', 'payload' => $pl];
        }
        $opts[] = ['label' => '↩️ Menu', 'intent' => 'menu'];
        _botResp('Para qual dia? 📅', $opts);
    }

    case 'book_hora': {
        $bid = (string)($payload['barbeiro_id'] ?? '');
        $data = (string)($payload['data'] ?? '');
        $slots = max(1, (int)($payload['servico_slots'] ?? 1));
        if ($bid === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) _botResp('Vamos recomeçar?', [['label'=>'🗓️ Agendar','intent'=>'agendar'],['label'=>'↩️ Menu','intent'=>'menu']]);
        $exp = getHorarioDeTrabalho($bid, (int)date('w', strtotime($data)), $data);
        if (!$exp) _botResp('Esse profissional não atende nesse dia. Quer escolher outro dia?', [['label'=>'📅 Outro dia','intent'=>'book_data','payload'=>$payload],['label'=>'↩️ Menu','intent'=>'menu']]);
        $ocupados = getHorariosOcupados($bid, $data);
        $opts = [];
        for ($c = strtotime($exp['inicio']); $c < strtotime($exp['fim']); $c = strtotime('+30 minutes', $c)) {
            $h = date('H:i', $c);
            if ($data === date('Y-m-d') && $h <= date('H:i')) continue;
            // verifica se todos os slots necessários estão livres
            $livre = true;
            for ($k = 0; $k < $slots; $k++) {
                $hk = date('H:i', strtotime("+".($k*30)." minutes", $c));
                if (in_array($hk, $ocupados, true) || $hk >= date('H:i', strtotime($exp['fim']))) { $livre = false; break; }
            }
            if ($livre) {
                $pl = $payload; $pl['hora'] = $h;
                $opts[] = ['label' => $h, 'intent' => 'book_nome', 'payload' => $pl];
            }
            if (count($opts) >= 16) break;
        }
        if (empty($opts)) _botResp('Não há horários livres nesse dia. 😕 Quer tentar outro?', [['label'=>'📅 Outro dia','intent'=>'book_data','payload'=>$payload],['label'=>'↩️ Menu','intent'=>'menu']]);
        $opts[] = ['label' => '📅 Outro dia', 'intent' => 'book_data', 'payload' => $payload];
        _botResp('Horários livres para <strong>' . date('d/m', strtotime($data)) . '</strong>. Escolha um: 🕒', $opts);
    }

    case 'book_nome': {
        if (empty($payload['hora'])) _botResp('Vamos recomeçar?', [['label'=>'🗓️ Agendar','intent'=>'agendar'],['label'=>'↩️ Menu','intent'=>'menu']]);
        // Cliente logado: agenda direto com os dados da conta.
        if ($clienteLogado) {
            $telCad = limparTelefone((string)($clienteLogado['telefone'] ?? ''));
            $payload['cliente_nome'] = $clienteLogado['nome'];
            if (strlen($telCad) >= 10) {
                $payload['cliente_telefone'] = $telCad;
                _botConfirmarAgendamento($payload);
            }
            // Conta sem telefone válido: pede para completar (agenda para a própria conta).
            _botResp('Só falta confirmar seu <strong>telefone/WhatsApp</strong> (com DDD): 📱', [['label'=>'✖️ Cancelar','intent'=>'menu']], ['await' => 'telefone_self', 'payload' => $payload]);
        }
        // Não logado: exige login/senha (ou cadastro) antes de confirmar. Guarda o payload para retomar.
        _botResp('Para agendar pelo assistente é preciso <strong>entrar na sua conta</strong>. 🔒<br>Faça login ou crie sua conta — eu retomo seu horário logo em seguida. 😊', [
            ['label' => '🔑 Entrar', 'intent' => 'login', 'payload' => $payload],
            ['label' => '📝 Criar conta', 'intent' => 'registrar', 'payload' => $payload],
            ['label' => '✖️ Cancelar', 'intent' => 'menu'],
        ]);
    }

    // ---- Início do fluxo de LOGIN (dentro do chat) ----
    case 'login': {
        if ($clienteLogado) _botAposAutenticar($clienteLogado, $payload);
        _botResp('Vamos entrar na sua conta. 🔑<br>Digite seu <strong>e-mail, telefone ou CPF</strong>:', [
            ['label' => '📝 Criar conta', 'intent' => 'registrar', 'payload' => $payload],
            ['label' => '✖️ Cancelar', 'intent' => 'menu'],
        ], ['await' => 'login_id', 'payload' => $payload]);
    }

    // Reexibe o pedido de senha (usado pelo botão "digitar senha de novo").
    case 'login_senha_retry': {
        _botResp('Digite sua <strong>senha</strong> novamente: 🔒', [
            ['label' => '❓ Esqueci a senha', 'url' => 'esqueci_senha'],
            ['label' => '✖️ Cancelar', 'intent' => 'menu'],
        ], ['await' => 'login_senha', 'payload' => $payload]);
    }

    // ---- Início do fluxo de CADASTRO (dentro do chat) ----
    case 'registrar': {
        if ($clienteLogado) _botAposAutenticar($clienteLogado, $payload);
        _botResp('Vamos criar sua conta! 📝 É rapidinho.<br>Qual é o seu <strong>nome completo</strong>?', [
            ['label' => '🔑 Já tenho conta', 'intent' => 'login', 'payload' => $payload],
            ['label' => '✖️ Cancelar', 'intent' => 'menu'],
        ], ['await' => 'reg_nome', 'payload' => $payload]);
    }

    case 'book_coletar': {
        $campo = (string)($input['campo'] ?? '');

        // ---------- TELEFONE (cliente logado completando a própria conta) ----------
        if ($campo === 'telefone_self') {
            if (!$clienteLogado) _botResp('Sua sessão expirou. Entre novamente para agendar.', [['label'=>'🔑 Entrar','intent'=>'login','payload'=>$payload],['label'=>'↩️ Menu','intent'=>'menu']]);
            $tel = limparTelefone($mensagem);
            if (strlen($tel) < 10) _botResp('Hmm, o telefone parece incompleto. Envie com DDD, ex.: (11) 99999-9999. 📱', [['label'=>'✖️ Cancelar','intent'=>'menu']], ['await' => 'telefone_self', 'payload' => $payload]);
            $payload['cliente_nome'] = $clienteLogado['nome'];
            $payload['cliente_telefone'] = $tel;
            _botConfirmarAgendamento($payload);
        }

        // ---------- LOGIN ----------
        if ($campo === 'login_id') {
            $id = trim($mensagem);
            if ($id === '') _botResp('Preciso do seu e-mail, telefone ou CPF para continuar. 🙏', [['label'=>'✖️ Cancelar','intent'=>'menu']], ['await' => 'login_id', 'payload' => $payload]);
            $payload['login_id'] = $id;
            _botResp('Agora digite sua <strong>senha</strong>: 🔒', [
                ['label' => '❓ Esqueci a senha', 'url' => 'esqueci_senha'],
                ['label' => '✖️ Cancelar', 'intent' => 'menu'],
            ], ['await' => 'login_senha', 'payload' => $payload]);
        }
        if ($campo === 'login_senha') {
            $idlogin = trim((string)($payload['login_id'] ?? ''));
            $cli = $idlogin !== '' ? getClientePorEmailTelefoneOuCPF($idlogin) : null;
            if ($cli && ($cli['status'] ?? '') === 'inativo') {
                _botResp('Sua conta ainda não foi <strong>confirmada por e-mail</strong>. 📧 Verifique sua caixa de entrada (e spam) para ativá-la e depois volte aqui.', [
                    ['label' => '🔁 Tentar outra conta', 'intent' => 'login', 'payload' => _botLimparAuth($payload)],
                    ['label' => '↩️ Menu', 'intent' => 'menu'],
                ]);
            }
            if ($cli && !empty($cli['password_hash']) && password_verify($mensagem, $cli['password_hash'])) {
                _botDefinirSessaoCliente($cli);
                _botAposAutenticar($cli, _botLimparAuth($payload));
            }
            _botResp('😕 Não consegui validar. Confira e-mail/telefone/CPF e senha.', [
                ['label' => '🔒 Digitar senha de novo', 'intent' => 'login_senha_retry', 'payload' => $payload],
                ['label' => '❓ Esqueci a senha', 'url' => 'esqueci_senha'],
                ['label' => '🔁 Recomeçar login', 'intent' => 'login', 'payload' => _botLimparAuth($payload)],
                ['label' => '↩️ Menu', 'intent' => 'menu'],
            ]);
        }

        // ---------- CADASTRO ----------
        if ($campo === 'reg_nome') {
            $nome = trim(preg_replace('/\s+/', ' ', $mensagem));
            if (mb_strlen($nome) < 3 || mb_strpos($nome, ' ') === false) _botResp('Envie seu <strong>nome e sobrenome</strong>, por favor. 😊', [['label'=>'✖️ Cancelar','intent'=>'menu']], ['await' => 'reg_nome', 'payload' => $payload]);
            $payload['reg_nome'] = $nome;
            $primeiro = explode(' ', $nome)[0];
            _botResp('Prazer, ' . htmlspecialchars($primeiro) . '! Qual é o seu <strong>e-mail</strong>? 📧', [['label'=>'✖️ Cancelar','intent'=>'menu']], ['await' => 'reg_email', 'payload' => $payload]);
        }
        if ($campo === 'reg_email') {
            $email = trim($mensagem);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) _botResp('Esse e-mail não parece válido. Pode conferir? 📧', [['label'=>'✖️ Cancelar','intent'=>'menu']], ['await' => 'reg_email', 'payload' => $payload]);
            if (getClientePorEmailTelefoneOuCPF($email)) _botResp('Já existe uma conta com esse e-mail. 🙂 Quer entrar?', [['label'=>'🔑 Fazer login','intent'=>'login','payload'=>_botLimparAuth($payload)],['label'=>'✖️ Cancelar','intent'=>'menu']]);
            $payload['reg_email'] = $email;
            _botResp('Anotado! Agora o seu <strong>CPF</strong> (só números): 🪪', [['label'=>'✖️ Cancelar','intent'=>'menu']], ['await' => 'reg_cpf', 'payload' => $payload]);
        }
        if ($campo === 'reg_cpf') {
            $cpf = limparTelefone($mensagem);
            if (!validarCPF($cpf)) _botResp('Esse CPF não parece válido. Envie os 11 números. 🪪', [['label'=>'✖️ Cancelar','intent'=>'menu']], ['await' => 'reg_cpf', 'payload' => $payload]);
            if (getClientePorEmailTelefoneOuCPF($cpf)) _botResp('Já existe uma conta com esse CPF. Quer entrar?', [['label'=>'🔑 Fazer login','intent'=>'login','payload'=>_botLimparAuth($payload)],['label'=>'✖️ Cancelar','intent'=>'menu']]);
            $payload['reg_cpf'] = $cpf;
            _botResp('Ótimo! Agora seu <strong>telefone/WhatsApp</strong> (com DDD): 📱', [['label'=>'✖️ Cancelar','intent'=>'menu']], ['await' => 'reg_telefone', 'payload' => $payload]);
        }
        if ($campo === 'reg_telefone') {
            $tel = limparTelefone($mensagem);
            if (strlen($tel) < 10) _botResp('Telefone incompleto. Envie com DDD, ex.: (11) 99999-9999. 📱', [['label'=>'✖️ Cancelar','intent'=>'menu']], ['await' => 'reg_telefone', 'payload' => $payload]);
            if (getClientePorEmailTelefoneOuCPF($tel)) _botResp('Já existe uma conta com esse telefone. Quer entrar?', [['label'=>'🔑 Fazer login','intent'=>'login','payload'=>_botLimparAuth($payload)],['label'=>'✖️ Cancelar','intent'=>'menu']]);
            $payload['reg_telefone'] = $tel;
            _botResp('Quase lá! Qual a sua <strong>data de nascimento</strong>? (DD/MM/AAAA) 🎂', [['label'=>'✖️ Cancelar','intent'=>'menu']], ['await' => 'reg_nascimento', 'payload' => $payload]);
        }
        if ($campo === 'reg_nascimento') {
            $nasc = _botParseData($mensagem);
            if ($nasc === '') _botResp('Não entendi a data. Envie no formato <strong>DD/MM/AAAA</strong>. 🎂', [['label'=>'✖️ Cancelar','intent'=>'menu']], ['await' => 'reg_nascimento', 'payload' => $payload]);
            $payload['reg_nascimento'] = $nasc;
            _botResp('Por fim, crie uma <strong>senha</strong> (mínimo 8 caracteres): 🔒', [['label'=>'✖️ Cancelar','intent'=>'menu']], ['await' => 'reg_senha', 'payload' => $payload]);
        }
        if ($campo === 'reg_senha') {
            if (!senhaAtendePolitica($mensagem)) _botResp('A senha precisa ter <strong>ao menos 8 caracteres</strong>. Tente outra: 🔒', [['label'=>'✖️ Cancelar','intent'=>'menu']], ['await' => 'reg_senha', 'payload' => $payload]);
            $novo = _botCriarConta($payload, $mensagem);
            if (empty($novo['ok'])) _botResp('😕 ' . htmlspecialchars($novo['erro'] ?? 'Não consegui concluir o cadastro.') . '<br>Vamos tentar de novo?', [
                ['label' => '📝 Recomeçar cadastro', 'intent' => 'registrar', 'payload' => _botLimparAuth($payload)],
                ['label' => '🔑 Já tenho conta', 'intent' => 'login', 'payload' => _botLimparAuth($payload)],
                ['label' => '↩️ Menu', 'intent' => 'menu'],
            ]);
            _botDefinirSessaoCliente($novo['cliente']);
            if (_botTemBookingPendente($payload)) {
                // Há agendamento aguardando: conclui na hora.
                _botAposAutenticar($novo['cliente'], _botLimparAuth($payload));
            }
            _botResp('🎉 <strong>Conta criada com sucesso!</strong> Bem-vindo(a), ' . htmlspecialchars(explode(' ', $novo['cliente']['nome'])[0]) . '! Como posso ajudar? 😊', _botMenuOptions());
        }

        _botResp('Vamos recomeçar o agendamento?', [['label'=>'🗓️ Agendar','intent'=>'agendar'],['label'=>'↩️ Menu','intent'=>'menu']]);
    }

    case 'meus_agendamentos': {
        if (!$clienteLogado) _botResp('Para ver seus agendamentos preciso que você <strong>entre na sua conta</strong>. 🔒', [
            ['label' => '🔑 Entrar', 'intent' => 'login'],
            ['label' => '↩️ Menu', 'intent' => 'menu'],
        ]);
        $ags = _botAgendamentosCliente($clienteLogado, true);
        if (empty($ags)) _botResp('Você não tem agendamentos futuros no momento. Que tal marcar um? 🗓️', [
            ['label' => '🗓️ Agendar agora', 'intent' => 'agendar'],
            ['label' => '↩️ Menu', 'intent' => 'menu'],
        ]);
        $linhas = '';
        foreach (array_slice($ags, 0, 6) as $a) {
            $st = ($a['status'] === 'pendente') ? ' <span style="opacity:.7">(aguardando confirmação)</span>' : '';
            $linhas .= '• <strong>' . date('d/m/Y', strtotime($a['data'])) . ' às ' . substr((string)$a['hora'], 0, 5) . '</strong> — '
                . htmlspecialchars($a['servicos']) . ' com ' . htmlspecialchars($a['barbeiro']) . $st . '<br>';
        }
        _botResp('📋 Seus próximos agendamentos:<br><br>' . $linhas . '<br>Para remarcar ou cancelar, é só ir na sua conta. 👤', [
            ['label' => '👤 Gerenciar na Minha Conta', 'url' => 'cliente'],
            ['label' => '🗓️ Novo agendamento', 'intent' => 'agendar'],
            ['label' => '↩️ Menu', 'intent' => 'menu'],
        ]);
    }

    case 'meus_pontos': {
        if (!$clienteLogado) _botResp('Para ver seus pontos de fidelidade preciso que você <strong>entre na sua conta</strong>. 🔒', [
            ['label' => '🔑 Entrar', 'intent' => 'login'],
            ['label' => '↩️ Menu', 'intent' => 'menu'],
        ]);
        $r = function_exists('getFidelidadeRegras') ? getFidelidadeRegras() : ['ativado' => 0, 'pontos_necessarios' => 10];
        if (empty($r['ativado'])) _botResp('Nosso programa de fidelidade não está ativo no momento. 😊 Posso ajudar em algo mais?', _botMenuOptions());
        $pt = (int) getClientFidelityPoints($clienteLogado['id']);
        $nec = max(1, (int) $r['pontos_necessarios']);
        $faltam = max(0, $nec - $pt);
        $cheios = max(0, min($pt, $nec));
        $barra = str_repeat('🟢', $cheios) . str_repeat('⚪', max(0, $nec - $cheios));
        $msg = '⭐ Você tem <strong>' . $pt . '</strong> ponto(s) de fidelidade.<br>' . $barra . '<br><br>';
        $msg .= $faltam > 0 ? "Faltam <strong>$faltam</strong> ponto(s) para a sua próxima recompensa! 🎁" : '🎉 Você já pode resgatar a sua recompensa!';
        _botResp($msg, [
            ['label' => '👤 Ver extrato na conta', 'url' => 'cliente'],
            ['label' => '🗓️ Agendar', 'intent' => 'agendar'],
            ['label' => '↩️ Menu', 'intent' => 'menu'],
        ]);
    }

    case 'minha_assinatura': {
        if (!$clienteLogado) _botResp('Para ver sua assinatura preciso que você <strong>entre na sua conta</strong>. 🔒', [
            ['label' => '🔑 Entrar', 'intent' => 'login'],
            ['label' => '↩️ Menu', 'intent' => 'menu'],
        ]);
        $ass = function_exists('getAssinaturaCliente') ? getAssinaturaCliente($clienteLogado['id']) : null;
        if (!$ass) _botResp('Você não tem uma assinatura ativa. Quer conhecer nossos planos? 👑', [
            ['label' => '👑 Ver planos', 'intent' => 'planos'],
            ['label' => '↩️ Menu', 'intent' => 'menu'],
        ]);
        $planos = lerDados('planos', ['id', 'nome', 'valor']);
        $nomePlano = $planos[$ass['plano_id']]['nome'] ?? 'Assinatura';
        _botResp('👑 Sua assinatura: <strong>' . htmlspecialchars($nomePlano) . '</strong><br>Válida até <strong>' . date('d/m/Y', strtotime($ass['data_fim'])) . '</strong>. Aproveite! 😊', [
            ['label' => '📋 Meus agendamentos', 'intent' => 'meus_agendamentos'],
            ['label' => '👤 Minha Conta', 'url' => 'cliente'],
            ['label' => '↩️ Menu', 'intent' => 'menu'],
        ]);
    }

    case 'cliente': {
        _botResp('Você pode ver, remarcar ou cancelar seus agendamentos na sua conta. 👤', [
            ['label' => '➡️ Minha Conta', 'url' => 'cliente'],
            ['label' => '🗓️ Novo agendamento', 'url' => 'agendamento'],
            ['label' => '↩️ Menu', 'intent' => 'menu'],
        ]);
    }

    // ---- Fluxo guiado de disponibilidade (lê o banco de verdade) ----
    case 'disponibilidade': {
        $barbeiros = lerDados('barbeiros', ['id', 'nome', 'status']);
        $ativos = array_filter($barbeiros, fn($b) => ($b['status'] ?? 'ativo') !== 'inativo');
        if (empty($ativos)) _botResp('Para consultar horários, acesse nossa agenda online. 📅', [['label'=>'Abrir agendamento','url'=>'agendamento'],['label'=>'↩️ Menu','intent'=>'menu']]);
        $opts = [];
        foreach ($ativos as $id => $b) {
            $opts[] = ['label' => htmlspecialchars($b['nome']), 'intent' => 'disp_barbeiro', 'payload' => ['barbeiro_id' => $id]];
        }
        $opts[] = ['label' => '↩️ Menu', 'intent' => 'menu'];
        _botResp('Com qual profissional você quer ver os horários livres? 💈', $opts);
    }

    case 'disp_barbeiro': {
        $bid = (string)($payload['barbeiro_id'] ?? '');
        $hoje = date('Y-m-d');
        $amanha = date('Y-m-d', strtotime('+1 day'));
        _botResp('Para qual dia?', [
            ['label' => 'Hoje (' . date('d/m') . ')', 'intent' => 'disp_data', 'payload' => ['barbeiro_id' => $bid, 'data' => $hoje]],
            ['label' => 'Amanhã (' . date('d/m', strtotime($amanha)) . ')', 'intent' => 'disp_data', 'payload' => ['barbeiro_id' => $bid, 'data' => $amanha]],
            ['label' => '↩️ Menu', 'intent' => 'menu'],
        ]);
    }

    case 'disp_data': {
        $bid = (string)($payload['barbeiro_id'] ?? '');
        $data = (string)($payload['data'] ?? '');
        if ($bid === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) _botResp('Não consegui identificar o profissional/dia. Vamos recomeçar?', [['label'=>'📅 Horários livres','intent'=>'disponibilidade'],['label'=>'↩️ Menu','intent'=>'menu']]);
        $expediente = getHorarioDeTrabalho($bid, (int)date('w', strtotime($data)), $data);
        if (!$expediente) {
            _botResp('Nesse dia o profissional não atende (folga, férias ou sem expediente). Quer tentar outro dia?', [
                ['label' => 'Ver outro profissional', 'intent' => 'disponibilidade'],
                ['label' => '➡️ Abrir agendamento', 'url' => 'agendamento'],
                ['label' => '↩️ Menu', 'intent' => 'menu'],
            ]);
        }
        $ocupados = getHorariosOcupados($bid, $data);
        $livres = [];
        for ($c = strtotime($expediente['inicio']); $c < strtotime($expediente['fim']); $c = strtotime('+30 minutes', $c)) {
            $h = date('H:i', $c);
            $passou = ($data === date('Y-m-d') && $h <= date('H:i'));
            if (!in_array($h, $ocupados, true) && !$passou) $livres[] = $h;
        }
        if (empty($livres)) {
            _botResp('Não há horários livres nesse dia. 😕 Quer tentar outro dia ou profissional?', [
                ['label' => '📅 Tentar de novo', 'intent' => 'disponibilidade'],
                ['label' => '➡️ Abrir agendamento', 'url' => 'agendamento'],
                ['label' => '↩️ Menu', 'intent' => 'menu'],
            ]);
        }
        $chips = implode(' ', array_map(fn($h) => '<span class="cb-slot">' . $h . '</span>', array_slice($livres, 0, 20)));
        _botResp('Horários livres em <strong>' . date('d/m/Y', strtotime($data)) . '</strong>:<br><br>' . $chips . '<br><br>Para reservar, é só continuar no agendamento online. 👇', [
            ['label' => '➡️ Agendar agora', 'url' => 'agendamento'],
            ['label' => '📅 Outro dia/profissional', 'intent' => 'disponibilidade'],
            ['label' => '↩️ Menu', 'intent' => 'menu'],
        ]);
    }

    case 'menu':
    case 'saudacao': {
        if ($exigirLoginVisitante && !$clienteLogado) {
            _botResp('Olá! 👋 Sou o assistente da <strong>' . htmlspecialchars($nomeBarbearia) . '</strong>. Para agendar e falar comigo, entre na sua conta — é rapidinho! 🔒', [
                ['label' => '🔑 Entrar', 'intent' => 'login'],
                ['label' => '📝 Criar conta', 'intent' => 'registrar'],
                ['label' => '✂️ Ver serviços', 'intent' => 'servicos'],
            ]);
        }
        $ini = _botSaudacaoPersonalizada($clienteLogado ?: [], $nomeBarbearia, (string)($cfgBot['saudacao'] ?? ''));
        _botResp($ini, _botMenuOptions());
    }
}

// ---------- IA (se ligada e com chave): AGENTE que age, ou apenas responde ----------
if ($iaAtiva && $mensagem !== '') {
    // Contexto do negócio no system prompt (reduz chamadas de ferramenta p/ o básico).
    $servicos = lerDados('servicos', ['id', 'nome', 'valor', 'slots']);
    $ctxServ = '';
    // Compacto p/ economizar tokens (reenviado a cada iteração do loop do agente):
    // sem duração (a tool listar_servicos traz isso quando o cliente pede).
    foreach (array_slice($servicos, 0, 40) as $sid => $s) {
        $ctxServ .= '- ' . $s['nome'] . ' (id ' . $sid . '): R$ ' . number_format((float)$s['valor'], 2, ',', '.') . "\n";
    }
    $planos = lerDados('planos', ['id', 'nome', 'valor']);
    $ctxPlanos = '';
    foreach ($planos as $pl) $ctxPlanos .= '- ' . $pl['nome'] . ': R$ ' . number_format((float)$pl['valor'], 2, ',', '.') . "/mês\n";
    $barbeiros = lerDados('barbeiros', ['id', 'nome', 'status']);
    $ctxBarb = '';
    foreach ($barbeiros as $bid => $b) { if (($b['status'] ?? 'ativo') !== 'inativo') $ctxBarb .= '- ' . $b['nome'] . ' (id ' . $bid . ")\n"; }

    $resumoCliente = _botResumoCliente($clienteLogado ?: []);
    $diasSemana = ['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'];
    $hoje = date('Y-m-d') . ' (' . $diasSemana[(int)date('w')] . ', ' . date('d/m/Y') . ')';
    $estaLogado = $clienteLogado ? 'SIM' : 'NÃO';

    $sistema = "Você é o assistente virtual da barbearia \"$nomeBarbearia\", conversando com um cliente pelo chat do site. "
        . "Fale português do Brasil, simpático, natural e objetivo (curto). Escreva em texto simples, sem Markdown. "
        . "NUNCA invente preços, horários, IDs, serviços ou dados do cliente. ";
    if ($agenteAtivo) {
        $sistema .= "Você é um AGENTE: use as ferramentas para consultar dados reais e EXECUTAR ações (agendar, cancelar, remarcar, ver pontos, etc.). "
            . "Sempre obtenha os dados via ferramenta ou pelo contexto abaixo. "
            . "Para agendar: descubra serviço e profissional (use IDs), verifique horarios_livres e então criar_agendamento. "
            . "ANTES de qualquer ação de escrita (criar/cancelar/remarcar/atualizar), chame a ferramenta SEM 'confirmar' para obter o resumo, "
            . "mostre o resumo ao cliente e só chame de novo com confirmar=true depois que ele disser SIM. "
            . "Converta datas relativas ('hoje','amanhã','sexta') para AAAA-MM-DD usando a data de hoje. "
            . "Cliente logado: $estaLogado. Se ele pedir algo pessoal e não estiver logado, use preciso_login. "
            . "Troca de senha e exclusão de conta NÃO são feitas por aqui: use as ferramentas de orientação.";
    } else {
        $sistema .= "Você NÃO executa ações: use SOMENTE as informações abaixo para responder. "
            . "Para agendar, cancelar, remarcar, ver pontos ou agendamentos, oriente o cliente a tocar nos botões do chat "
            . "(ex.: \"Agendar\", \"Meus agendamentos\", \"Meus pontos\"). Se não souber, sugira o WhatsApp ou a agenda online.";
    }
    $sistema .= "\n\nData de hoje: $hoje.\n\n"
        . "=== DADOS DA BARBEARIA ===\n"
        . "Serviços (com IDs):\n" . ($ctxServ ?: "(nenhum)\n")
        . ($ctxPlanos ? ("Planos:\n" . $ctxPlanos) : "")
        . "Profissionais (com IDs):\n" . ($ctxBarb ?: "(nenhum)\n")
        . "Endereço: " . ($configGeral['endereco'] ?? 'não informado') . " | Telefone: " . ($configGeral['telefone_contato'] ?? 'não informado') . "\n"
        . "Pagamento: Dinheiro, PIX e Cartão.\n\n"
        . "=== CLIENTE ATUAL ===\n" . $resumoCliente . "\n";

    // system + histórico (memória) + mensagem atual. Histórico curto (últimos 4,
    // 300 chars) para caber no limite de tokens/min do Groq no fluxo com ferramentas
    // (o system + schema de ferramentas já é reenviado a cada iteração do agente).
    $messages = [['role' => 'system', 'content' => $sistema]];
    $hist = $input['history'] ?? [];
    if (is_string($hist)) { $tmp = json_decode($hist, true); $hist = is_array($tmp) ? $tmp : []; }
    if (is_array($hist)) {
        foreach (array_slice($hist, -4) as $h) {
            if (!is_array($h)) continue;
            $role = (($h['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
            $txt = trim((string)($h['content'] ?? ''));
            if ($txt !== '') $messages[] = ['role' => $role, 'content' => mb_substr($txt, 0, 300)];
        }
    }
    $messages[] = ['role' => 'user', 'content' => $mensagem];
    $messagesLimpo = $messages; // cópia sem artefatos de tool para a rede de segurança

    // Modo agente: a IA usa ferramentas e executa ações.
    if ($agenteAtivo && function_exists('agentResponder')) {
        $ctx = ['cliente' => $clienteLogado ?: null, 'config' => $configGeral, 'nome_barbearia' => $nomeBarbearia];
        $ag = agentResponder($messages, $ctx);
        if (!empty($ag['success'])) {
            // Ações rápidas contextuais.
            $acoes = [];
            if (!empty($ag['login_necessario']) && !$clienteLogado) {
                $acoes[] = ['label' => '🔑 Entrar', 'intent' => 'login'];
                $acoes[] = ['label' => '📝 Criar conta', 'intent' => 'registrar'];
            }
            if ($clienteLogado) $acoes[] = ['label' => '📋 Meus agendamentos', 'intent' => 'meus_agendamentos'];
            $acoes[] = ['label' => '🗓️ Agendar', 'intent' => 'agendar'];
            $acoes[] = ['label' => '↩️ Menu', 'intent' => 'menu'];
            // Escapa e converte **negrito** (Markdown eventual) em <strong>.
            $txt = htmlspecialchars($ag['reply']);
            $txt = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $txt);
            _botResp(nl2br($txt), $acoes);
        }
    }
    // Modo "só responder" (agente desligado) OU rede de segurança se o agente
    // falhar (chave/quota): resposta simples com as mensagens LIMPAS.
    if (function_exists('chamarIAComFallback')) {
        $ia = chamarIAComFallback($messagesLimpo);
        if (!empty($ia['success'])) {
            $txt = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', htmlspecialchars($ia['resposta']));
            _botResp(nl2br($txt), _botMenuOptions());
        }
    }
}

// ---------- fallback final (sem IA) ----------
_botResp('Não entendi 🤔. Posso te ajudar com uma destas opções:', _botMenuOptions());
