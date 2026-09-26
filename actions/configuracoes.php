<?php
// --- AÇÃO: SALVAR CONFIG DO CHATBOT (chaves de IA + failover) ---
if ($action === 'salvar_config_chatbot') {
    // Os campos vêm de <select> (sempre presentes), então lemos o VALOR (0/1),
    // não apenas isset — senão "Desativado" nunca seria salvo.
    $cfg = [
        'ativo'          => (($_POST['cb_ativo'] ?? '1') === '1') ? 1 : 0,
        'modo_ia'        => (($_POST['cb_modo_ia'] ?? '0') === '1') ? 1 : 0,
        'modo_agente'    => (($_POST['cb_modo_agente'] ?? '1') === '1') ? 1 : 0,
        'exigir_login_visitante' => (($_POST['cb_exigir_login'] ?? '0') === '1') ? 1 : 0,
        'provedor_ordem' => 'groq', // Groq primário; Cerebras é fallback no 429/TPM
        'groq_keys'      => trim($_POST['cb_groq_keys'] ?? ''),
        'groq_modelo'    => trim($_POST['cb_groq_modelo'] ?? '') ?: 'openai/gpt-oss-20b',
        'gemini_keys'    => trim($_POST['cb_gemini_keys'] ?? ''),
        'gemini_modelo'  => trim($_POST['cb_gemini_modelo'] ?? '') ?: 'gemini-flash-lite-latest',
        'saudacao'       => trim($_POST['cb_saudacao'] ?? ''),
    ];
    _salvarConfigSQLite('config_chatbot', $cfg);
    header('Location: admin.php?tab=configuracoes&subtab=gemini&success=' . urlencode('Configuração do assistente/chatbot salva com sucesso!'));
    exit;
}

// --- AÇÃO: SALVAR LANDING PAGE E CORES DE TEMA ---
if ($action === 'salvar_landing_page') {
    $currentConfig = carregarLandingPageConfig();
    
    // Lista EXATA de todos os campos de texto do formulário da Landing Page
    $chaves_permitidas = [
        'hero_title', 'hero_subtitle', 'hero_cta_button', 'hero_secondary_button',
        'about_title', 'about_text',
        'stat1_number', 'stat1_label', 'stat2_number', 'stat2_label', 'stat3_number', 'stat3_label',
        'services_title', 'team_title', 'featured_title', 'featured_subtitle',
        'testimonials_title', 'cta_title', 'cta_text', 'cta_button',
        'contact_title', 'terms_of_use', 'privacy_policy'
    ];

    $sanitizedData = [];
    foreach ($chaves_permitidas as $chave) {
        $sanitizedData[$chave] = $_POST[$chave] ?? ($currentConfig[$chave] ?? '');
    }

    // Processamento seguro do Vídeo da Landing Page (opcional).
    // Mantém o vídeo atual quando nenhum arquivo novo é enviado.
    $video_path_atual = $currentConfig['hero_video_path'] ?? '';
    $sanitizedData['hero_video_path'] = $video_path_atual;

    if (isset($_FILES['hero_video']) && $_FILES['hero_video']['error'] === UPLOAD_ERR_OK) {
        [$uploadOk, $uploadResultado] = salvarUploadSeguro(
            $_FILES['hero_video'],
            'hero-video',
            ['video/mp4' => 'mp4', 'video/webm' => 'webm'],
            25 * 1024 * 1024
        );
        if (!$uploadOk) {
            header('Location: admin.php?tab=landingpage&error=' . urlencode($uploadResultado));
            exit;
        }
        $sanitizedData['hero_video_path'] = $uploadResultado;
        if (!empty($video_path_atual) && file_exists($video_path_atual) && $video_path_atual != $uploadResultado) {
            @unlink($video_path_atual);
        }
    }

    // Salvando as configurações de texto e vídeo da Landing Page
    salvarLandingPageConfig($sanitizedData);
    
    // Salvando a nova seção de Cores (Theme Config)
    $themeConfig = [
        'secondary_color' => $_POST['secondary_color'] ?? '#f59e0b'
    ];
    $sanitized_theme = array_filter($themeConfig, function($color) { return preg_match('/^#[a-f0-9]{6}$/i', $color); });
    if (!empty($sanitized_theme)) {
        _salvarConfigSQLite('theme_config', $sanitized_theme);
    }
    
    header('Location: admin.php?tab=landingpage&success=' . urlencode('Design do site e Cor de Destaque atualizados com sucesso!'));
    exit;
}

// --- AÇÃO: SALVAR CONFIG GERAL ---
if ($action === 'salvar_config_geral') {
    $currentConfig = carregarConfigGeral();
    $logo_path = $currentConfig['logo_path']; 
    
    // Processamento seguro da Logo (MIME Type Check)
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
        [$uploadOk, $uploadResultado] = salvarUploadSeguro(
            $_FILES['logo'],
            'logo',
            ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
            3 * 1024 * 1024
        );
        if (!$uploadOk) {
            header('Location: admin.php?tab=configuracoes&subtab=geral&error=' . urlencode($uploadResultado));
            exit;
        }
        $logo_path = $uploadResultado;
    }

    $header_logo_format = $_POST['header_logo_format'] ?? 'auto';
    if (!in_array($header_logo_format, ['auto', 'compacta', 'horizontal'], true)) {
        $header_logo_format = 'auto';
    }

    // Fuso horário: só aceita identificadores válidos; senão mantém o atual/padrão.
    $fusoHorario = trim($_POST['fuso_horario'] ?? '');
    if ($fusoHorario === '' || !in_array($fusoHorario, timezone_identifiers_list(), true)) {
        $fusoHorario = $currentConfig['fuso_horario'] ?? 'America/Sao_Paulo';
    }

    // E-mail de contato (usado como Reply-To e destino padrão do teste de e-mail).
    $emailContato = trim($_POST['email_contato'] ?? '');
    if ($emailContato !== '' && !filter_var($emailContato, FILTER_VALIDATE_EMAIL)) {
        $emailContato = ''; // ignora valor inválido em vez de salvar lixo
    }

    // WhatsApp: guarda o número e deriva o link wa.me quando o link não for informado.
    $whatsappNumero = preg_replace('/\D/', '', $_POST['whatsapp_numero'] ?? '');
    $linkWhatsapp = trim($_POST['link_whatsapp'] ?? '');
    if ($linkWhatsapp === '' && $whatsappNumero !== '') {
        $linkWhatsapp = 'https://wa.me/' . $whatsappNumero;
    }

    $novaConfig = array_merge(is_array($currentConfig) ? $currentConfig : [], [
        'nome_barbearia' => $_POST['nome_barbearia'] ?? '',
        'telefone_contato' => $_POST['telefone_contato'] ?? '',
        'email_contato' => $emailContato,
        'endereco' => $_POST['endereco'] ?? '',
        'fuso_horario' => $fusoHorario,
        'whatsapp_numero' => $whatsappNumero,
        'link_instagram' => $_POST['link_instagram'] ?? '',
        'link_facebook' => $_POST['link_facebook'] ?? '',
        'link_whatsapp' => $linkWhatsapp,
        'logo_path' => $logo_path,
        'header_slogan' => mb_substr(trim($_POST['header_slogan'] ?? ''), 0, 80),
        'header_logo_format' => $header_logo_format,
        // Preserva a geocerca existente (não vem neste formulário) para não zerá-la.
        'geofence_lat' => $currentConfig['geofence_lat'] ?? '',
        'geofence_lon' => $currentConfig['geofence_lon'] ?? ''
    ]);

    _salvarConfigSQLite('config_geral', $novaConfig);

    // Regenera os ícones do PWA automaticamente quando o logo é trocado.
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
        require_once __DIR__ . '/../lib/pwa_functions.php';
        $temaPwa = function_exists('_lerConfigSQLite') ? _lerConfigSQLite('theme_config', []) : [];
        @regenerarIconesPWA($logo_path, $temaPwa['primary_color'] ?? null);
    }

    header('Location: admin.php?tab=configuracoes&subtab=geral&success=' . urlencode('Informações gerais da barbearia salvas!'));
    exit;
}

// --- AÇÃO: SALVAR CONFIG EMAIL ---
if ($action === 'salvar_config_email') {
    $pass = !empty($_POST['password']) ? $_POST['password'] : ($configEmail['password'] ?? '');
    $novaConfig = [
        'host' => $_POST['host'] ?? '',
        'username' => $_POST['username'] ?? '',
        'password' => $pass,
        'port' => (int)$_POST['port'],
        'smtp_secure' => $_POST['smtp_secure'] ?? '',
        'nome_remetente' => $_POST['nome_remetente'] ?? ''
    ];
    _salvarConfigSQLite('config_email', $novaConfig);
    header('Location: admin.php?tab=configuracoes&subtab=email&success=' . urlencode('Configurações de servidor de e-mail (SMTP) salvas com sucesso!'));
    exit;
}

// --- AÇÃO: SALVAR CONFIG STRIPE ---
if ($action === 'salvar_config_stripe') {
    $novaConfig = [
        'secret_key' => trim($_POST['secret_key'] ?? ''),
        'webhook_secret' => trim($_POST['stripe_webhook_secret'] ?? ''),
        'portal_url' => trim($_POST['portal_url'] ?? '')
    ];
    _salvarConfigSQLite('config_stripe', $novaConfig);
    header('Location: admin.php?tab=configuracoes&subtab=pagamento&success=' . urlencode('Credenciais de pagamento da Stripe salvas e ativadas!'));
    exit;
}

// --- AÇÃO: SALVAR GATEWAYS DE ASSINATURA ---
if ($action === 'salvar_config_pagamentos') {
    // Campos secretos não são pré-preenchidos no formulário: vazio = manter o atual.
    $stripeAtual = function_exists('_lerConfigSQLite')
        ? _lerConfigSQLite('config_stripe', ['secret_key' => '', 'portal_url' => '', 'webhook_secret' => ''])
        : ['secret_key' => '', 'portal_url' => '', 'webhook_secret' => ''];
    $novaSecret = trim($_POST['stripe_secret_key'] ?? '');
    $novoWebhook = trim($_POST['stripe_webhook_secret'] ?? '');
    _salvarConfigSQLite('config_stripe', [
        'secret_key' => $novaSecret !== '' ? $novaSecret : ($stripeAtual['secret_key'] ?? ''),
        'webhook_secret' => $novoWebhook !== '' ? $novoWebhook : ($stripeAtual['webhook_secret'] ?? ''),
        'portal_url' => trim($_POST['stripe_portal_url'] ?? '')
    ]);

    header('Location: admin.php?tab=configuracoes&subtab=pagamento&success=' . urlencode('Configurações dos gateways de assinatura salvas com sucesso!'));
    exit;
}

// --- AÇÃO: SALVAR CONFIG AGENDAMENTO ---
if ($action === 'salvar_config_agendamento') { 
    $novaConfig = [
        'antecedencia_minima_minutos' => (int)$_POST['antecedencia_minima_minutos'],
        'antecedencia_maxima' => (int)$_POST['antecedencia_maxima'],
        'max_servicos' => (int)$_POST['max_servicos'],
        'notif_confirmacao' => isset($_POST['notif_confirmacao']) ? 1 : 0,
        'notif_aprovacao' => isset($_POST['notif_aprovacao']) ? 1 : 0,
        'notif_lembrete' => isset($_POST['notif_lembrete']) ? 1 : 0
    ];
    _salvarConfigSQLite('config_agendamento', $novaConfig);
    header('Location: admin.php?tab=configuracoes&subtab=agendamento&success=' . urlencode('Regras de agendamento e limites salvos!')); 
    exit; 
}

// --- AÇÃO: CONFIG APROVAÇÃO ---
if ($action === 'salvar_config_aprovacao') { 
    $novaConfig = ['aprovar' => $_POST['modo_aprovacao']];
    _salvarConfigSQLite('config', $novaConfig);
    header('Location: admin.php?tab=configuracoes&subtab=agendamento&success=' . urlencode('Modo de aprovação alterado!')); 
    exit; 
}

// --- AÇÃO: BACKUP DADOS (ZIP completo) ---
if ($action === 'backup_dados') {
    if (class_exists('ZipArchive')) {
        $zipname = 'backup_barbearia_'.date('Y-m-d_His').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipname, ZipArchive::CREATE);
        if ($handle = opendir(DATA_DIR)) {
            while (false !== ($entry = readdir($handle))) {
                if ($entry != "." && $entry != "..") {
                    $zip->addFile(DATA_DIR . $entry, $entry);
                }
            }
            closedir($handle);
        }
        $zip->close();
        if (function_exists('_salvarConfigSQLite')) { _salvarConfigSQLite('backup_info', ['ultimo_backup' => date('Y-m-d H:i:s'), 'ultimo_tipo' => 'completo']); }
        header('Content-Type: application/zip');
        header('Content-disposition: attachment; filename='.$zipname);
        header('Content-Length: ' . filesize($zipname));
        readfile($zipname);
        unlink($zipname);
        exit;
    } else {
        header('Location: admin.php?tab=configuracoes&subtab=dados&error=' . urlencode('A extensão ZipArchive do PHP não está ativada. Use o backup somente do banco (.sqlite).'));
        exit;
    }
}

// --- AÇÃO: BACKUP SOMENTE DO BANCO (.sqlite) ---
if ($action === 'backup_sqlite') {
    $dbPath = defined('SQLITE_DB_PATH') ? SQLITE_DB_PATH : (DATA_DIR . 'database.sqlite');
    if (!file_exists($dbPath)) {
        header('Location: admin.php?tab=configuracoes&subtab=dados&error=' . urlencode('Arquivo do banco não encontrado.'));
        exit;
    }
    if (function_exists('_salvarConfigSQLite')) { _salvarConfigSQLite('backup_info', ['ultimo_backup' => date('Y-m-d H:i:s'), 'ultimo_tipo' => 'banco']); }
    $nome = 'backup_banco_' . date('Y-m-d_His') . '.sqlite';
    header('Content-Type: application/octet-stream');
    header('Content-disposition: attachment; filename=' . $nome);
    header('Content-Length: ' . filesize($dbPath));
    readfile($dbPath);
    exit;
}

// --- AÇÃO: LIMPAR DADOS DO BANCO (DANGER ZONE) ---
if ($action === 'limpar_dados') {
    $confirmacao = strtoupper(trim($_POST['confirmacao_exclusao'] ?? ''));
    $tabelas_limpar = $_POST['limpar'] ?? [];

    if ($confirmacao !== 'EXCLUIR') {
        header('Location: admin.php?tab=configuracoes&error=' . urlencode('Palavra de segurança incorreta. A limpeza foi cancelada.'));
        exit;
    }

    if (empty($tabelas_limpar)) {
        header('Location: admin.php?tab=configuracoes&error=' . urlencode('Você não selecionou nenhum dado para ser limpo.'));
        exit;
    }

    $pdo = getDB();
    try {
        $pdo->beginTransaction();
        
        $tabelas_para_processar = [];

        foreach ($tabelas_limpar as $tipo) {
            switch ($tipo) {
                case 'agendamentos':
                    $tabelas_para_processar = array_merge($tabelas_para_processar, ['agendamentos']);
                    break;
                case 'clientes':
                    $tabelas_para_processar = array_merge($tabelas_para_processar, ['clientes', 'clientes_assinaturas', 'assinatura_pagamentos', 'anotacoes_clientes', 'fidelidade_pontos', 'clientes_tokens']);
                    break;
                case 'barbeiros':
                    $tabelas_para_processar = array_merge($tabelas_para_processar, ['barbeiros', 'horarios_trabalho', 'horarios_bloqueados']);
                    break;
                case 'servicos':
                    $tabelas_para_processar = array_merge($tabelas_para_processar, ['servicos', 'categorias', 'combos', 'planos']);
                    break;
                case 'avaliacoes':
                    $tabelas_para_processar = array_merge($tabelas_para_processar, ['avaliacoes', 'respostas_avaliacoes', 'avaliacoes_destacadas']);
                    break;
                case 'produtos':
                    $tabelas_para_processar = array_merge($tabelas_para_processar, ['produtos']);
                    break;
                case 'financeiro':
                    $tabelas_para_processar = array_merge($tabelas_para_processar, ['despesas', 'comissoes_pagas']);
                    break;
                case 'marketing':
                    $tabelas_para_processar = array_merge($tabelas_para_processar, ['cupoes', 'vouchers']);
                    break;
                case 'notificacoes':
                    $tabelas_para_processar = array_merge($tabelas_para_processar, ['notificacoes']);
                    break;
                case 'kardex':
                    $tabelas_para_processar = array_merge($tabelas_para_processar, ['estoque_logs']);
                    break;
            }
        }

        foreach ($tabelas_para_processar as $tabela) {
            try { $pdo->exec("DELETE FROM " . $tabela); } catch (PDOException $e) { continue; }
        }

        $pdo->commit();
        header('Location: admin.php?tab=configuracoes&subtab=dados&success=' . urlencode('Limpeza concluída! Os dados selecionados foram apagados com sucesso.'));
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        header('Location: admin.php?tab=configuracoes&subtab=dados&error=' . urlencode('Ocorreu um erro ao limpar o banco de dados.'));
        exit;
    }
}

// --- AÇÃO: LIMPEZA CIRÚRGICA POR PERÍODO (agendamentos antigos já finalizados) ---
if ($action === 'limpar_por_periodo') {
    $data_limite = $_POST['data_limite'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_limite)) {
        header('Location: admin.php?tab=configuracoes&subtab=dados&error=' . urlencode('Informe uma data limite válida.'));
        exit;
    }
    if ($data_limite >= date('Y-m-d')) {
        header('Location: admin.php?tab=configuracoes&subtab=dados&error=' . urlencode('A data limite deve ser anterior a hoje, por segurança.'));
        exit;
    }
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM agendamentos WHERE data < ? AND status IN ('concluido','cancelado','cancelado_pelo_cliente')");
        $stmt->execute([$data_limite]);
        $apagados = $stmt->rowCount();
        header('Location: admin.php?tab=configuracoes&subtab=dados&success=' . urlencode("$apagados agendamento(s) finalizado(s) anteriores a " . date('d/m/Y', strtotime($data_limite)) . " foram removidos."));
        exit;
    } catch (PDOException $e) {
        header('Location: admin.php?tab=configuracoes&subtab=dados&error=' . urlencode('Erro ao limpar por período.'));
        exit;
    }
}

// --- AÇÃO: OTIMIZAR BANCO (VACUUM) ---
if ($action === 'otimizar_banco') {
    try {
        $dbPath = defined('SQLITE_DB_PATH') ? SQLITE_DB_PATH : (DATA_DIR . 'database.sqlite');
        $antes = file_exists($dbPath) ? filesize($dbPath) : 0;
        $pdo = getDB();
        $pdo->exec('VACUUM');
        clearstatcache();
        $depois = file_exists($dbPath) ? filesize($dbPath) : 0;
        $reduzido = max(0, $antes - $depois);
        $msg = 'Banco otimizado com sucesso.';
        if ($reduzido > 0) $msg .= ' Espaço recuperado: ' . number_format($reduzido / 1024, 1, ',', '.') . ' KB.';
        header('Location: admin.php?tab=configuracoes&subtab=dados&success=' . urlencode($msg));
        exit;
    } catch (PDOException $e) {
        header('Location: admin.php?tab=configuracoes&subtab=dados&error=' . urlencode('Não foi possível otimizar o banco.'));
        exit;
    }
}

// --- AÇÃO: USUÁRIOS ---
if ($action === 'salvar_usuario') {
    $current_username = $_POST['current_username']; 
    $new_username = trim($_POST['new_username']); 
    $new_password = $_POST['new_password'];
    $new_role = $_POST['new_role'] ?? 'proprietario';
    if (!array_key_exists($new_role, obterPerfisAdmin())) {
        $new_role = 'proprietario';
    }
    
    if (empty($current_username) && empty($new_password)) { 
        header('Location: admin.php?tab=configuracoes&subtab=sistema&error=' . urlencode('A senha é obrigatória para criar um novo usuário.')); 
        exit; 
    }
    
    if (!empty($new_password) && !senhaAtendePolitica($new_password)) {
        $msgSenha = function_exists('mensagemPoliticaSenha') ? mensagemPoliticaSenha() : 'A senha deve ter no mínimo 8 caracteres, incluindo pelo menos uma letra e um número.';
        header('Location: admin.php?tab=configuracoes&subtab=sistema&error=' . urlencode($msgSenha));
        exit;
    }
    
    if (!empty($new_username)) {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (username TEXT PRIMARY KEY, password_hash TEXT NOT NULL)");
        
        $password_hash_to_save = '';
        
        if (empty($new_password) && !empty($current_username)) {
            $stmtGet = $pdo->prepare("SELECT password_hash, role FROM users WHERE username = ?");
            $stmtGet->execute([$current_username]);
            if($row = $stmtGet->fetch()) {
                $password_hash_to_save = $row['password_hash'];
                if (!isset($_POST['new_role'])) {
                    $new_role = $row['role'] ?: 'proprietario';
                }
            }
        } else {
            $password_hash_to_save = password_hash($new_password, PASSWORD_DEFAULT);
        }
        
        if (!empty($current_username) && $current_username !== $new_username) {
            $stmtDel = $pdo->prepare("DELETE FROM users WHERE username = ?");
            $stmtDel->execute([$current_username]);
        }
        
        $stmtUpsert = $pdo->prepare("INSERT OR REPLACE INTO users (username, password_hash, role) VALUES (?, ?, ?)");
        $stmtUpsert->execute([$new_username, $password_hash_to_save, $new_role]);
        
        if (isset($_SESSION['username']) && $_SESSION['username'] == $current_username) { 
            $_SESSION['username'] = $new_username; 
            $_SESSION['admin_role'] = $new_role;
        }
    }
    header('Location: admin.php?tab=configuracoes&subtab=sistema&success=' . urlencode('Usuário administrador salvo com sucesso!')); 
    exit;
}

if ($action === 'excluir_usuario') {
    $username = trim((string)($_GET['username'] ?? ''));
    if ($username === '') {
        header('Location: admin.php?tab=configuracoes&subtab=sistema&error=' . urlencode('Usuário inválido.'));
        exit;
    }
    // Impede o admin de excluir a própria conta em uso.
    if (isset($_SESSION['username']) && strcasecmp($username, (string)$_SESSION['username']) === 0) {
        header('Location: admin.php?tab=configuracoes&subtab=sistema&error=' . urlencode('Você não pode excluir o usuário com o qual está logado.'));
        exit;
    }
    $pdo = getDB();

    $stmtCount = $pdo->query("SELECT COUNT(*) FROM users");
    if ($stmtCount->fetchColumn() > 1) {
        $stmtDel = $pdo->prepare("DELETE FROM users WHERE username = ?");
        $stmtDel->execute([$username]);
        header('Location: admin.php?tab=configuracoes&subtab=sistema&success=' . urlencode('Usuário excluído.')); 
    } else {
        header('Location: admin.php?tab=configuracoes&subtab=sistema&error=' . urlencode('Não é possível excluir o único administrador do sistema.')); 
    }
    exit; 
}
?>
