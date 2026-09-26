<?php
require_once 'functions.php';
iniciarSessaoSegura();

// Geração de Token CSRF para segurança geral e do logout
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =========================================================================
// 1. CONFIGURAÇÕES INICIAIS E SEGURANÇA
// =========================================================================
date_default_timezone_set('America/Sao_Paulo');

$timeout_duration = 3600; // 60 minutos
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header('Location: login.php?error=' . urlencode('Sua sessão expirou por inatividade. Faça login novamente.'));
    exit;
}
$_SESSION['LAST_ACTIVITY'] = time();

if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

// Validação do Token no Logout
if (isset($_GET['logout'])) {
    if (isset($_GET['token']) && hash_equals($_SESSION['csrf_token'], $_GET['token'])) {
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    } else {
        header('Location: admin.php?error=' . urlencode('Token de segurança inválido ao tentar sair.'));
        exit;
    }
}

// =========================================================================
// 2. DEPENDÊNCIAS
// =========================================================================
require_once 'functions.php';
garantirCamposComissaoAssinatura();
garantirEstruturaGestaoAdmin();
$_SESSION['admin_role'] = obterPerfilAdminUsuario($_SESSION['username'] ?? '');

// =========================================================================
// 3. DEFINIÇÕES DE TABELAS (SQLite) E CHAVES
// =========================================================================
$tabelaBarbeiros              = 'barbeiros'; 
$tabelaServicos               = 'servicos'; 
$tabelaCombos                 = 'combos'; 
$tabelaAgendamentos           = 'agendamentos'; 
$tabelaCategorias             = 'categorias';
$tabelaAvaliacoesDestacadas   = 'avaliacoes_destacadas';
$tabelaRespostasAvaliacoes    = 'respostas_avaliacoes';
$tabelaClientes               = 'clientes'; 
$tabelaAvaliacoes             = 'avaliacoes'; 
$tabelaCupoes                 = 'cupoes'; 
$tabelaVouchers               = 'vouchers';
$tabelaDespesas               = 'despesas';
$tabelaComissoesPagas         = 'comissoes_pagas';

$keys_barbeiros               = ['id', 'nome', 'foto', 'username', 'password', 'status', 'servicos_ids', 'comissao', 'comissao_produtos', 'comissao_assinatura_tipo', 'comissao_assinatura_valor']; 
$keys_servicos                = ['id', 'nome', 'valor', 'slots', 'categoria_id', 'descricao'];
$keys_combos                  = ['id', 'nome', 'servicos_ids', 'valor', 'categoria_id']; 
$keys_categorias              = ['id', 'nome', 'ordem'];
$keys_avaliacoes_destacadas   = ['id_avaliacao'];
$keys_respostas_avaliacoes    = ['id_resposta', 'id_avaliacao', 'texto_resposta', 'timestamp'];
$keys_agendamentos            = ['id', 'nome', 'email', 'telefone', 'barbeiro_id', 'servicos_ids', 'data', 'hora', 'status', 'desconto_aplicado', 'tipo_desconto', 'observacoes', 'produtos_vendidos', 'plano_provisorio', 'cliente_id', 'presenca_confirmada'];
$keys_clientes                = ['id', 'nome', 'email', 'telefone', 'password_hash', 'data_nascimento', 'foto_perfil', 'codigo_indicacao', 'cpf', 'indicado_por_id', 'status', 'confirmation_token'];
$keys_avaliacoes              = ['id', 'agendamento_id', 'cliente_id', 'barbeiro_id', 'rating', 'comment', 'timestamp']; 
$keys_cupoes                  = ['id', 'codigo', 'desconto_percentual', 'usos_maximos', 'data_validade', 'usos_atuais']; 
$keys_vouchers                = ['id', 'codigo', 'valor', 'status', 'data_criacao', 'agendamento_id_uso', 'data_validade'];
$keys_despesas                = ['id', 'descricao', 'valor', 'data_vencimento', 'data_pagamento', 'status', 'categoria'];
// A coluna do valor pago chama-se 'valor' (é o que actions/financeiro.php grava).
// Pedir 'valor_comissao' fazia o SELECT falhar e lerDados() devolver sempre [] —
// a variável ficava vazia em todo carregamento do painel.
$keys_comissoes_pagas         = ['id', 'barbeiro_id', 'mes_ano', 'valor_total_servicos', 'valor', 'gorjeta', 'data_pagamento'];

$dias_semana_texto            = ['0' => 'Domingo', '1' => 'Segunda', '2' => 'Terça', '3' => 'Quarta', '4' => 'Quinta', '5' => 'Sexta', '6' => 'Sábado'];
$meses_texto                  = ['01' => 'Janeiro', '02' => 'Fevereiro', '03' => 'Março', '04' => 'Abril', '05' => 'Maio', '06' => 'Junho', '07' => 'Julho', '08' => 'Agosto', '09' => 'Setembro', '10' => 'Outubro', '11' => 'Novembro', '12' => 'Dezembro'];

// =========================================================================
// 4. CARREGAMENTO DE DADOS (BANCO DE DADOS)
// =========================================================================
$configGeral            = carregarConfigGeral();
$configAgendamento      = carregarConfigAgendamento();
$configEmail            = carregarConfigEmail();
$configIndicacao        = getIndicacaoConfig();

$barbeirosArr           = lerDados($tabelaBarbeiros, $keys_barbeiros);
$servicosArr            = lerDados($tabelaServicos, $keys_servicos);
$combosArr              = lerDados($tabelaCombos, $keys_combos);
$agendamentosArr        = lerDados($tabelaAgendamentos, $keys_agendamentos);
$categoriasArr          = lerDados($tabelaCategorias, $keys_categorias); 
$avaliacoesDestacadasArr= lerDados($tabelaAvaliacoesDestacadas, $keys_avaliacoes_destacadas);
$clientesArr            = lerDados($tabelaClientes, $keys_clientes); 
$avaliacoesArr          = lerDados($tabelaAvaliacoes, $keys_avaliacoes); 
$cupoesArr              = lerDados($tabelaCupoes, $keys_cupoes); 
$vouchersArr            = lerDados($tabelaVouchers, $keys_vouchers); 
$despesasArr            = lerDados($tabelaDespesas, $keys_despesas);
$comissoesPagasArr      = lerDados($tabelaComissoesPagas, $keys_comissoes_pagas);

$respostasAvaliacoesRaw = lerDados($tabelaRespostasAvaliacoes, $keys_respostas_avaliacoes);
$respostasAvaliacoesArr = [];
foreach ($respostasAvaliacoesRaw as $resposta) {
    if (isset($resposta['id_avaliacao'])) {
        $respostasAvaliacoesArr[$resposta['id_avaliacao']] = $resposta;
    }
}

// Leitura segura do config genérico e financeiro
$config = function_exists('_lerConfigSQLite') ? _lerConfigSQLite('config', ['aprovar' => 'manual']) : ['aprovar' => 'manual'];
$pontosFidelidade  = getAllFidelityPoints(); 
$configFidelidade  = getFidelityConfig(); 
$configAniversario = getAniversarioConfig(); 
$anotacoesClientes = lerAnotacoesTodosClientes(); 

// Leitura segura de administradores
$usersArr = [];
if (function_exists('getDB')) {
    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (username TEXT PRIMARY KEY, password_hash TEXT NOT NULL)");
        $stmt = $pdo->query("SELECT username, password_hash, role FROM users");
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $usersArr[$row['username']] = [
                    'username' => $row['username'],
                    'password' => $row['password_hash'],
                    'role' => $row['role'] ?: 'proprietario',
                ];
            }
        }
    } catch (PDOException $e) {
        if (function_exists('log_activity')) log_activity("FALHA: Erro ao ler users do SQLite. Erro: " . $e->getMessage());
    }
}

// =========================================================================
// 5. PROCESSAMENTO E AÇÕES
// =========================================================================
require_once 'admin_actions.php';
require_once 'admin_data.php';
$gestaoData = obterDadosGestaoAdmin($agendamentosArr, $clientesArr, $barbeirosArr, $servicosArr, $planosArr, $avaliacoesArr, $combosArr, $produtosArr ?? []);
$gestaoData['alertas']  = filtrarAlertasPorPermissaoAdmin($gestaoData['alertas']);
$gestaoAlertasResumo    = resumirAlertasGestaoAdmin($gestaoData['alertas']);
$gestaoAlertasPendentes = $gestaoAlertasResumo['pendentes'];
if (($_GET['tab'] ?? '') === 'gestao') {
    $legacyDashboardParams = $_GET;
    $legacyDashboardParams['tab'] = 'dashboard';
    header('Location: admin.php?' . http_build_query($legacyDashboardParams));
    exit;
}

// =========================================================================
// 5.1. CARREGAMENTO SOB DEMANDA DE UMA ABA (AJAX)
// Retorna apenas o HTML da aba pedida, reaproveitando todo o carregamento
// de dados acima, para o painel não precisar renderizar as 12 abas a cada
// requisição.
// =========================================================================
$abasValidas = ['dashboard', 'agendamentos', 'financeiro', 'relatorios', 'clientes', 'assinaturas', 'servicos', 'barbeiros', 'marketing', 'fidelidade', 'avaliacoes', 'landingpage', 'configuracoes'];
if (isset($_GET['ajax_tab'])) {
    $tabSolicitada = $_GET['ajax_tab'];
    if (!in_array($tabSolicitada, $abasValidas, true) || ($tabSolicitada !== 'dashboard' && !adminPodeAcessarAba($tabSolicitada))) {
        http_response_code(403);
        exit;
    }
    $abaAtiva = $tabSolicitada;
    include 'admin_tabs/' . $tabSolicitada . '.php';
    exit;
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - <?= htmlspecialchars($configGeral['nome_barbearia']) ?></title>

    <script>
        // Define o tema antes da primeira pintura (evita flash). Segue a
        // preferência salva; se não houver, segue o tema do sistema.
        (function () {
            try {
                var pref = localStorage.getItem('admin_theme');
                if (!pref) {
                    pref = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
                }
                document.documentElement.classList.add(pref === 'dark' ? 'dark-mode' : 'light-mode');
            } catch (e) {
                document.documentElement.classList.add('light-mode');
            }
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script> 
    <link rel="stylesheet" href="<?= assetUrl('css/style.css') ?>">
    <link rel="stylesheet" href="css/custom_theme.css.php">
    <link rel="stylesheet" href="<?= assetUrl('css/admin_style.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/admin_components.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/admin_gestao.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/admin_theme.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/notif_agendamentos.css') ?>">

    <style>
        .swal2-popup { border-radius: 16px !important; font-family: 'Inter', sans-serif !important; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1) !important; }
        .swal2-title { color: #1e293b !important; font-weight: 800 !important; font-size: 1.5rem !important; }
        .swal2-html-container { color: #475569 !important; font-weight: 500 !important; font-size: 1.05rem !important; }
        .swal2-confirm, .swal2-cancel { border-radius: 10px !important; font-weight: 700 !important; padding: 12px 24px !important; font-size: 1rem !important; transition: 0.2s !important; }
        .swal2-cancel { background-color: #f1f5f9 !important; color: #475569 !important; }
        .swal2-cancel:hover { background-color: #e2e8f0 !important; }
        .btn-swal-danger { background-color: #ef4444 !important; box-shadow: 0 4px 10px rgba(239, 68, 68, 0.2) !important; }
        .btn-swal-danger:hover { background-color: #dc2626 !important; transform: translateY(-2px); }
        .btn-swal-primary { background-color: var(--secondary-color, #007bff) !important; box-shadow: 0 4px 10px rgba(0, 123, 255, 0.2) !important; }
        .btn-swal-primary:hover { filter: brightness(0.9); transform: translateY(-2px); }

        /* --- MENSAGENS DE SUCESSO/ERRO PREMIUM --- */
        .modern-alert {
            display: flex; align-items: center; padding: 18px 25px; border-radius: 16px; margin-bottom: 25px;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05), 0 8px 10px -6px rgba(0,0,0,0.01);
            animation: fadeInAlert 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275); position: relative; overflow: hidden; width: 100%;
        }
        @keyframes fadeInAlert { from { opacity: 0; transform: translateY(-20px) scale(0.95); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .modern-alert::before { content: ''; position: absolute; top: 0; left: 0; width: 6px; height: 100%; }
        .modern-alert.success { background: linear-gradient(to right, #f0fdf4, #ffffff); border: 1px solid #bbf7d0; color: #166534; }
        .modern-alert.success::before { background-color: #10b981; }
        .modern-alert.error { background: linear-gradient(to right, #fef2f2, #ffffff); border: 1px solid #fecaca; color: #991b1b; }
        .modern-alert.error::before { background-color: #ef4444; }
        .modern-alert-icon { font-size: 2rem; margin-right: 18px; flex-shrink: 0; }
        .modern-alert.success .modern-alert-icon { color: #10b981; }
        .modern-alert.error .modern-alert-icon { color: #ef4444; }
        .modern-alert-content { flex-grow: 1; display: flex; align-items: center; }
        .modern-alert-text { font-size: 1.1rem; font-weight: 600; margin: 0; opacity: 1; line-height: 1.5; color: inherit; }
        .modern-alert-close { background: transparent; border: none; font-size: 1.2rem; cursor: pointer; opacity: 0.4; transition: 0.2s; padding: 5px; margin-left: 10px; }
        .modern-alert-close:hover { opacity: 1; transform: scale(1.1); }
    </style>
</head>

<body class="admin-page">
<?php 
    // Lê a aba da URL para manter a navegação consistente após ações
    $abaAtiva = $_GET['tab'] ?? 'dashboard';
    if (!adminPodeAcessarAba($abaAtiva)) {
        $abaAtiva = 'dashboard';
    }
?>

<!-- Notificações ricas de novos agendamentos (cartões flutuantes, responsivos) -->
<div id="novo-agendamento-stack" class="na-stack" aria-live="polite" aria-atomic="false"></div>
<audio id="audio-notificacao" src="https://assets.mixkit.co/sfx/preview/mixkit-software-interface-start-2574.mp3" preload="auto"></audio>

<style>
<?php foreach (['agendamentos', 'financeiro', 'relatorios', 'clientes', 'servicos', 'barbeiros', 'marketing', 'fidelidade', 'avaliacoes', 'landingpage', 'configuracoes'] as $abaPermissao): ?>
    <?php if (!adminPodeAcessarAba($abaPermissao)): ?>
        .tab-btn[data-tab="<?= $abaPermissao ?>"], #<?= $abaPermissao ?> { display: none !important; }
    <?php endif; ?>
<?php endforeach; ?>
</style>

<div class="admin-layout">
    
    <aside class="admin-sidebar">
        <div class="sidebar-header">
            <?php 
            // Correção da Foto do Logo: Verifica se existe, senão usa padrão
            $logoPath = (!empty($configGeral['logo_path']) && file_exists($configGeral['logo_path'])) 
                        ? $configGeral['logo_path'] 
                        : 'uploads/logo.png'; 
            ?>
            <img src="<?= htmlspecialchars($logoPath) ?>" alt="Logo" onerror="this.src='uploads/logo.png';">
            <div>
                <h2>Painel Admin</h2>
                <span class="barbearia-name"><?= htmlspecialchars($configGeral['nome_barbearia']) ?></span>
            </div>
        </div>

        <nav class="sidebar-nav tab">
            <?php
            $navGrupos = [
                'Operação' => [
                    'dashboard'    => ['fa-gauge-high', 'Dashboard'],
                    'agendamentos' => ['fa-calendar-check', 'Agendamentos'],
                    'clientes'     => ['fa-users', 'Clientes'],
                    'servicos'     => ['fa-tags', 'Serviços & Estoque'],
                    'barbeiros'    => ['fa-user-tie', 'Barbeiros'],
                ],
                'Negócio' => [
                    'financeiro'  => ['fa-sack-dollar', 'Financeiro (RH)'],
                    'assinaturas' => ['fa-crown', 'Assinaturas'],
                    'relatorios'  => ['fa-chart-line', 'Relatórios'],
                    'marketing'   => ['fa-bullhorn', 'Marketing'],
                    'fidelidade'  => ['fa-star', 'Fidelidade'],
                    'avaliacoes'  => ['fa-star-half-stroke', 'Avaliações'],
                ],
                'Site & Sistema' => [
                    'landingpage'   => ['fa-paint-roller', 'Landing Page'],
                    'configuracoes' => ['fa-gear', 'Configurações'],
                ],
            ];
            foreach ($navGrupos as $tituloGrupo => $itens):
                // O grupo só aparece se houver ao menos uma aba acessível nele
                // (o Dashboard é sempre acessível).
                $temAcesso = false;
                foreach ($itens as $slug => $info) {
                    if ($slug === 'dashboard' || adminPodeAcessarAba($slug)) { $temAcesso = true; break; }
                }
                if (!$temAcesso) continue;
            ?>
                <div class="nav-group-title"><?= htmlspecialchars($tituloGrupo) ?></div>
                <?php foreach ($itens as $slug => $info): ?>
                    <button class="tab-btn <?= $abaAtiva == $slug ? 'active' : '' ?>" data-tab="<?= $slug ?>"><i class="fa <?= $info[0] ?>"></i> <span><?= htmlspecialchars($info[1]) ?></span></button>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            Desenvolvido com <span style="color: #ef4444;">❤</span> por <a href="https://instagram.com/ggxuao" target="_blank" rel="noopener noreferrer">João Gonçalves</a>
        </div>
    </aside>

    <main class="admin-main">
        <header class="admin-topbar">
            <div class="topbar-left">
                <span style="color: #94a3b8; font-weight: 500; font-size: 0.95rem;" id="boas-vindas-msg">Bem-vindo(a) ao painel</span>
            </div>
            
            <div class="topbar-right">
                <button type="button" class="admin-command-search" id="admin-global-search-open" title="Busca global">
                    <i class="fa fa-search"></i>
                    <span>Buscar no painel</span>
                    <kbd>Ctrl K</kbd>
                </button>
                <button type="button" class="admin-theme-toggle" id="admin-theme-toggle" title="Alternar tema claro/escuro" aria-label="Alternar tema claro/escuro">
                    <i class="fa fa-moon"></i>
                </button>
                <div class="admin-alert-center">
                    <button type="button" class="admin-alert-trigger" id="admin-alert-trigger"
                            title="Alertas operacionais" aria-label="Alertas operacionais"
                            aria-haspopup="dialog" aria-expanded="false" aria-controls="admin-alert-popover">
                        <i class="fa fa-bell"></i>
                        <span id="admin-alert-badge" class="<?= $gestaoAlertasPendentes === 0 ? 'is-clear' : '' ?>"><?= $gestaoAlertasPendentes ?></span>
                    </button>
                    <div class="admin-alert-popover" id="admin-alert-popover" role="dialog" aria-label="Alertas operacionais">
                        <div class="admin-alert-popover-head">
                            <div class="admin-alert-popover-title">
                                <strong>Alertas operacionais</strong>
                                <small id="admin-alert-updated">atualizado agora</small>
                            </div>
                            <button type="button" class="admin-alert-refresh" id="admin-alert-refresh"
                                    title="Atualizar alertas" aria-label="Atualizar alertas"><i class="fa fa-arrows-rotate"></i></button>
                        </div>
                        <div class="admin-alert-summary" id="admin-alert-summary"></div>
                        <div class="admin-alert-popover-list" id="admin-alert-list"></div>
                    </div>
                </div>
                <div class="topbar-datetime" id="topbar-datetime">
                    <i class="fa fa-spinner fa-spin"></i>
                </div>
                
                <div class="topbar-actions">
                    <div class="admin-user-chip">
                        <div class="admin-user-avatar">
                            <?= strtoupper(substr($_SESSION['username'] ?? 'A', 0, 1)) ?>
                        </div>
                        <span>
                            <?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?>
                        </span>
                    </div>
                    <button type="button" data-modal-target="#modal-reportar-bug" class="topbar-icon-btn is-support" title="Central de suporte" aria-label="Central de suporte"><i class="fa fa-headset"></i></button>
                    <a href="#" onclick="reloadCurrentTab(event)" class="topbar-icon-btn" title="Atualizar página" aria-label="Atualizar página"><i class="fa fa-arrows-rotate"></i></a>
                    <a href="?logout=1&token=<?= $_SESSION['csrf_token'] ?>" class="btn-logout" title="Sair" aria-label="Sair"><i class="fa fa-right-from-bracket"></i></a>
                </div>
            </div>
        </header>

        <div class="admin-content-area">
            
            <?php if (isset($_GET['success']) || isset($_GET['msg']) || isset($_GET['error'])): 
                $isErro = isset($_GET['error']);
                $alertClass = $isErro ? 'error' : 'success';
                $alertIcon = $isErro ? 'fa-exclamation-triangle' : 'fa-check-circle';
                $alertMessage = $_GET['error'] ?? $_GET['success'] ?? $_GET['msg'];
            ?>
                <div class="modern-alert <?= $alertClass ?>" id="globalAlert">
                    <div class="modern-alert-icon">
                        <i class="fa <?= $alertIcon ?>"></i>
                    </div>
                    <div class="modern-alert-content">
                        <p class="modern-alert-text"><?= htmlspecialchars($alertMessage) ?></p>
                    </div>
                    <button type="button" class="modern-alert-close" onclick="document.getElementById('globalAlert').style.display='none'">
                        <i class="fa fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>

            <?php foreach ($abasValidas as $abaSlug):
                if ($abaSlug !== 'dashboard' && !adminPodeAcessarAba($abaSlug)) continue;
                $ehAbaAtiva = $abaAtiva === $abaSlug;
            ?>
                <div id="<?= $abaSlug ?>" class="tabcontent <?= $ehAbaAtiva ? 'active' : '' ?>" <?= $ehAbaAtiva ? 'data-loaded="1"' : 'data-lazy="1"' ?>>
                    <?php if ($ehAbaAtiva): include 'admin_tabs/' . $abaSlug . '.php'; endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </main>

</div>

<div class="admin-search-overlay" id="admin-search-overlay" aria-hidden="true">
    <div class="admin-search-dialog" role="dialog" aria-modal="true" aria-label="Busca global">
        <div class="admin-search-input-wrap">
            <i class="fa fa-search"></i>
            <input type="search" id="admin-global-search-input"
                   placeholder="Buscar clientes, agendamentos, serviços, produtos, seções…"
                   autocomplete="off" spellcheck="false"
                   role="combobox" aria-expanded="true" aria-autocomplete="list"
                   aria-controls="admin-global-search-results">
            <button type="button" id="admin-global-search-close" title="Fechar busca (Esc)" aria-label="Fechar busca"><i class="fa fa-times"></i></button>
        </div>
        <div class="admin-search-filters" id="admin-global-search-filters" role="group" aria-label="Filtrar por tipo"></div>
        <div class="admin-search-results" id="admin-global-search-results" role="listbox" aria-label="Resultados da busca">
            <div class="admin-search-empty"><i class="fa fa-keyboard"></i><span>Digite para localizar qualquer item do painel.</span></div>
        </div>
        <div class="admin-search-footer">
            <span id="admin-global-search-count">&nbsp;</span>
            <span class="admin-search-keys">
                <kbd>↑</kbd><kbd>↓</kbd> navegar · <kbd>Enter</kbd> abrir · <kbd>Esc</kbd> fechar
            </span>
        </div>
    </div>
</div>

<?php include_once 'admin_modals.php'; ?>

<script>
const adminJSData = <?= json_encode($adminJSData ?? []) ?>;
const dashboardChartData = adminJSData.dashboardChartData || {};
const relatoriosChartData = adminJSData.relatoriosChartData || {};
const avaliacoesChartData = adminJSData.avaliacoesChartData || {};
const clientesData = adminJSData.clientesData ? Object.values(adminJSData.clientesData) : [];
const agendamentosData = adminJSData.agendamentosData || [];
const anotacoesData = adminJSData.anotacoesClientesData || {};
const barbeirosData = adminJSData.barbeirosData || {};
const combosData = adminJSData.combosData ? Object.values(adminJSData.combosData) : [];
const servicosData = adminJSData.servicosData || {};
const categoriasData = adminJSData.categoriasData ? Object.values(adminJSData.categoriasData) : [];
const horariosTrabalhoData = adminJSData.horariosTrabalhoData || [];
const avaliacoesData = adminJSData.avaliacoesData || [];
const clientesInfoData = adminJSData.clientesInfoData || {};
const diasSemanaTexto = <?= json_encode($dias_semana_texto) ?>;
// CORREÇÃO: Removido o espaço do nome da variável
const respostasAvaliacoesData = adminJSData.respostasAvaliacoesData || {};
<?php
// Atalhos de navegação ("Ir para") — respeitam as permissões do perfil.
$navBuscaLabels = [
    'dashboard'     => ['fa-gauge-high', 'Dashboard'],
    'agendamentos'  => ['fa-calendar-check', 'Agendamentos'],
    'clientes'      => ['fa-users', 'Clientes'],
    'servicos'      => ['fa-tags', 'Serviços & Estoque'],
    'barbeiros'     => ['fa-user-tie', 'Barbeiros'],
    'financeiro'    => ['fa-sack-dollar', 'Financeiro (RH)'],
    'assinaturas'   => ['fa-crown', 'Assinaturas'],
    'relatorios'    => ['fa-chart-line', 'Relatórios'],
    'marketing'     => ['fa-bullhorn', 'Marketing'],
    'fidelidade'    => ['fa-star', 'Fidelidade'],
    'avaliacoes'    => ['fa-star-half-stroke', 'Avaliações'],
    'landingpage'   => ['fa-paint-roller', 'Landing Page'],
    'configuracoes' => ['fa-gear', 'Configurações'],
];
$navBusca = [];
foreach ($navBuscaLabels as $slugNav => $infoNav) {
    if ($slugNav !== 'dashboard' && !adminPodeAcessarAba($slugNav)) continue;
    $navBusca[] = [
        'tipo' => 'Ir para',
        'titulo' => $infoNav[1],
        'subtitulo' => 'Abrir seção do painel',
        'url' => 'admin.php?tab=' . $slugNav,
        'icone' => $infoNav[0],
    ];
}
$adminBuscaGlobal = array_merge($navBusca, $gestaoData['busca']);
?>
window.adminGlobalSearchData = <?= json_encode($adminBuscaGlobal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.adminAlertasIniciais = <?= json_encode($gestaoData['alertas'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.adminAlertasConfig = { endpoint: 'admin_alertas.php', intervaloMs: 90000 };
</script>

<script src="<?= assetUrl('js/admin_ui.js') ?>"></script>
<script src="<?= assetUrl('js/admin_ui_core.js') ?>"></script>
<script src="<?= assetUrl('js/admin_detalhes.js') ?>"></script>
<script src="<?= assetUrl('js/admin_agendamentos.js') ?>"></script>
<script src="<?= assetUrl('js/admin_charts.js') ?>"></script>
<script src="<?= assetUrl('js/admin_gestao.js') ?>"></script>

<!-- Notificações de novos agendamentos (motor compartilhado com o painel do barbeiro) -->
<script>
    window.NA_CONFIG = { isAdmin: true, barbeiroId: '', endpoint: 'check_new_appointments.php', pollMs: 15000 };
</script>
<script src="<?= assetUrl('js/notif_agendamentos.js') ?>"></script>

<script>
document.addEventListener("DOMContentLoaded", function() {

    // --- 3.0. Alternância de tema claro/escuro ---
    (function () {
        const btn = document.getElementById('admin-theme-toggle');
        if (!btn) return;
        const root = document.documentElement;
        function sync() {
            const escuro = root.classList.contains('dark-mode');
            btn.innerHTML = escuro ? '<i class="fa fa-sun"></i>' : '<i class="fa fa-moon"></i>';
            btn.title = escuro ? 'Mudar para tema claro' : 'Mudar para tema escuro';
        }
        sync();
        btn.addEventListener('click', function () {
            const escuro = root.classList.toggle('dark-mode');
            root.classList.toggle('light-mode', !escuro);
            try { localStorage.setItem('admin_theme', escuro ? 'dark' : 'light'); } catch (e) {}
            sync();
        });
    })();

    // --- 3.1. Auto-Ocultar o Alerta Global Superior após 5 segundos ---
    const alertBox = document.getElementById('globalAlert');
    if(alertBox) {
        setTimeout(function() {
            alertBox.style.opacity = '0';
            alertBox.style.transition = 'opacity 0.5s ease';
            setTimeout(() => alertBox.style.display = 'none', 500);
            
            const url = new URL(window.location);
            url.searchParams.delete('success');
            url.searchParams.delete('msg');
            url.searchParams.delete('error');
            window.history.replaceState({}, document.title, url);
        }, 5000);
    }

    // --- 3.2. Lógica de Carregamento (Spinner) ao Enviar Formulários ---
    function bindFormSpinner(root) {
        root.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                if(!this.classList.contains('modern-filter-form') && !this.classList.contains('search-wrapper')) {
                    const btnSubmit = this.querySelector('button[type="submit"]');
                    if (btnSubmit && !btnSubmit.disabled) {
                        const currentWidth = btnSubmit.offsetWidth;
                        btnSubmit.style.width = currentWidth + 'px';
                        btnSubmit.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processando...';
                        btnSubmit.style.opacity = '0.8';
                        btnSubmit.style.pointerEvents = 'none';
                    }
                }
            });
        });
    }

    // --- 3.3. Configuração Inteligente do SweetAlert2 ---
    function getSwalConfig(msg) {
        const msgLower = msg.toLowerCase();
        let icon = 'question';
        let btnClass = 'btn-swal-primary';

        if (msgLower.includes('excluir') || msgLower.includes('cancelar') || msgLower.includes('apagar') || msgLower.includes('irreversível')) {
            icon = 'warning';
            btnClass = 'btn-swal-danger';
        }
        return { icon, btnClass };
    }

    window.confirmActionOverlay = function(msg, btnElement) {
        event.preventDefault();
        const config = getSwalConfig(msg);

        Swal.fire({
            title: 'Você tem certeza?', text: msg, icon: config.icon, showCancelButton: true,
            confirmButtonText: 'Sim, confirmar', cancelButtonText: 'Voltar', reverseButtons: true,
            customClass: { confirmButton: config.btnClass }
        }).then((result) => {
            if (result.isConfirmed) {
                if(typeof showActionOverlay === 'function') showActionOverlay(btnElement);
                window.location.href = btnElement.href;
            }
        });
        return false;
    };

    function bindSwalConfirmLinks(root) {
        root.querySelectorAll('[onclick*="confirm("]').forEach(el => {
            if (el.getAttribute('onclick').includes('confirmActionOverlay')) return;

            const originalCode = el.getAttribute('onclick');
            const match = originalCode.match(/confirm\(\s*['"](.*?)['"]\s*\)/);

            if (match && match[1]) {
                const msg = match[1];
                const config = getSwalConfig(msg);
                el.removeAttribute('onclick');

                el.addEventListener('click', function(e) {
                    e.preventDefault();
                    Swal.fire({
                        title: 'Atenção', text: msg, icon: config.icon, showCancelButton: true,
                        confirmButtonText: 'Sim, continuar', cancelButtonText: 'Cancelar', reverseButtons: true,
                        customClass: { confirmButton: config.btnClass }
                    }).then((result) => {
                        if (result.isConfirmed) {
                            if (el.tagName.toLowerCase() === 'a' && el.href) {
                                if(typeof showActionOverlay === 'function') showActionOverlay(el);
                                window.location.href = el.href;
                            }
                        }
                    });
                });
            }
        });
    }

    function bindSwalConfirmForms(root) {
        root.querySelectorAll('form[onsubmit*="confirm("]').forEach(form => {
            if (form.getAttribute('onsubmit').includes('submeterCampanha')) return;

            const originalCode = form.getAttribute('onsubmit');
            const match = originalCode.match(/confirm\(\s*['"](.*?)['"]\s*\)/);

            if (match && match[1]) {
                const msg = match[1];
                const config = getSwalConfig(msg);
                form.removeAttribute('onsubmit');

                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    Swal.fire({
                        title: 'Atenção', text: msg, icon: config.icon, showCancelButton: true,
                        confirmButtonText: 'Sim, confirmar', cancelButtonText: 'Cancelar', reverseButtons: true,
                        customClass: { confirmButton: config.btnClass }
                    }).then((result) => {
                        if (result.isConfirmed) {
                            form.submit();
                        }
                    });
                });
            }
        });
    }

    bindFormSpinner(document);
    bindSwalConfirmLinks(document);
    bindSwalConfirmForms(document);

    // Reaplica esses comportamentos a uma aba carregada depois, via AJAX.
    window.__reinitAdminTabBehaviors = function(root) {
        bindFormSpinner(root);
        bindSwalConfirmLinks(root);
        bindSwalConfirmForms(root);
    };

});
</script>
</body>
</html>
