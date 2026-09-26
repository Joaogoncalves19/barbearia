<?php
// install.php
// Assistente de instalação: verificação de sistema, permissões e criação do admin (SQLite).
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define o fuso horário
date_default_timezone_set('America/Sao_Paulo');

$basePath = __DIR__ . DIRECTORY_SEPARATOR;
$dadosDir = $basePath . '_dados';
$logsDir = $basePath . '_logs';
$uploadsDir = $basePath . 'uploads';
$dbFile = $dadosDir . DIRECTORY_SEPARATOR . 'database.sqlite';

$erro_critico_banco = null;

// =========================================================================================
// PASSO 1: FORÇAR CRIAÇÃO DO BANCO E TABELAS (ANTES DE CARREGAR AS FUNÇÕES)
// Isso evita que o sistema trave ao tentar ler uma tabela que não existe.
// =========================================================================================
try {
    if (!is_dir($dadosDir)) {
        @mkdir($dadosDir, 0775, true);
    }

    $pdoCheck = new PDO('sqlite:' . $dbFile);
    $pdoCheck->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ATUALIZADO: Todas as tabelas utilizadas em todo o ecossistema do sistema (Adicionado estoque_logs e colunas novas)
    $queries_tabelas = [
        "CREATE TABLE IF NOT EXISTS users (username TEXT PRIMARY KEY, password_hash TEXT NOT NULL, role TEXT DEFAULT 'proprietario', permissions TEXT DEFAULT '')",
        "CREATE TABLE IF NOT EXISTS configuracoes (secao TEXT PRIMARY KEY, dados_json TEXT)",
        "CREATE TABLE IF NOT EXISTS barbeiros (id TEXT PRIMARY KEY, nome TEXT, foto TEXT, username TEXT, password TEXT, status TEXT, servicos_ids TEXT, comissao TEXT, comissao_produtos TEXT, meta_diaria TEXT)",
        "CREATE TABLE IF NOT EXISTS servicos (id TEXT PRIMARY KEY, nome TEXT, valor TEXT, slots TEXT, categoria_id TEXT)",
        "CREATE TABLE IF NOT EXISTS combos (id TEXT PRIMARY KEY, nome TEXT, servicos_ids TEXT, valor TEXT, categoria_id TEXT)",
        "CREATE TABLE IF NOT EXISTS agendamentos (id TEXT PRIMARY KEY, nome TEXT, email TEXT, telefone TEXT, barbeiro_id TEXT, servicos_ids TEXT, data TEXT, hora TEXT, status TEXT, desconto_aplicado TEXT, tipo_desconto TEXT, observacoes TEXT, produtos_vendidos TEXT, plano_provisorio TEXT, cliente_id TEXT, data_criacao TEXT)",
        "CREATE TABLE IF NOT EXISTS categorias (id TEXT PRIMARY KEY, nome TEXT, ordem TEXT)",
        "CREATE TABLE IF NOT EXISTS avaliacoes_destacadas (id_avaliacao TEXT PRIMARY KEY)",
        "CREATE TABLE IF NOT EXISTS respostas_avaliacoes (id_resposta TEXT PRIMARY KEY, id_avaliacao TEXT, texto_resposta TEXT, timestamp TEXT)",
        "CREATE TABLE IF NOT EXISTS clientes (id TEXT PRIMARY KEY, nome TEXT, email TEXT, telefone TEXT, password_hash TEXT, data_nascimento TEXT, foto_perfil TEXT, codigo_indicacao TEXT, cpf TEXT, indicado_por_id TEXT, status TEXT, confirmation_token TEXT, notas_barbeiro TEXT)",
        "CREATE TABLE IF NOT EXISTS avaliacoes (id TEXT PRIMARY KEY, agendamento_id TEXT, cliente_id TEXT, barbeiro_id TEXT, rating TEXT, comment TEXT, timestamp TEXT)",
        "CREATE TABLE IF NOT EXISTS cupoes (id TEXT PRIMARY KEY, codigo TEXT, desconto_percentual TEXT, usos_maximos TEXT, data_validade TEXT, usos_atuais TEXT)",
        "CREATE TABLE IF NOT EXISTS vouchers (id TEXT PRIMARY KEY, codigo TEXT, valor TEXT, status TEXT, data_criacao TEXT, agendamento_id_uso TEXT, data_validade TEXT)",
        "CREATE TABLE IF NOT EXISTS planos (id TEXT PRIMARY KEY, nome TEXT, valor TEXT, servicos_ids TEXT)",
        "CREATE TABLE IF NOT EXISTS clientes_assinaturas (cliente_id TEXT PRIMARY KEY, plano_id TEXT, data_inicio TEXT, data_fim TEXT, status TEXT, gateway TEXT DEFAULT 'manual', gateway_subscription_id TEXT DEFAULT '', gateway_status TEXT DEFAULT '', ultimo_pagamento_id TEXT DEFAULT '', cancelamento_em TEXT DEFAULT '', gateway_customer_id TEXT DEFAULT '')",
        "CREATE TABLE IF NOT EXISTS assinatura_pagamentos (id TEXT PRIMARY KEY, cliente_id TEXT NOT NULL, plano_id TEXT NOT NULL, gateway TEXT DEFAULT 'manual', gateway_subscription_id TEXT DEFAULT '', valor REAL DEFAULT 0, moeda TEXT DEFAULT 'BRL', status TEXT DEFAULT 'confirmado', data_pagamento TEXT NOT NULL, tipo TEXT DEFAULT 'mensalidade', referencia TEXT DEFAULT '', created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS webhook_eventos_processados (gateway TEXT NOT NULL, evento_id TEXT NOT NULL, tipo TEXT DEFAULT '', processado_em TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (gateway, evento_id))",
        "CREATE TABLE IF NOT EXISTS horarios_trabalho (barbeiro_id TEXT, dia TEXT, inicio TEXT, fim TEXT, ativo TEXT)",
        "CREATE TABLE IF NOT EXISTS horarios_bloqueados (barbeiro_id TEXT, data TEXT, hora TEXT)",
        "CREATE TABLE IF NOT EXISTS config_almoco_barbeiro (barbeiro_id TEXT PRIMARY KEY, horario TEXT, status TEXT)",
        "CREATE TABLE IF NOT EXISTS password_resets (email TEXT, token TEXT, expiry INTEGER)",
        "CREATE TABLE IF NOT EXISTS clientes_tokens (selector TEXT PRIMARY KEY, cliente_id TEXT NOT NULL, token_hash TEXT NOT NULL, expires_at INTEGER NOT NULL, created_at TEXT NOT NULL, last_used_at TEXT DEFAULT '')",
        "CREATE TABLE IF NOT EXISTS config (chave TEXT PRIMARY KEY, valor TEXT)",
        "CREATE TABLE IF NOT EXISTS anotacoes_clientes (id TEXT PRIMARY KEY, anotacao TEXT)",
        "CREATE TABLE IF NOT EXISTS notificacoes (id TEXT PRIMARY KEY, cliente_id TEXT, mensagem TEXT, lida INTEGER, data_criacao TEXT)",
        "CREATE TABLE IF NOT EXISTS fidelidade (id TEXT PRIMARY KEY, pontos INTEGER)",
        "CREATE TABLE IF NOT EXISTS produtos (id TEXT PRIMARY KEY, nome TEXT, valor TEXT, quantidade INTEGER, categoria_id TEXT)",
        "CREATE TABLE IF NOT EXISTS despesas (id TEXT PRIMARY KEY, descricao TEXT, valor TEXT, data_despesa TEXT, data_vencimento TEXT, data_pagamento TEXT, categoria TEXT, status TEXT)",
        "CREATE TABLE IF NOT EXISTS comissoes_pagas (id TEXT PRIMARY KEY, barbeiro_id TEXT, valor TEXT, data_pagamento TEXT, periodo_inicio TEXT, periodo_fim TEXT, mes_ano TEXT, valor_total_servicos TEXT)",
        "CREATE TABLE IF NOT EXISTS estoque_logs (id TEXT PRIMARY KEY, produto_id TEXT, tipo TEXT, quantidade INTEGER, motivo TEXT, data_hora TEXT, usuario TEXT)"
    ];

    // Cria as tabelas
    foreach ($queries_tabelas as $sql) {
        $pdoCheck->exec($sql);
    }

    // Injeta as colunas que podem estar faltando para sistemas que estão sendo
    // atualizados (retrocompatibilidade). Centralizado em lib/migrations.php.
    require_once __DIR__ . '/lib/migrations.php';
    aplicarMigracoes($pdoCheck);

    // As configurações do sistema (geral, landing page, tema, etc.) são armazenadas
    // na tabela `configuracoes` (secao/dados_json) e semeadas com seus padrões
    // logo após o carregamento das funções (PASSO 2), garantindo compatibilidade
    // total com o painel administrativo atual.
} catch (Exception $e) {
    $erro_critico_banco = "Erro ao criar tabelas no SQLite: " . $e->getMessage();
}

// =========================================================================================
// PASSO 2: SÓ AGORA CARREGAMOS AS FUNÇÕES DO SISTEMA E AS CONFIGURAÇÕES
// =========================================================================================
$configGeral = ['nome_barbearia' => 'Sistema de Barbearia', 'logo_path' => ''];
if (file_exists('functions.php')) {
    require_once 'functions.php';
    try {
        // Semeia (só se ainda não existir) todas as seções de configuração usadas
        // pelo painel atual, já com os padrões corretos:
        //  - logo_path  => uploads/logo.png
        //  - hero_video_path => uploads/bg_video.mp4
        if (function_exists('carregarConfigGeral'))       carregarConfigGeral();
        if (function_exists('carregarLandingPageConfig')) carregarLandingPageConfig();
        if (function_exists('carregarConfigChatbot'))     carregarConfigChatbot();
        if (function_exists('carregarConfigAgendamento')) carregarConfigAgendamento();
        if (function_exists('carregarConfigEmail'))       carregarConfigEmail();
        if (function_exists('getFidelityConfig'))         getFidelityConfig();
        if (function_exists('getAniversarioConfig'))      getAniversarioConfig();
        if (function_exists('getIndicacaoConfig'))        getIndicacaoConfig();
        // Tema padrão (cor de destaque) — usado pelo custom_theme.css.php / header.
        if (function_exists('_lerConfigSQLite'))          _lerConfigSQLite('theme_config', ['secondary_color' => '#007bff']);

        $configGeral = carregarConfigGeral();
    } catch (Exception $e) {
        // Ignora
    }
}

// =========================================================================================
// VERIFICAÇÕES DE AMBIENTE
// Cada item: label, hint, value, state ('ok' | 'warn' | 'fail'), critical (bool).
// Um 'fail' crítico impede a instalação; 'warn' apenas alerta (não bloqueia).
// =========================================================================================
$minPHPVersion = '7.4.0';

$checkServidor = [];
$checkDirs     = [];
$checkFiles    = [];
$checkPerms    = [];

// --- Servidor / PHP ---
$phpOK = version_compare(PHP_VERSION, $minPHPVersion, '>=');
$checkServidor[] = ['label' => 'Versão do PHP', 'hint' => 'Requer ' . $minPHPVersion . ' ou superior', 'value' => PHP_VERSION, 'state' => $phpOK ? 'ok' : 'fail', 'critical' => true];

$extEssenciais = [
    'pdo_sqlite' => 'Conexão com o banco de dados',
    'json'       => 'Leitura das configurações',
    'mbstring'   => 'Acentos e caracteres especiais',
];
foreach ($extEssenciais as $ext => $hint) {
    $on = extension_loaded($ext);
    $checkServidor[] = ['label' => 'Extensão ' . $ext, 'hint' => $hint, 'value' => $on ? 'Ativa' : 'Ausente', 'state' => $on ? 'ok' : 'fail', 'critical' => true];
}

$extRecomendadas = [
    'gd'      => 'Uploads de imagens (fotos, logo)',
    'zip'     => 'Backup do sistema',
    'openssl' => 'Envio de e-mails via SMTP',
];
foreach ($extRecomendadas as $ext => $hint) {
    $on = extension_loaded($ext);
    $checkServidor[] = ['label' => 'Extensão ' . $ext, 'hint' => $hint . ' · recomendado', 'value' => $on ? 'Ativa' : 'Ausente', 'state' => $on ? 'ok' : 'warn', 'critical' => false];
}

$fu = ini_get('file_uploads');
$fuOn = in_array($fu, ['1', 'On', 'on'], true);
$checkServidor[] = ['label' => 'Uploads habilitados', 'hint' => 'Permite enviar fotos e logo', 'value' => $fuOn ? 'Sim' : 'Não', 'state' => $fuOn ? 'ok' : 'warn', 'critical' => false];

// --- Pastas e arquivos ---
$diretoriosObrigatorios = ['_dados', '_logs', 'uploads', 'lib', 'PHPMailer', 'css', 'js', 'email_templates', 'admin_tabs'];
$arquivosObrigatorios   = ['functions.php', 'admin.php', 'index.php', 'agendamento.php', 'login.php'];
$diretoriosEscrita      = ['_dados', '_logs', 'uploads'];

foreach ($diretoriosObrigatorios as $dir) {
    $ok = is_dir($basePath . $dir);
    $checkDirs[] = ['label' => $dir . '/', 'hint' => '', 'value' => $ok ? 'Existe' : 'Ausente', 'state' => $ok ? 'ok' : 'fail', 'critical' => true];
}
foreach ($arquivosObrigatorios as $file) {
    $ok = file_exists($basePath . $file);
    $checkFiles[] = ['label' => $file, 'hint' => '', 'value' => $ok ? 'Existe' : 'Ausente', 'state' => $ok ? 'ok' : 'fail', 'critical' => true];
}

// --- Permissões de escrita ---
$permissao_dados_ok = false;
clearstatcache();
$permHints = ['_dados' => 'Banco de dados SQLite', '_logs' => 'Registros de erro', 'uploads' => 'Fotos e logo'];
foreach ($diretoriosEscrita as $dir) {
    $path = $basePath . $dir;
    $ok = (is_dir($path) && is_writable($path));
    if ($ok) {
        $testFile = $path . DIRECTORY_SEPARATOR . 'writable_test.tmp';
        if (@file_put_contents($testFile, 'teste') === false) {
            $ok = false;
        } else {
            @unlink($testFile);
        }
    }
    if ($dir === '_dados' && $ok) {
        $permissao_dados_ok = true;
    }
    // _dados é obrigatório; _logs e uploads só avisam se faltarem.
    $checkPerms[] = ['label' => $dir . '/', 'hint' => $permHints[$dir] ?? '', 'value' => $ok ? 'Gravável' : 'Bloqueada', 'state' => $ok ? 'ok' : ($dir === '_dados' ? 'fail' : 'warn'), 'critical' => ($dir === '_dados')];
}

// --- Consolidação ---
$requisitosCriticosOK = !$erro_critico_banco;
$totalAvisos = 0;
foreach (array_merge($checkServidor, $checkDirs, $checkFiles, $checkPerms) as $c) {
    if ($c['state'] === 'warn') $totalAvisos++;
    if ($c['critical'] && $c['state'] === 'fail') $requisitosCriticosOK = false;
}

$grupos = [
    ['id' => 'srv',  'titulo' => 'Servidor & PHP',        'icon' => 'fa-server',      'itens' => $checkServidor],
    ['id' => 'dir',  'titulo' => 'Pastas do sistema',     'icon' => 'fa-folder',      'itens' => $checkDirs],
    ['id' => 'arq',  'titulo' => 'Arquivos do sistema',   'icon' => 'fa-file-lines',  'itens' => $checkFiles],
    ['id' => 'perm', 'titulo' => 'Permissões de escrita', 'icon' => 'fa-lock-open',   'itens' => $checkPerms],
];

$admin_setup_error = '';
$admin_setup_success = '';

// Verifica se o admin já existe
$users_file_exists = false;
if ($pdoCheck) {
    try {
        $stmtCheck = $pdoCheck->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
        if ($stmtCheck && $stmtCheck->fetch()) {
            $countCheck = $pdoCheck->query("SELECT COUNT(*) FROM users");
            if ($countCheck && $countCheck->fetchColumn() > 0) {
                $users_file_exists = true;
            }
        }
    } catch (Exception $e) {}
}

// Criação do Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'criar_admin') {
    if ($users_file_exists) {
        $admin_setup_error = "Erro: O administrador já está configurado no banco de dados.";
    } elseif (!$permissao_dados_ok) {
        $admin_setup_error = "Erro: O diretório '_dados' não existe ou não tem permissão de escrita.";
    } else {
        $username = trim($_POST['username']);
        $senha = $_POST['password'];
        $confirma_senha = $_POST['confirm_password'];

        if (empty($username) || empty($senha)) {
            $admin_setup_error = "Usuário e senha são obrigatórios.";
        } elseif ($senha !== $confirma_senha) {
            $admin_setup_error = "As senhas não coincidem.";
        } elseif (strlen($senha) < 8) {
             $admin_setup_error = "A senha deve ter no mínimo 8 caracteres.";
        } else {
            $password_hash = password_hash($senha, PASSWORD_DEFAULT);
            try {
                $stmt = $pdoCheck->prepare("INSERT INTO users (username, password_hash, role, permissions) VALUES (?, ?, 'proprietario', '')");
                $stmt->execute([$username, $password_hash]);

                // Salva os dados da barbearia preenchidos no formulário (mesma seção do painel).
                // IMPORTANTE: gravamos pela conexão $pdoCheck (a mesma do INSERT do admin).
                // Usar getDB() aqui falharia silenciosamente por lock do SQLite (a conexão
                // $pdoCheck ainda detém o lock desta requisição).
                if (function_exists('carregarConfigGeral')) {
                    $atual = carregarConfigGeral(); // leituras funcionam; seeds já feitos no PASSO 2
                    $limpar = function ($v) { return trim((string)$v); };
                    $novoGeral = array_merge($atual, [
                        'nome_barbearia'   => $limpar($_POST['nome_barbearia']   ?? $atual['nome_barbearia']),
                        'telefone_contato' => $limpar($_POST['telefone_contato'] ?? $atual['telefone_contato']),
                        'endereco'         => $limpar($_POST['endereco']         ?? $atual['endereco']),
                        'header_slogan'    => $limpar($_POST['header_slogan']    ?? ($atual['header_slogan'] ?? '')),
                        'link_whatsapp'    => $limpar($_POST['link_whatsapp']    ?? ($atual['link_whatsapp'] ?? '')),
                        'link_instagram'   => $limpar($_POST['link_instagram']   ?? ($atual['link_instagram'] ?? '')),
                        'link_facebook'    => $limpar($_POST['link_facebook']    ?? ($atual['link_facebook'] ?? '')),
                        // Garante o logo padrão existente na pasta uploads.
                        'logo_path'        => (!empty($atual['logo_path']) && file_exists($atual['logo_path'])) ? $atual['logo_path'] : 'uploads/logo.png',
                    ]);

                    $stmtCfg = $pdoCheck->prepare("INSERT OR REPLACE INTO configuracoes (secao, dados_json) VALUES (?, ?)");
                    $stmtCfg->execute(['config_geral', json_encode($novoGeral, JSON_UNESCAPED_UNICODE)]);

                    // Garante que a landing use o vídeo de fundo padrão (uploads/bg_video.mp4).
                    if (function_exists('carregarLandingPageConfig')) {
                        $lp = carregarLandingPageConfig();
                        if (empty($lp['hero_video_path'])) $lp['hero_video_path'] = 'uploads/bg_video.mp4';
                        $stmtCfg->execute(['landing_page', json_encode($lp, JSON_UNESCAPED_UNICODE)]);
                    }
                }

                $admin_setup_success = "Administrador e dados da barbearia salvos com sucesso! Você já pode fazer login.";
                $users_file_exists = true;
            } catch (PDOException $e) {
                $admin_setup_error = "Falha ao salvar no banco. Erro: " . $e->getMessage();
            }
        }
    }
}

$podeInstalar = $requisitosCriticosOK && $permissao_dados_ok;

// Passo inicial exibido ao carregar
$stepInicial = 'check';
if ($users_file_exists) {
    $stepInicial = 'done';
} elseif ($admin_setup_error) {
    $stepInicial = 'config';
}

// Helper de estilo por estado
function inst_state_meta($state) {
    switch ($state) {
        case 'ok':   return ['cls' => 'ok',   'icon' => 'fa-check'];
        case 'warn': return ['cls' => 'warn', 'icon' => 'fa-exclamation'];
        default:     return ['cls' => 'fail', 'icon' => 'fa-xmark'];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalação - <?= htmlspecialchars($configGeral['nome_barbearia']) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/custom_theme.css.php">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --accent: var(--secondary-color, #4f46e5);
            --ink: #0f172a;
            --ink-soft: #475569;
            --muted: #94a3b8;
            --line: #e6eaf1;
            --bg: #eef2f7;
            --card: #ffffff;
            --ok-bg: #ecfdf5; --ok-fg: #059669; --ok-bd: #a7f3d0;
            --warn-bg: #fffbeb; --warn-fg: #b45309; --warn-bd: #fde68a;
            --fail-bg: #fef2f2; --fail-fg: #dc2626; --fail-bd: #fecaca;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; font-family: 'Inter', -apple-system, sans-serif; color: var(--ink);
            background:
                radial-gradient(1200px 500px at 100% -10%, rgba(79,70,229,0.10), transparent 60%),
                radial-gradient(1000px 500px at -10% 110%, rgba(14,165,233,0.10), transparent 55%),
                var(--bg);
            min-height: 100vh; padding: 32px 16px; line-height: 1.5;
        }
        .wizard { max-width: 820px; margin: 0 auto; }

        /* Cabeçalho + stepper */
        .wiz-head { display: flex; align-items: center; gap: 16px; margin-bottom: 22px; }
        .wiz-logo { width: 52px; height: 52px; border-radius: 14px; object-fit: contain; background: #fff; padding: 6px; box-shadow: 0 6px 18px rgba(15,23,42,0.12); flex-shrink: 0; }
        .wiz-logo-fallback { display: flex; align-items: center; justify-content: center; color: #fff; background: linear-gradient(135deg, #0f172a, #334155); }
        .wiz-head h1 { font-size: 1.25rem; font-weight: 800; margin: 0; letter-spacing: -0.4px; }
        .wiz-head p { margin: 2px 0 0; color: var(--ink-soft); font-size: 0.9rem; }

        .stepper { display: flex; align-items: center; gap: 6px; background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 14px 18px; box-shadow: 0 10px 30px -12px rgba(15,23,42,0.18); margin-bottom: 22px; }
        .step { display: flex; align-items: center; gap: 10px; flex: 1; cursor: pointer; background: none; border: none; font-family: inherit; padding: 4px; border-radius: 10px; transition: background .15s; text-align: left; }
        .step:disabled { cursor: not-allowed; }
        .step .dot { width: 34px; height: 34px; border-radius: 50%; background: #eef1f6; color: var(--muted); font-weight: 800; font-size: 0.95rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all .2s; border: 2px solid transparent; }
        .step .txt { display: flex; flex-direction: column; line-height: 1.2; }
        .step .txt b { font-size: 0.9rem; font-weight: 700; color: var(--ink-soft); }
        .step .txt span { font-size: 0.72rem; color: var(--muted); }
        .step.active .dot { background: var(--accent); color: #fff; box-shadow: 0 6px 16px -4px color-mix(in srgb, var(--accent) 60%, transparent); }
        .step.active .txt b { color: var(--ink); }
        .step.done-step .dot { background: var(--ok-bg); color: var(--ok-fg); border-color: var(--ok-bd); }
        .step-sep { flex: 0 0 24px; height: 2px; background: var(--line); border-radius: 2px; }
        @media (max-width: 640px) {
            .step .txt span { display: none; }
            .step-sep { flex-basis: 12px; }
        }

        /* Painel */
        .panel { display: none; background: var(--card); border: 1px solid var(--line); border-radius: 20px; padding: 34px; box-shadow: 0 20px 50px -24px rgba(15,23,42,0.28); animation: fade .35s ease; }
        .panel.active { display: block; }
        @keyframes fade { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
        .panel h2 { font-size: 1.5rem; font-weight: 800; margin: 0 0 6px; letter-spacing: -0.5px; }
        .panel .lead { color: var(--ink-soft); margin: 0 0 24px; }

        /* Banner resumo */
        .summary { display: flex; align-items: center; gap: 16px; border-radius: 16px; padding: 20px 22px; margin-bottom: 26px; border: 1px solid; }
        .summary .s-ico { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0; }
        .summary b { display: block; font-size: 1.05rem; }
        .summary small { color: var(--ink-soft); }
        .summary.good { background: var(--ok-bg); border-color: var(--ok-bd); }
        .summary.good .s-ico { background: #fff; color: var(--ok-fg); }
        .summary.good b { color: #166534; }
        .summary.bad { background: var(--fail-bg); border-color: var(--fail-bd); }
        .summary.bad .s-ico { background: #fff; color: var(--fail-fg); }
        .summary.bad b { color: #991b1b; }

        /* Grupos de verificação (accordion) */
        .group { border: 1px solid var(--line); border-radius: 14px; margin-bottom: 12px; overflow: hidden; }
        .group-head { width: 100%; display: flex; align-items: center; gap: 12px; padding: 16px 18px; background: #fbfcfe; border: none; cursor: pointer; font-family: inherit; text-align: left; }
        .group-head .g-ico { color: var(--muted); width: 18px; text-align: center; }
        .group-head .g-title { font-weight: 700; font-size: 0.98rem; flex: 1; }
        .g-badge { font-size: 0.72rem; font-weight: 700; padding: 4px 10px; border-radius: 999px; text-transform: uppercase; letter-spacing: 0.3px; }
        .g-badge.ok { background: var(--ok-bg); color: var(--ok-fg); }
        .g-badge.warn { background: var(--warn-bg); color: var(--warn-fg); }
        .g-badge.fail { background: var(--fail-bg); color: var(--fail-fg); }
        .group-head .chev { color: var(--muted); transition: transform .2s; }
        .group.open .chev { transform: rotate(180deg); }
        .group-body { display: none; padding: 6px 18px 14px; }
        .group.open .group-body { display: block; }
        .row { display: flex; align-items: center; gap: 12px; padding: 11px 4px; border-top: 1px solid #f1f4f9; }
        .row:first-child { border-top: none; }
        .row .r-mark { width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; flex-shrink: 0; }
        .r-mark.ok { background: var(--ok-bg); color: var(--ok-fg); }
        .r-mark.warn { background: var(--warn-bg); color: var(--warn-fg); }
        .r-mark.fail { background: var(--fail-bg); color: var(--fail-fg); }
        .row .r-main { flex: 1; min-width: 0; }
        .row .r-label { font-weight: 600; font-size: 0.92rem; }
        .row .r-hint { font-size: 0.8rem; color: var(--muted); }
        .row .r-val { font-size: 0.8rem; font-weight: 600; color: var(--ink-soft); background: #f1f5f9; padding: 3px 10px; border-radius: 8px; font-family: ui-monospace, monospace; white-space: nowrap; }

        /* Caixas de ajuda */
        .help { border-radius: 14px; padding: 18px 20px; margin-top: 6px; line-height: 1.6; font-size: 0.92rem; }
        .help h4 { margin: 0 0 8px; font-size: 1rem; display: flex; align-items: center; gap: 9px; }
        .help ul { margin: 8px 0 0; padding-left: 20px; }
        .help code { background: rgba(0,0,0,0.06); padding: 2px 7px; border-radius: 6px; font-size: 0.88em; font-weight: 600; }
        .help.warn { background: var(--warn-bg); border: 1px solid var(--warn-bd); color: #92400e; }
        .help.warn h4 { color: #92400e; }
        .help.red { background: var(--fail-bg); border: 1px solid var(--fail-bd); color: #991b1b; }
        .help.red h4 { color: #991b1b; }

        /* Formulário */
        .form-section-title { font-size: 1.05rem; font-weight: 700; margin: 4px 0 4px; display: flex; align-items: center; gap: 10px; }
        .form-section-title i { color: var(--accent); }
        .form-section-sub { color: var(--ink-soft); font-size: 0.9rem; margin: 0 0 18px; }
        .divider { height: 1px; background: var(--line); margin: 28px 0 22px; }
        .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 560px) { .grid2 { grid-template-columns: 1fr; } }
        .field { margin-bottom: 16px; }
        .field label { display: block; font-weight: 600; color: var(--ink-soft); margin-bottom: 7px; font-size: 0.88rem; }
        .field .ic { position: relative; }
        .field .ic > i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: 1rem; }
        .field input { width: 100%; padding: 13px 14px 13px 42px; border-radius: 11px; border: 1.5px solid var(--line); font-family: inherit; font-size: 0.98rem; color: var(--ink); transition: all .15s; background: #fff; }
        .field input:focus { border-color: var(--accent); outline: none; box-shadow: 0 0 0 4px color-mix(in srgb, var(--accent) 15%, transparent); }
        .field .msg { font-size: 0.78rem; margin-top: 6px; min-height: 1em; }
        .field .msg.err { color: var(--fail-fg); }
        .field .msg.good { color: var(--ok-fg); }

        /* Botões */
        .actions { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 28px; flex-wrap: wrap; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 9px; padding: 14px 26px; border-radius: 12px; font-family: inherit; font-weight: 700; font-size: 1rem; cursor: pointer; border: none; transition: all .2s; text-decoration: none; }
        .btn-primary { background: var(--accent); color: #fff; box-shadow: 0 10px 24px -10px color-mix(in srgb, var(--accent) 70%, transparent); }
        .btn-primary:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 14px 30px -10px color-mix(in srgb, var(--accent) 70%, transparent); }
        .btn-primary:disabled { background: #cbd5e1; color: #eef2f7; cursor: not-allowed; box-shadow: none; }
        .btn-ghost { background: #fff; color: var(--ink); border: 1.5px solid var(--line); }
        .btn-ghost:hover { border-color: #cbd5e1; }
        .btn-dark { background: #0f172a; color: #fff; }
        .btn-dark:hover { transform: translateY(-2px); }
        .btn-full { width: 100%; }

        /* Mensagens */
        .flash { display: flex; align-items: flex-start; gap: 11px; padding: 14px 18px; border-radius: 12px; font-weight: 500; margin-bottom: 22px; line-height: 1.5; }
        .flash i { margin-top: 2px; }
        .flash.err { background: var(--fail-bg); border: 1px solid var(--fail-bd); color: #b91c1c; }
        .flash.ok { background: var(--ok-bg); border: 1px solid var(--ok-bd); color: #15803d; }

        /* Passo final */
        .done-hero { text-align: center; padding: 10px 0 24px; }
        .done-hero .big { width: 84px; height: 84px; border-radius: 50%; background: var(--ok-bg); color: var(--ok-fg); display: flex; align-items: center; justify-content: center; font-size: 2.4rem; margin: 0 auto 18px; }
        .go-buttons { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; margin-top: 24px; }
    </style>
</head>
<body>
<div class="wizard">

    <div class="wiz-head">
        <?php if (!empty($configGeral['logo_path']) && file_exists($configGeral['logo_path'])): ?>
            <img class="wiz-logo" src="<?= htmlspecialchars($configGeral['logo_path']) ?>" alt="Logo">
        <?php else: ?>
            <div class="wiz-logo wiz-logo-fallback"><i class="fa fa-scissors"></i></div>
        <?php endif; ?>
        <div>
            <h1>Assistente de Instalação</h1>
            <p><?= htmlspecialchars($configGeral['nome_barbearia']) ?></p>
        </div>
    </div>

    <!-- STEPPER -->
    <div class="stepper" id="stepper">
        <button type="button" class="step" data-goto="check">
            <span class="dot">1</span>
            <span class="txt"><b>Verificação</b><span>Requisitos do sistema</span></span>
        </button>
        <span class="step-sep"></span>
        <button type="button" class="step" data-goto="config" <?= ($podeInstalar || $admin_setup_error) ? '' : 'disabled' ?>>
            <span class="dot">2</span>
            <span class="txt"><b>Configuração</b><span>Admin e barbearia</span></span>
        </button>
        <span class="step-sep"></span>
        <button type="button" class="step" data-goto="done" <?= $users_file_exists ? '' : 'disabled' ?>>
            <span class="dot">3</span>
            <span class="txt"><b>Pronto</b><span>Finalizar</span></span>
        </button>
    </div>

    <?php if ($erro_critico_banco): ?>
        <div class="flash err"><i class="fa fa-triangle-exclamation"></i> <?= htmlspecialchars($erro_critico_banco) ?></div>
    <?php endif; ?>

    <!-- ==================== PASSO 1: VERIFICAÇÃO ==================== -->
    <section class="panel" id="panel-check" data-step="check">
        <h2>Verificação do sistema</h2>
        <p class="lead">Conferimos automaticamente se o servidor atende aos requisitos e se as pastas têm permissão de escrita.</p>

        <?php if ($requisitosCriticosOK): ?>
            <div class="summary good">
                <div class="s-ico"><i class="fa fa-circle-check"></i></div>
                <div>
                    <b>Tudo pronto para instalar</b>
                    <small>As tabelas e configurações já foram criadas nos bastidores.<?= $totalAvisos ? ' Há ' . $totalAvisos . ' aviso(s) opcional(is) — não impedem a instalação.' : '' ?></small>
                </div>
            </div>
        <?php else: ?>
            <div class="summary bad">
                <div class="s-ico"><i class="fa fa-circle-exclamation"></i></div>
                <div>
                    <b>Ação necessária antes de continuar</b>
                    <small>Um ou mais requisitos obrigatórios falharam. Veja em vermelho abaixo o que corrigir.</small>
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($grupos as $g):
            $nFail = 0; $nWarn = 0;
            foreach ($g['itens'] as $it) {
                if ($it['state'] === 'warn') $nWarn++;
                elseif ($it['state'] === 'fail') $nFail++;
            }
            $abrir = false; // todas as abas iniciam recolhidas
            if ($nFail > 0) { $bcls = 'fail'; $btxt = $nFail . ' problema(s)'; }
            elseif ($nWarn > 0) { $bcls = 'warn'; $btxt = $nWarn . ' aviso(s)'; }
            else { $bcls = 'ok'; $btxt = 'Tudo certo'; }
        ?>
            <div class="group <?= $abrir ? 'open' : '' ?>">
                <button type="button" class="group-head" onclick="this.parentNode.classList.toggle('open')">
                    <i class="fa <?= $g['icon'] ?> g-ico"></i>
                    <span class="g-title"><?= htmlspecialchars($g['titulo']) ?></span>
                    <span class="g-badge <?= $bcls ?>"><?= $btxt ?></span>
                    <i class="fa fa-chevron-down chev"></i>
                </button>
                <div class="group-body">
                    <?php foreach ($g['itens'] as $it): $m = inst_state_meta($it['state']); ?>
                        <div class="row">
                            <span class="r-mark <?= $m['cls'] ?>"><i class="fa <?= $m['icon'] ?>"></i></span>
                            <div class="r-main">
                                <div class="r-label"><?= htmlspecialchars($it['label']) ?></div>
                                <?php if (!empty($it['hint'])): ?><div class="r-hint"><?= htmlspecialchars($it['hint']) ?></div><?php endif; ?>
                            </div>
                            <span class="r-val" title="<?= htmlspecialchars($it['value']) ?>"><?= htmlspecialchars($it['value']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (!$permissao_dados_ok): ?>
            <div class="help warn" style="margin-top:18px;">
                <h4><i class="fa fa-folder-open"></i> Como corrigir as permissões</h4>
                <p style="margin:0;">No painel da sua hospedagem, defina permissão <code>775</code> nestas pastas:</p>
                <ul>
                    <li><code>_dados/</code> — banco de dados (obrigatória)</li>
                    <li><code>_logs/</code> — registros de erro</li>
                    <li><code>uploads/</code> — fotos e logo</li>
                </ul>
            </div>
        <?php endif; ?>

        <div class="actions" style="justify-content:flex-end;">
            <button type="button" class="btn btn-primary" data-goto="config" <?= $podeInstalar ? '' : 'disabled' ?>>
                <?php if ($podeInstalar): ?>
                    Continuar <i class="fa fa-arrow-right"></i>
                <?php else: ?>
                    <i class="fa fa-lock"></i> Corrija os itens em vermelho
                <?php endif; ?>
            </button>
        </div>
    </section>

    <!-- ==================== PASSO 2: CONFIGURAÇÃO ==================== -->
    <section class="panel" id="panel-config" data-step="config">
        <h2>Configuração inicial</h2>
        <p class="lead">Crie o acesso do administrador e informe os dados da barbearia. Tudo pode ser alterado depois no painel.</p>

        <?php if ($admin_setup_error): ?>
            <div class="flash err"><i class="fa fa-circle-xmark"></i> <?= htmlspecialchars($admin_setup_error) ?></div>
        <?php endif; ?>

        <?php if ($users_file_exists): ?>
            <div class="help red">
                <h4><i class="fa fa-lock"></i> Administrador já configurado</h4>
                <p style="margin:0;">O usuário administrador já existe no banco de dados. Se você abriu esta página apenas para atualizar o banco, isso <strong>já foi feito automaticamente</strong> ao carregar. Vá para o último passo.</p>
            </div>
            <div class="actions" style="justify-content:flex-end;">
                <button type="button" class="btn btn-primary" data-goto="done">Ir para o final <i class="fa fa-arrow-right"></i></button>
            </div>
        <?php else: ?>
            <form method="POST" id="setupForm">
                <input type="hidden" name="action" value="criar_admin">

                <div class="form-section-title"><i class="fa fa-user-shield"></i> Acesso do administrador</div>
                <p class="form-section-sub">Esse será o login mestre do painel de controle.</p>

                <div class="grid2">
                    <div class="field">
                        <label for="username">Usuário</label>
                        <div class="ic"><i class="fa fa-user"></i>
                            <input type="text" name="username" id="username" placeholder="Ex: admin" autocomplete="username" required>
                        </div>
                    </div>
                    <div class="field">
                        <label for="password">Senha</label>
                        <div class="ic"><i class="fa fa-key"></i>
                            <input type="password" name="password" id="password" placeholder="Mínimo 8 caracteres" minlength="8" autocomplete="new-password" required>
                        </div>
                        <div class="msg" id="pwMsg"></div>
                    </div>
                </div>
                <div class="field" style="max-width:calc(50% - 8px);">
                    <label for="confirm_password">Confirmar senha</label>
                    <div class="ic"><i class="fa fa-check-double"></i>
                        <input type="password" name="confirm_password" id="confirm_password" placeholder="Repita a senha" autocomplete="new-password" required>
                    </div>
                    <div class="msg" id="pwMatch"></div>
                </div>

                <div class="divider"></div>

                <div class="form-section-title"><i class="fa fa-store"></i> Dados da barbearia</div>
                <p class="form-section-sub">Aparecem no site. Depois você ajusta em Configurações &gt; Informações Gerais.</p>

                <div class="grid2">
                    <div class="field">
                        <label for="nome_barbearia">Nome da barbearia</label>
                        <div class="ic"><i class="fa fa-signature"></i>
                            <input type="text" name="nome_barbearia" id="nome_barbearia" placeholder="Ex: Barbearia do Zé" value="Sua Barbearia" required>
                        </div>
                    </div>
                    <div class="field">
                        <label for="header_slogan">Slogan <span style="color:var(--muted);font-weight:400;">(opcional)</span></label>
                        <div class="ic"><i class="fa fa-quote-left"></i>
                            <input type="text" name="header_slogan" id="header_slogan" placeholder="Ex: Barba, cabelo e amigos">
                        </div>
                    </div>
                    <div class="field">
                        <label for="telefone_contato">Telefone / WhatsApp</label>
                        <div class="ic"><i class="fa fa-phone"></i>
                            <input type="text" name="telefone_contato" id="telefone_contato" placeholder="(21) 99999-9999">
                        </div>
                    </div>
                    <div class="field">
                        <label for="endereco">Endereço</label>
                        <div class="ic"><i class="fa fa-location-dot"></i>
                            <input type="text" name="endereco" id="endereco" placeholder="Rua Exemplo, 123 - Centro">
                        </div>
                    </div>
                    <div class="field">
                        <label for="link_whatsapp">Link do WhatsApp <span style="color:var(--muted);font-weight:400;">(opcional)</span></label>
                        <div class="ic"><i class="fab fa-whatsapp"></i>
                            <input type="text" name="link_whatsapp" id="link_whatsapp" placeholder="5521999999999">
                        </div>
                    </div>
                    <div class="field">
                        <label for="link_instagram">Instagram <span style="color:var(--muted);font-weight:400;">(opcional)</span></label>
                        <div class="ic"><i class="fab fa-instagram"></i>
                            <input type="text" name="link_instagram" id="link_instagram" placeholder="https://instagram.com/...">
                        </div>
                    </div>
                    <div class="field">
                        <label for="link_facebook">Facebook <span style="color:var(--muted);font-weight:400;">(opcional)</span></label>
                        <div class="ic"><i class="fab fa-facebook-f"></i>
                            <input type="text" name="link_facebook" id="link_facebook" placeholder="https://facebook.com/...">
                        </div>
                    </div>
                </div>

                <div class="actions">
                    <button type="button" class="btn btn-ghost" data-goto="check"><i class="fa fa-arrow-left"></i> Voltar</button>
                    <button type="submit" class="btn btn-primary" <?= $permissao_dados_ok ? '' : 'disabled' ?>>
                        <i class="fa <?= $permissao_dados_ok ? 'fa-circle-check' : 'fa-lock' ?>"></i>
                        <?= $permissao_dados_ok ? 'Criar conta e finalizar' : 'Pasta _dados/ sem permissão' ?>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <!-- ==================== PASSO 3: PRONTO ==================== -->
    <section class="panel" id="panel-done" data-step="done">
        <div class="done-hero">
            <div class="big"><i class="fa fa-circle-check"></i></div>
            <h2 style="margin-bottom:6px;">Instalação concluída!</h2>
            <p class="lead" style="margin:0 auto; max-width:520px;">O banco de dados está pronto e as configurações foram salvas. Sua barbearia já pode receber agendamentos.</p>
        </div>

        <?php if ($admin_setup_success): ?>
            <div class="flash ok"><i class="fa fa-circle-check"></i> <?= htmlspecialchars($admin_setup_success) ?></div>
        <?php endif; ?>

        <div class="help red">
            <h4><i class="fa fa-shield-halved"></i> Ação de segurança importante</h4>
            <p style="margin:0;">O logo (<code>uploads/logo.png</code>) e o vídeo de fundo (<code>uploads/bg_video.mp4</code>) já vêm configurados. Por segurança, <strong>apague o arquivo <code>install.php</code></strong> do servidor agora que a instalação terminou.</p>
        </div>

        <div class="go-buttons">
            <a href="admin.php" class="btn btn-dark"><i class="fa fa-right-to-bracket"></i> Ir para o painel admin</a>
            <a href="index.php" class="btn btn-ghost"><i class="fa fa-globe"></i> Ver o site</a>
        </div>
    </section>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const panels = document.querySelectorAll('.panel');
    const steps  = document.querySelectorAll('.stepper .step');
    const order  = ['check', 'config', 'done'];

    function goTo(step) {
        const btn = document.querySelector('.stepper .step[data-goto="' + step + '"]');
        if (btn && btn.disabled) return; // passo bloqueado
        panels.forEach(p => p.classList.toggle('active', p.dataset.step === step));
        const idx = order.indexOf(step);
        steps.forEach(s => {
            const sIdx = order.indexOf(s.dataset.goto);
            s.classList.toggle('active', s.dataset.goto === step);
            s.classList.toggle('done-step', sIdx < idx);
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    document.querySelectorAll('[data-goto]').forEach(el => {
        el.addEventListener('click', () => goTo(el.dataset.goto));
    });

    // Validação de senha em tempo real
    const pw = document.getElementById('password');
    const cpw = document.getElementById('confirm_password');
    const pwMsg = document.getElementById('pwMsg');
    const pwMatch = document.getElementById('pwMatch');
    function checkPw() {
        if (!pw) return;
        if (pw.value.length > 0 && pw.value.length < 8) {
            pwMsg.textContent = 'Faltam ' + (8 - pw.value.length) + ' caractere(s).';
            pwMsg.className = 'msg err';
        } else if (pw.value.length >= 8) {
            pwMsg.textContent = 'Senha com tamanho adequado.';
            pwMsg.className = 'msg good';
        } else {
            pwMsg.textContent = ''; pwMsg.className = 'msg';
        }
        if (cpw && cpw.value.length > 0) {
            const ok = cpw.value === pw.value;
            pwMatch.textContent = ok ? 'As senhas coincidem.' : 'As senhas não coincidem.';
            pwMatch.className = 'msg ' + (ok ? 'good' : 'err');
        } else if (pwMatch) {
            pwMatch.textContent = ''; pwMatch.className = 'msg';
        }
    }
    if (pw) pw.addEventListener('input', checkPw);
    if (cpw) cpw.addEventListener('input', checkPw);

    // Passo inicial definido pelo servidor
    goTo(<?= json_encode($stepInicial) ?>);
});
</script>
</body>
</html>
