<?php
require_once 'functions.php';
iniciarSessaoSegura();
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['cliente_logado'])) { header('Location: login_cliente'); exit; }

// Geração de Token CSRF para segurança dos formulários e do logout
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

require_once 'functions.php';

$configGeral = carregarConfigGeral(); 
$cliente_id = $_SESSION['cliente_id'];

// --- VERIFICAÇÃO IMEDIATA DE CONTA DESATIVADA ---
// Bloqueia o acesso e derruba a sessão na hora se o admin inativou o cliente
try {
    $pdo_status = getDB();
    $stmt_status = $pdo_status->prepare("SELECT status FROM clientes WHERE id = ?");
    $stmt_status->execute([$cliente_id]);
    $cliente_status = $stmt_status->fetchColumn();

    if ($cliente_status === 'inativo') {
        session_destroy();
        // Se for uma requisição via JavaScript (AJAX), devolve a mensagem de erro formatada
        if (isset($_POST['is_ajax']) || isset($_GET['is_ajax'])) {
            echo json_encode(['status' => 'error', 'message' => 'Sua conta foi desativada pelo administrador.']);
            exit;
        }
        // Redireciona expulsando o usuário
        header('Location: login_cliente?error=' . urlencode('Sua conta foi suspensa ou desativada pelo administrador.'));
        exit;
    }
} catch (Exception $e) {}
// ------------------------------------------------

$mensagem = '';
$mensagem_tipo = 'sucesso';

// Carrega a cor de destaque (accent)
$themeConfig = function_exists('_lerConfigSQLite') ? _lerConfigSQLite('theme_config', []) : [];
$app_accent = $themeConfig['secondary_color'] ?? '#f59e0b';
$keys_agendamentos = ['id', 'nome', 'email', 'telefone', 'barbeiro_id', 'servicos_ids', 'data', 'hora', 'status', 'desconto_aplicado', 'tipo_desconto', 'observacoes', 'produtos_vendidos', 'plano_provisorio', 'cliente_id'];
$configStripe = function_exists('_lerConfigSQLite') ? _lerConfigSQLite('config_stripe', ['secret_key' => '', 'portal_url' => '']) : ['portal_url' => ''];
$link_portal_stripe = $configStripe['portal_url'] ?? '';

// 1. Processa Ações do Usuário (POST/GET)
require_once 'cliente_actions.php';

// 2. Busca e Estrutura Dados (DB)
require_once 'cliente_data.php';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/pwa_head.php'; ?>
    <script>
        // Aplica o tema salvo (ou a preferência do sistema) antes da renderização, evitando "flash".
        (function() {
            try {
                var t = localStorage.getItem('cliente_tema');
                if (!t) t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                if (t === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
            } catch (e) {}
        })();
    </script>
    <title>Minha Conta - <?= htmlspecialchars($configGeral['nome_barbearia']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= assetUrl('css/style.css') ?>">
    <link rel="stylesheet" href="css/custom_theme.css.php">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
<?php include __DIR__ . '/partials/cliente_style.php'; ?>
</head>
<body class="admin-page">
<?php
    $pageTitle = 'Minha Conta';
    $backLink = 'index';
    $headerVariant = 'auth';
    require_once 'header_app.php';
?>

<div class="minha-conta-layout">
    
    <aside class="minha-conta-sidebar">
        <div class="profile-card-modern">
            <div class="profile-img-wrapper">
                <img src="<?= htmlspecialchars($foto_perfil) ?>" alt="Foto Perfil" class="profile-img">
                <?php if($assinaturaAtiva && in_array($assinaturaAtiva['status'], ['ativo', 'cancelamento_agendado'], true)): ?>
                    <div class="vip-badge-profile" title="Barbearia por assinatura"><i class="fa fa-crown"></i></div>
                <?php endif; ?>
            </div>
            <div class="profile-info">
                <?php 
                    // Obtém as partes do nome para exibir Nome e Sobrenome (Primeiro e Último)
                    $partes_nome = explode(' ', trim($clienteAtual['nome']));
                    $nome_exibicao = $partes_nome[0];
                    if (count($partes_nome) > 1) {
                        $nome_exibicao .= ' ' . end($partes_nome);
                    }
                ?>
                <h2><?= htmlspecialchars($nome_exibicao) ?></h2>
                <p><i class="fa fa-envelope"></i> <?= htmlspecialchars($clienteAtual['email']) ?></p>
                <p><i class="fa fa-phone"></i> <?= htmlspecialchars($clienteAtual['telefone']) ?></p>
                <div style="margin-top: 12px; display: inline-block; background: #f1f5f9; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 700; color: #64748b;">
                    ID: <span style="color: var(--app-accent);"><?= htmlspecialchars($clienteAtual['id']) ?></span>
                </div>
            </div>
            <div class="profile-actions">
                <button type="button" class="theme-toggle" id="btn-toggle-tema" aria-label="Alternar tema claro/escuro">
                    <i class="fa fa-moon"></i><i class="fa fa-sun"></i>
                    <span class="theme-toggle-label">Modo escuro</span>
                </button>
                <a href="logout_cliente?token=<?= htmlspecialchars($csrf_token) ?>" class="btn btn-logout-sidebar" id="btn-logout-cliente"><i class="fa fa-sign-out-alt"></i> Sair</a>
            </div>
        </div>

        <nav class="nav-tabs-vertical nav-tabs" aria-label="Seções da minha conta">
            <button type="button" class="nav-tab active" data-tab="historico" aria-controls="historico" aria-selected="true"><i class="fa fa-calendar-alt"></i> Meu Histórico</button>
            <?php if($config_fidelidade['ativado']): ?>
                <button type="button" class="nav-tab" data-tab="fidelidade" aria-controls="fidelidade" aria-selected="false"><i class="fa fa-star"></i> Clube Fidelidade</button>
            <?php endif; ?>
            <button type="button" class="nav-tab" data-tab="dados" aria-controls="dados" aria-selected="false"><i class="fa fa-user-edit"></i> Dados Pessoais</button>
            <button type="button" class="nav-tab" data-tab="avaliacoes" aria-controls="avaliacoes" aria-selected="false"><i class="fa fa-comment-dots"></i> Minhas Avaliações</button>
            <?php if($config_indicacao['ativado']): ?>
                <button type="button" class="nav-tab" data-tab="indicacao" aria-controls="indicacao" aria-selected="false"><i class="fa fa-gift"></i> Indique e Ganhe</button>
            <?php endif; ?>
        </nav>
    </aside>

    <main class="minha-conta-main">
        
        <div id="alerta-container" style="<?= $mensagem ? 'display: block;' : 'display: none;' ?> border-radius: 16px; padding: 18px 25px; font-weight: 600; box-shadow: 0 4px 15px rgba(0,0,0,0.05); <?= $mensagem_tipo === 'erro' ? 'background-color: #fef2f2; border: 1px solid #fecaca; color: #b91c1c;' : 'background-color: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d;' ?> margin-bottom: 20px;">
            <i id="alerta-icone" class="fa <?= $mensagem_tipo === 'erro' ? 'fa-exclamation-circle' : 'fa-check-circle' ?>" style="font-size: 1.2rem; margin-right: 8px;"></i> 
            <span id="alerta-texto"><?= htmlspecialchars($mensagem) ?></span>
        </div>

        <div class="account-command-center">
            <div class="main-header-greeting">
                <span class="account-eyebrow"><i class="fa fa-gem"></i> Experiência premium</span>
                <h1>Olá, <?= htmlspecialchars(explode(' ', trim($clienteAtual['nome']))[0]) ?>.</h1>
                <p>Seu painel pessoal para acompanhar horários, benefícios, histórico e preferências em um só lugar.</p>
            </div>
            <div class="account-quick-actions">
                <a href="agendamento" class="quick-action primary"><i class="fa fa-calendar-plus"></i> Agendar</a>
                <button type="button" class="quick-action" data-modal-target="#modal-notificacoes" data-notification-trigger="true">
                    <i class="fa fa-bell"></i> Avisos
                    <?php if($notificacoes_nao_lidas > 0): ?>
                    <span class="notification-count" id="badge-notificacoes"><?= $notificacoes_nao_lidas ?></span>
                    <?php endif; ?>
                </button>
            </div>
        </div>

        <?php if ($e_aniversariante): ?>
            <div class="aniversario-banner" style="background: linear-gradient(135deg, var(--app-accent-soft), #fff); border:1px solid var(--app-accent-border); color:var(--app-accent-strong); padding:20px 25px; border-radius: 20px; font-weight: 600; box-shadow: 0 10px 25px -18px color-mix(in srgb, var(--app-accent), transparent 45%); display: flex; align-items: center; gap: 15px;">
                <div style="background: white; width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 4px 10px rgba(0,0,0,0.05);">
                    <i class="fa fa-birthday-cake" style="font-size:1.5rem; color: var(--app-accent);"></i>
                </div>
                <div>
                    <h4 style="margin: 0 0 5px; color: var(--app-accent-strong); font-size: 1.1rem; font-weight: 800;">Feliz Aniversário! 🎉</h4>
                    <p style="margin: 0; font-size: 0.95rem;">No mês do seu aniversário, você ganha <strong><?= $config_aniversario['desconto_percentual'] ?>% de desconto</strong> no seu primeiro agendamento!</p>
                </div>
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon visits"><i class="fa fa-calendar-check"></i></div>
                <div class="stat-info-wrapper">
                    <div class="stat-label">Visitas Concluídas</div>
                    <div class="stat-value"><?= $totalVisitas ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon invested"><i class="fa fa-wallet"></i></div>
                <div class="stat-info-wrapper">
                    <div class="stat-label">Total Investido</div>
                    <div class="stat-value">R$ <?= number_format($gastoTotal, 2, ',', '.') ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon saved"><i class="fa fa-piggy-bank"></i></div>
                <div class="stat-info-wrapper">
                    <div class="stat-label">Economizado</div>
                    <div class="stat-value">R$ <?= number_format($economiaTotal, 2, ',', '.') ?></div>
                </div>
            </div>
            <?php if(($config_fidelidade['ativado'] ?? 0)): ?>
            <div class="stat-card">
                <div class="stat-icon points"><i class="fa fa-star"></i></div>
                <div class="stat-info-wrapper">
                    <div class="stat-label">Pontos Fidelidade</div>
                    <div class="stat-value"><?= $pontos_fidelidade ?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php if($assinaturaAtiva && in_array($assinaturaAtiva['status'], ['ativo', 'cancelamento_agendado'], true)):
            $planoVip = $planosArr[$assinaturaAtiva['plano_id']] ?? null;
            $diasVip = max(0, ceil((strtotime($assinaturaAtiva['data_fim']) - time()) / 86400));
            $gatewayAssinatura = $assinaturaAtiva['gateway'] ?? 'manual';
            $cancelamentoAgendado = $assinaturaAtiva['status'] === 'cancelamento_agendado';
            // Serviços inclusos no plano
            $servicosPlano = [];
            if ($planoVip && !empty($planoVip['servicos_ids'])) {
                foreach (explode(',', $planoVip['servicos_ids']) as $sidP) {
                    $sidP = trim($sidP);
                    if (isset($servicosArr[$sidP])) $servicosPlano[] = $servicosArr[$sidP]['nome'];
                    elseif (isset($combosArr[$sidP])) $servicosPlano[] = $combosArr[$sidP]['nome'];
                }
            }
        ?>
            <div class="vip-subscription-card">
                <div class="vip-card-content">
                    <div style="min-width:0;">
                        <div class="vip-title"><i class="fa fa-crown"></i> Barbearia por assinatura</div>
                        <div class="vip-detail">Plano: <strong><?= htmlspecialchars($planoVip['nome'] ?? 'Plano Personalizado') ?></strong></div>
                        <span class="subscription-provider">
                            <i class="fa <?= $gatewayAssinatura === 'stripe' ? 'fa-credit-card' : 'fa-store' ?>"></i>
                            <?= $gatewayAssinatura === 'stripe' ? 'Stripe' : 'Assinatura manual' ?>
                        </span>

                        <div class="subscription-grid">
                            <?php if(!empty($assinaturaAtiva['data_inicio'])): ?>
                            <div class="subscription-fact">
                                <span class="sf-label"><i class="fa fa-play-circle"></i> Início</span>
                                <span class="sf-value"><?= date('d/m/Y', strtotime($assinaturaAtiva['data_inicio'])) ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="subscription-fact">
                                <span class="sf-label"><i class="fa fa-rotate"></i> <?= $cancelamentoAgendado ? 'Encerra em' : 'Renova em' ?></span>
                                <span class="sf-value"><?= date('d/m/Y', strtotime($assinaturaAtiva['data_fim'])) ?></span>
                            </div>
                            <?php if($planoVip && isset($planoVip['valor']) && (float)$planoVip['valor'] > 0): ?>
                            <div class="subscription-fact">
                                <span class="sf-label"><i class="fa fa-tag"></i> Valor</span>
                                <span class="sf-value">R$ <?= number_format((float)$planoVip['valor'], 2, ',', '.') ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="subscription-fact">
                                <span class="sf-label"><i class="fa fa-circle-check"></i> Situação</span>
                                <span class="sf-value"><?= $cancelamentoAgendado ? 'Cancelamento agendado' : 'Ativa' ?></span>
                            </div>
                        </div>

                        <?php if(!empty($servicosPlano)): ?>
                        <div class="subscription-included">
                            <span class="sf-label" style="display:block; margin-bottom:8px;"><i class="fa fa-scissors"></i> Serviços inclusos</span>
                            <div style="display:flex; flex-wrap:wrap; gap:8px;">
                                <?php foreach($servicosPlano as $svcNome): ?>
                                    <span class="subscription-tag"><?= htmlspecialchars($svcNome) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($cancelamentoAgendado): ?>
                            <div class="subscription-ending">
                                <i class="fa fa-info-circle"></i>
                                <span>Cancelamento concluído. Não haverá novas cobranças e seus benefícios permanecem ativos até <?= date('d/m/Y', strtotime($assinaturaAtiva['data_fim'])) ?>.</span>
                            </div>
                        <?php elseif (in_array($gatewayAssinatura, ['stripe', 'manual'], true) && !empty($link_portal_stripe)): ?>
                        <div class="subscription-actions">
                            <a href="<?= htmlspecialchars($link_portal_stripe) ?>?prefilled_email=<?= urlencode($clienteAtual['email']) ?>" target="_blank" rel="noopener noreferrer" class="subscription-manage-btn">
                                <i class="fa fa-external-link-alt"></i> Gerenciar Assinatura
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="vip-days-left">
                        <div class="days-number"><?= $diasVip ?></div>
                        <div class="days-label">Dias Restantes</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($proximoAgendamento): ?>
        <div class="next-appointment-hero">
            <div class="hero-header">
                <span style="font-weight:800; font-size: 1.1rem; color: var(--text-main);"><i class="fa fa-clock" style="color: var(--app-accent);"></i> Próximo Horário</span>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 0.9rem; font-weight: 600; color: var(--text-muted);">Inicia em:</span>
                    <div class="countdown-timer" id="countdown" data-datetime="<?= htmlspecialchars($proximoAgendamento['data'] . ' ' . $proximoAgendamento['hora']) ?>">--h --m</div>
                </div>
            </div>
            <div class="hero-body">
                <div class="hero-content-wrapper">
                    <img src="<?= htmlspecialchars($proximoAgendamentoDetalhes['barbeiro_foto']) ?>" alt="Barbeiro" class="barber-hero-img">
                    <div class="hero-info">
                        <h3><?= htmlspecialchars($barbeirosArr[$proximoAgendamento['barbeiro_id']]['nome'] ?? 'Barbeiro Indisponível') ?></h3>
                        <div class="hero-services">
                            <i class="fa fa-cut" style="color: var(--app-accent);"></i> <?= htmlspecialchars(implode(', ', $proximoAgendamentoDetalhes['servicos_nomes'])) ?>
                        </div>
                        <div style="margin-top: 10px; font-weight: 700; color: var(--text-main); font-size: 1.1rem;">
                            <i class="fa fa-calendar-day" style="color: #94a3b8;"></i> <?= date('d/m/Y', strtotime($proximoAgendamento['data'])) ?> às <?= $proximoAgendamento['hora'] ?>
                        </div>
                    </div>
                    <div class="hero-actions">
                        <button class="btn btn-detalhes" style="background: white; border: 1px solid #e2e8f0; color: var(--text-main); box-shadow: 0 4px 6px rgba(0,0,0,0.02);" data-modal-target="#modal-detalhes-agendamento" data-agendamento-id="<?= $proximoAgendamento['id'] ?>"><i class="fa fa-eye"></i> Detalhes</button>
                        <button class="btn btn-reagendar" style="background: var(--text-main); color: white; border: none; box-shadow: 0 4px 10px rgba(15, 23, 42, 0.2);" data-modal-target="#modal-reagendamento" data-agendamento-id="<?= $proximoAgendamento['id'] ?>" data-barbeiro-id="<?= $proximoAgendamento['barbeiro_id'] ?>" data-servicos-ids="<?= $proximoAgendamento['servicos_ids'] ?>"><i class="fa fa-calendar-plus"></i> Reagendar</button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php
            // --- PRÓXIMOS AGENDAMENTOS (além do destaque acima) ---
            $outrosAtivos = array_slice($agendamentosAtivos, 1);
            if (!empty($outrosAtivos)):
        ?>
        <section class="proximos-agendamentos">
            <h3 class="tabcontent-title" style="border:none; padding:0; margin:0 0 4px;">
                <i class="fa fa-calendar-alt" style="color: var(--app-accent);"></i>
                Próximos agendamentos
                <span class="cliente-chip" style="margin-left:6px; background: var(--surface-3); color: var(--text-muted); font-size:0.85rem; padding:3px 10px; border-radius:999px; font-weight:800;"><?= count($outrosAtivos) ?></span>
            </h3>
            <p style="color: var(--text-muted); margin:0 0 6px; font-weight:500; font-size:0.95rem;">Você tem mais horários confirmados. Toque para ver detalhes, reagendar ou cancelar.</p>
            <?php foreach ($outrosAtivos as $ag):
                $servicosNomesProx = [];
                foreach (explode(',', $ag['servicos_ids']) as $sid) {
                    $sid = trim($sid);
                    if (isset($servicosArr[$sid])) $servicosNomesProx[] = $servicosArr[$sid]['nome'];
                    elseif (isset($combosArr[$sid])) $servicosNomesProx[] = $combosArr[$sid]['nome'].' (Combo)';
                }
                $statusClassProx = 'status-'.($ag['status'] === 'cancelado_pelo_cliente' ? 'cancelado' : $ag['status']);
                $barbeiroNomeProx = $barbeirosArr[$ag['barbeiro_id']]['nome'] ?? 'Barbeiro';
            ?>
            <div class="appt-card">
                <div class="appt-date">
                    <span class="appt-day"><?= date('d', strtotime($ag['data'])) ?></span>
                    <span class="appt-month"><?= mesAbrevPt((int)date('n', strtotime($ag['data']))) ?></span>
                    <div class="appt-year"><?= date('Y', strtotime($ag['data'])) ?></div>
                </div>
                <div class="appt-details">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px; flex-wrap:wrap; gap:10px;">
                        <strong style="font-size:1.15rem; color:var(--text-main); font-weight:800;"><?= htmlspecialchars(implode(', ', $servicosNomesProx)) ?></strong>
                        <span class="appt-status <?= $statusClassProx ?>"><?= $ag['status'] === 'aprovado' ? 'Confirmado' : ucfirst($ag['status']) ?></span>
                    </div>
                    <div style="font-size:1rem; color:var(--text-muted); font-weight:500; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <span><i class="fa fa-clock" style="color: var(--text-faint);"></i> <?= date('d/m/Y', strtotime($ag['data'])) ?> às <?= $ag['hora'] ?></span>
                        <span style="display:inline-flex; align-items:center; gap:6px;">&bull;
                            <img src="<?= htmlspecialchars($barbeirosArr[$ag['barbeiro_id']]['foto'] ?? 'uploads/default-profile.jpg') ?>" style="width:24px; height:24px; border-radius:50%; object-fit:cover;">
                            <strong><?= htmlspecialchars($barbeiroNomeProx) ?></strong>
                        </span>
                    </div>
                </div>
                <div class="appt-actions-container">
                    <button class="btn btn-detalhes" style="padding:10px 18px; border-radius:12px; background: var(--card-bg); color: var(--text-main); border:1px solid var(--border-strong); font-weight:600;" data-modal-target="#modal-detalhes-agendamento" data-agendamento-id="<?= $ag['id'] ?>"><i class="fa fa-eye"></i> Detalhes</button>
                    <button class="btn btn-reagendar" style="padding:10px 18px; border-radius:12px; background: var(--text-main); color: var(--card-bg); border:none; font-weight:700;" data-modal-target="#modal-reagendamento" data-agendamento-id="<?= $ag['id'] ?>" data-barbeiro-id="<?= $ag['barbeiro_id'] ?>" data-servicos-ids="<?= htmlspecialchars($ag['servicos_ids']) ?>"><i class="fa fa-calendar-plus"></i> Reagendar</button>
                    <a href="cliente.php?action=cancelar&id=<?= $ag['id'] ?>&csrf_token=<?= htmlspecialchars($csrf_token) ?>" class="btn" style="padding:10px 18px; border-radius:12px; background: var(--card-bg); color:#ef4444; border:1px solid #fca5a5; font-weight:700; text-align:center; text-decoration:none;" onclick="return confirm('Tem certeza que deseja cancelar este agendamento?');"><i class="fa fa-times-circle"></i> Cancelar</a>
                </div>
            </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <?php include 'cliente_tabs/historico.php'; ?>
        <?php if($config_fidelidade['ativado']) include 'cliente_tabs/fidelidade.php'; ?>
        <?php include 'cliente_tabs/dados.php'; ?>
        <?php include 'cliente_tabs/avaliacoes.php'; ?>
        <?php if($config_indicacao['ativado']) include 'cliente_tabs/indicacao.php'; ?>

    </main>
</div>

<?php require_once 'cliente_modals.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://npmcdn.com/flatpickr/dist/l10n/pt.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
<?php include __DIR__ . '/partials/cliente_script.php'; ?>
<script src="<?= assetUrl('js/cliente.js') ?>"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(e) {
            if(!this.classList.contains('modern-filter-form') && !this.classList.contains('search-wrapper')) {
                const btnSubmit = this.querySelector('button[type="submit"]');
                if (btnSubmit && !btnSubmit.disabled) {
                    const currentWidth = btnSubmit.offsetWidth;
                    btnSubmit.style.width = currentWidth + 'px';
                    
                    btnSubmit.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processando...';
                    btnSubmit.style.opacity = '0.8';
                    btnSubmit.style.pointerEvents = 'none';
                    btnSubmit.disabled = true;
                }
            }
        });
    });
});
</script>
<?php include 'chatbot_widget.php'; ?>
</body>
</html>
