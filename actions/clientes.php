<?php
// --- VALIDAÇÃO DE SEGURANÇA CSRF ---
$acoes_protegidas = ['salvar_cliente', 'excluir_cliente', 'toggle_cliente_status', 'salvar_anotacao', 'ativar_assinatura', 'cancelar_assinatura'];

if (isset($action) && in_array($action, $acoes_protegidas)) {
    $token_enviado = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!verify_csrf_token($token_enviado)) {
        header('Location: admin.php?tab=clientes&error=' . urlencode('Ação bloqueada por segurança (Token inválido ou expirado). Tente novamente.'));
        exit;
    }
}

// --- AÇÃO: SALVAR CLIENTE (Atualizado para SQLite Nativo) ---
if ($action === 'salvar_cliente') {
    $pdo = getDB();
    $id = $_POST['id'] ?: gerarIdClienteUnico($pdo); // sem colisao de chave primaria
    
    $nome = trim($_POST['nome']); 
    $email = trim($_POST['email']); 
    $telefone = limparTelefone($_POST['telefone']); 
    $cpf = limparTelefone($_POST['cpf']); 
    $data_nascimento = $_POST['data_nascimento']; 
    $nova_senha = $_POST['nova_senha']; 

    // Mesma validacao do cadastro publico e do chatbot. Este caminho nao tinha
    // nenhuma: dava para salvar ficha com nome vazio, e-mail invalido e telefone
    // em branco -- e telefone em branco fazia buscas casarem com a pessoa errada.
    // A senha so e validada quando o admin realmente digita uma nova.
    $exigir = ['nome', 'telefone'];
    if (trim($email) !== '') { $exigir[] = 'email'; }
    if (trim((string)$nova_senha) !== '') { $exigir[] = 'senha'; }
    $errosCadastro = validarDadosCadastroCliente([
        'nome'            => $nome,
        'email'           => $email,
        'telefone'        => $telefone,
        'cpf'             => $cpf,
        'data_nascimento' => $data_nascimento,
        'senha'           => $nova_senha,
    ], $exigir);
    if ($errosCadastro) {
        header('Location: admin.php?tab=clientes&error=' . urlencode(implode(' ', $errosCadastro)));
        exit;
    }

    // E-mail, telefone e CPF nao podem pertencer a OUTRO cliente. Comparado
    // pelo id para que reeditar a propria ficha continue funcionando.
    foreach (['email' => $email, 'telefone' => $telefone, 'cpf' => $cpf] as $campo => $valor) {
        if (trim((string) $valor) === '') { continue; }
        $dono = getClientePorEmailTelefoneOuCPF($valor);
        if ($dono && $dono['id'] !== $id) {
            $rotulo = $campo === 'cpf' ? 'CPF' : ($campo === 'email' ? 'e-mail' : 'telefone');
            header('Location: admin.php?tab=clientes&error=' . urlencode("Este {$rotulo} já pertence a outro cliente."));
            exit;
        }
    }
    $foto_atual = $_POST['foto_perfil_atual'] ?? '';
    
    $foto_perfil = $foto_atual; 
    if (empty($foto_perfil) || $foto_perfil == 'uploads/default-profile.jpg') { 
        $foto_perfil = 'uploads/default-profile.jpg'; 
    }
    if (isset($_FILES['foto_perfil']) && $_FILES['foto_perfil']['error'] == 0) { 
        [$uploadOk, $uploadResultado] = salvarUploadSeguro(
            $_FILES['foto_perfil'],
            'perfil-' . $id,
            ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
            3 * 1024 * 1024,
            'cliente-' . $id
        );
        if (!$uploadOk) {
            header('Location: admin.php?tab=clientes&error=' . urlencode($uploadResultado));
            exit;
        }
        $foto_perfil = $uploadResultado; 
    }
    
    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS clientes (id TEXT PRIMARY KEY, nome TEXT, email TEXT, telefone TEXT, password_hash TEXT, data_nascimento TEXT, foto_perfil TEXT, codigo_indicacao TEXT, cpf TEXT, indicado_por_id TEXT, status TEXT, confirmation_token TEXT)");
    
    $stmtGet = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
    $stmtGet->execute([$id]);
    $cliente_existente = $stmtGet->fetch() ?: [];
    
    $password_hash = $cliente_existente['password_hash'] ?? '';
    if (!empty($nova_senha)) { 
        $password_hash = password_hash($nova_senha, PASSWORD_DEFAULT); 
    } elseif (empty($id) && empty($password_hash)) { 
        $password_hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT); 
    }
    
    $codigo_indicacao = $cliente_existente['codigo_indicacao'] ?? strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6));
    $indicado_por_id = $cliente_existente['indicado_por_id'] ?? '';
    $status = $cliente_existente['status'] ?? 'ativo';
    $confirmation_token = $cliente_existente['confirmation_token'] ?? '';
    
    // UPSERT pela chave primaria, nao "INSERT OR REPLACE".
    // O REPLACE apaga QUALQUER linha que conflite com uma constraint de
    // unicidade -- com indice unico em e-mail, salvar um cliente com o e-mail
    // de outro deletaria o outro inteiro, em silencio. O ON CONFLICT(id) so
    // toca a linha deste id.
    $stmtIns = $pdo->prepare(
        "INSERT INTO clientes (id, nome, email, telefone, password_hash, data_nascimento, foto_perfil, codigo_indicacao, cpf, indicado_por_id, status, confirmation_token)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(id) DO UPDATE SET
             nome = excluded.nome,
             email = excluded.email,
             telefone = excluded.telefone,
             password_hash = excluded.password_hash,
             data_nascimento = excluded.data_nascimento,
             foto_perfil = excluded.foto_perfil,
             codigo_indicacao = excluded.codigo_indicacao,
             cpf = excluded.cpf,
             indicado_por_id = excluded.indicado_por_id,
             status = excluded.status,
             confirmation_token = excluded.confirmation_token"
    );
    $stmtIns->execute([$id, $nome, $email, $telefone, $password_hash, $data_nascimento, $foto_perfil, $codigo_indicacao, $cpf, $indicado_por_id, $status, $confirmation_token]);

    // A tabela agendamentos guarda uma copia de nome/email/telefone ao lado do
    // cliente_id (util para agendamento de quem nao tem cadastro). Quando o
    // admin edita a ficha, essa copia precisa acompanhar -- senao o historico
    // fica com o dado antigo e as buscas por telefone/e-mail deixam de casar.
    // O vinculo usado aqui e o ID, que nunca muda.
    try {
        $stmtSync = $pdo->prepare("UPDATE agendamentos SET nome = ?, email = ?, telefone = ? WHERE cliente_id = ?");
        $stmtSync->execute([$nome, $email, $telefone, $id]);
    } catch (Exception $e) {
        if (function_exists('log_activity')) {
            log_activity('Falha ao sincronizar dados do cliente ' . $id . ' nos agendamentos: ' . $e->getMessage());
        }
    }
    
    header('Location: admin.php?tab=clientes&success=' . urlencode('Ficha do cliente salva com sucesso!')); exit;
}

if ($action === 'excluir_cliente' && !empty($id)) { 
    $pdo = getDB();
    $stmt = $pdo->prepare("DELETE FROM clientes WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: admin.php?tab=clientes&success=' . urlencode('Cliente e seu histórico foram excluídos permanentemente.')); 
    exit; 
}

if ($action === 'toggle_cliente_status' && !empty($id)) {
    $pdo = getDB();
    $stmtGet = $pdo->prepare("SELECT status FROM clientes WHERE id = ?");
    $stmtGet->execute([$id]);
    if ($row = $stmtGet->fetch()) {
        $status_atual = $row['status'] ?: 'ativo';
        $novo_status = ($status_atual === 'ativo') ? 'inativo' : 'ativo';
        $stmtUp = $pdo->prepare("UPDATE clientes SET status = ? WHERE id = ?");
        $stmtUp->execute([$novo_status, $id]);
    }
    header('Location: admin.php?tab=clientes&success=' . urlencode('Status de acesso do cliente atualizado.')); 
    exit;
}

// --- AÇÃO: SALVAR ANOTAÇÃO ---
if ($action === 'salvar_anotacao') { 
    $cliente_id = $_POST['cliente_id']; 
    $anotacao = trim($_POST['anotacao']); 
    salvarAnotacaoCliente($cliente_id, $anotacao);
    header('Location: admin.php?tab=clientes&success=' . urlencode('Anotação interna do cliente salva!')); 
    exit; 
}

// --- ATIVAR ASSINATURA MANUALMENTE (GERENCIAR) ---
if ($action === 'ativar_assinatura') {
    $cliente_id = $_POST['cliente_id'];
    $plano_id = $_POST['plano_id'];
    $dias = (int) $_POST['dias_validade'];
    $status = $_POST['status_assinatura'] ?? 'ativo';
    
    $duracao_str = "+" . $dias . " days";
    $assinaturaAnterior = getAssinaturaClienteQualquerStatus($cliente_id);
    $assinaturaAtual = getAssinaturaCliente($cliente_id);
    $data_base = date('Y-m-d');
    
    if ($assinaturaAtual && $assinaturaAtual['data_fim'] > $data_base && $status === 'ativo') {
        $data_inicio_base = $assinaturaAtual['data_fim'];
    } else {
        $data_inicio_base = $data_base;
    }
    
    salvarAssinaturaCliente($cliente_id, $plano_id, $duracao_str, $data_inicio_base, $status);
    if ($status === 'ativo') {
        $planoManual = lerDados('planos', ['id', 'nome', 'valor', 'servicos_ids'])[$plano_id] ?? [];
        registrarPagamentoAssinatura(
            'manual-' . $cliente_id . '-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6),
            $cliente_id,
            $plano_id,
            'manual',
            (float)($planoManual['valor'] ?? 0),
            date('Y-m-d H:i:s'),
            $assinaturaAnterior ? 'renovacao' : 'adesao',
            '',
            'Ativação pelo painel administrativo'
        );
    }
    header('Location: admin.php?tab=clientes&success=' . urlencode('Plano de assinatura do cliente atualizado com sucesso!'));
    exit;
}

// --- CANCELAR ASSINATURA (a partir do painel de Assinaturas) ---
if ($action === 'cancelar_assinatura') {
    $cliente_id = $_REQUEST['cliente_id'] ?? '';
    $abaRetorno = ($_REQUEST['origem'] ?? '') === 'clientes' ? 'clientes' : 'assinaturas';

    $assinatura = $cliente_id !== '' ? getAssinaturaClienteQualquerStatus($cliente_id) : null;
    if (!$assinatura) {
        header('Location: admin.php?tab=' . $abaRetorno . '&error=' . urlencode('Assinatura não encontrada para este cliente.'));
        exit;
    }

    // Se for Stripe, tenta cancelar a cobrança recorrente na origem (ao fim do
    // período) para que não haja novas cobranças. O benefício segue até o
    // vencimento; o webpush/webhook confirma o encerramento.
    $gateway = $assinatura['gateway'] ?? 'manual';
    $subId = trim((string)($assinatura['gateway_subscription_id'] ?? ''));
    $avisoStripe = '';
    if ($gateway === 'stripe' && $subId !== '') {
        $configStripeCancel = function_exists('_lerConfigSQLite') ? _lerConfigSQLite('config_stripe', ['secret_key' => '']) : ['secret_key' => ''];
        $secret = trim((string)($configStripeCancel['secret_key'] ?? ''));
        if ($secret !== '') {
            $ch = curl_init("https://api.stripe.com/v1/subscriptions/" . urlencode($subId));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['cancel_at_period_end' => 'true']));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $secret, "Content-Type: application/x-www-form-urlencoded"]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode < 200 || $httpCode >= 300) {
                if (function_exists('log_activity')) log_activity('Falha ao cancelar assinatura Stripe ' . $subId . ': ' . $resp);
                $avisoStripe = ' (Não foi possível confirmar o cancelamento no Stripe automaticamente; verifique no painel do Stripe.)';
            }
        } else {
            $avisoStripe = ' (Stripe sem credenciais: cancele também no painel do Stripe.)';
        }
    }

    // Marca localmente: mantém os benefícios até o vencimento (data_fim).
    $dataFim = $assinatura['data_fim'] ?? date('Y-m-d');
    $statusLocal = ($dataFim >= date('Y-m-d')) ? 'cancelamento_agendado' : 'cancelado';
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE clientes_assinaturas SET status = ?, gateway_status = 'canceled', cancelamento_em = ? WHERE cliente_id = ?");
    $stmt->execute([$statusLocal, date('Y-m-d H:i:s'), $cliente_id]);

    if (function_exists('criarNotificacao')) {
        criarNotificacao($cliente_id, 'Sua assinatura foi cancelada. Não haverá novas cobranças e seus benefícios seguem ativos até ' . date('d/m/Y', strtotime($dataFim)) . '.');
    }
    if (function_exists('registrarAtividadeGestao')) {
        registrarAtividadeGestao('cancelar_assinatura', 'Assinatura cancelada do cliente ' . $cliente_id);
    }

    header('Location: admin.php?tab=' . $abaRetorno . '&success=' . urlencode('Assinatura cancelada. Benefícios válidos até ' . date('d/m/Y', strtotime($dataFim)) . '.' . $avisoStripe));
    exit;
}
?>
