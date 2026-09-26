<?php
require_once 'functions.php';
iniciarSessaoSegura();

// Realinha nome/e-mail/telefone da sessao com o banco (busca pelo ID, que nao
// muda). Sem isto, o formulario e preenchido com o que valia no momento do
// login -- e esses campos viram os dados do novo agendamento.
if (function_exists('clienteDaSessao')) { clienteDaSessao(); }
// Importa toda a lógica de preparação de dados do agendamento
require_once 'agendamento_data.php';

$mensagem = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_favorite_barber') {
    header('Content-Type: application/json');

    if (!$cliente_logado) {
        echo json_encode(['success' => false, 'message' => 'Entre na sua conta para favoritar barbeiros.']);
        exit;
    }

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Sessao expirada. Atualize a pagina.']);
        exit;
    }

    $barbeiro_favorito_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['barbeiro_id'] ?? '');
    if ($barbeiro_favorito_id === '' || !isset($barbeirosAtivosArr[$barbeiro_favorito_id])) {
        echo json_encode(['success' => false, 'message' => 'Barbeiro invalido.']);
        exit;
    }

    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS barbeiros_favoritos (cliente_id TEXT NOT NULL, barbeiro_id TEXT NOT NULL, created_at TEXT, PRIMARY KEY (cliente_id, barbeiro_id))");
        $stmt = $pdo->prepare("SELECT 1 FROM barbeiros_favoritos WHERE cliente_id = ? AND barbeiro_id = ? LIMIT 1");
        $stmt->execute([$_SESSION['cliente_id'], $barbeiro_favorito_id]);
        $ja_favorito = $stmt->fetchColumn();

        if ($ja_favorito) {
            $stmt = $pdo->prepare("DELETE FROM barbeiros_favoritos WHERE cliente_id = ? AND barbeiro_id = ?");
            $stmt->execute([$_SESSION['cliente_id'], $barbeiro_favorito_id]);
            echo json_encode(['success' => true, 'favorite' => false, 'message' => 'Barbeiro removido dos favoritos.']);
        } else {
            $stmt = $pdo->prepare("INSERT INTO barbeiros_favoritos (cliente_id, barbeiro_id, created_at) VALUES (?, ?, ?)");
            $stmt->execute([$_SESSION['cliente_id'], $barbeiro_favorito_id, date('Y-m-d H:i:s')]);
            echo json_encode(['success' => true, 'favorite' => true, 'message' => 'Barbeiro adicionado aos favoritos.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Nao foi possivel atualizar o favorito.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'validate_discount_code') {
    header('Content-Type: application/json');

    if (!$cliente_logado) {
        echo json_encode(['success' => false, 'requires_login' => true, 'message' => 'Entre na sua conta para validar cupons e vouchers.']);
        exit;
    }

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Sessao expirada. Atualize a pagina.']);
        exit;
    }

    $codigo_desconto = trim(strtoupper($_POST['codigo'] ?? ''));
    $valor_base = max(0, (float)($_POST['valor_total'] ?? 0));

    if ($codigo_desconto === '') {
        echo json_encode(['success' => false, 'message' => 'Informe um codigo para validar.']);
        exit;
    }

    $cupom = getCouponByCode($codigo_desconto);
    $voucher = getVoucherByCode($codigo_desconto);

    if ($cupom && !isset($cupom['error'])) {
        if (checkIfUserUsedCoupon($cupom['id'], $_SESSION['cliente_id'])) {
            echo json_encode(['success' => false, 'message' => 'Voce ja utilizou este cupom.']);
            exit;
        }

        $percentual = (float)$cupom['desconto_percentual'];
        $desconto = $valor_base * ($percentual / 100);
        echo json_encode([
            'success' => true,
            'type' => 'cupom',
            'label' => "Cupom {$percentual}% OFF",
            'discount' => round($desconto, 2),
            'message' => 'Cupom valido. O desconto sera aplicado ao confirmar.'
        ]);
        exit;
    }

    if ($voucher && !isset($voucher['error'])) {
        $desconto = min($valor_base, (float)$voucher['valor']);
        echo json_encode([
            'success' => true,
            'type' => 'voucher',
            'label' => 'Voucher de presente',
            'discount' => round($desconto, 2),
            'message' => 'Voucher valido. O valor sera abatido ao confirmar.'
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => $cupom['error'] ?? ($voucher['error'] ?? 'Cupom ou voucher invalido.')]);
    exit;
}

$favoritosBarbeirosIds = [];
if ($cliente_logado) {
    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS barbeiros_favoritos (cliente_id TEXT NOT NULL, barbeiro_id TEXT NOT NULL, created_at TEXT, PRIMARY KEY (cliente_id, barbeiro_id))");
        $stmtFavoritos = $pdo->prepare("SELECT barbeiro_id FROM barbeiros_favoritos WHERE cliente_id = ?");
        $stmtFavoritos->execute([$_SESSION['cliente_id']]);
        $favoritosBarbeirosIds = $stmtFavoritos->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Exception $e) {
        $favoritosBarbeirosIds = [];
    }
}

// ==========================================
// PROCESSAMENTO DO FORMULÁRIO (MÓDULO SEPARADO)
// ==========================================
if ($cliente_logado && $_SERVER['REQUEST_METHOD'] == 'POST' && empty($_POST['action'])) {
    // Segurança: valida o token CSRF antes de processar o agendamento.
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $mensagem = "Sessão expirada ou token de segurança inválido. Recarregue a página e tente novamente.";
    } else {
        require_once 'processar_agendamento.php';
    }
}
// ==========================================

$mensagem_tipo_erro = false;

if (isset($_GET['success']) && $_GET['success'] == 1) {
    if (isset($_SESSION['mensagem_sucesso'])) {
        $mensagem = $_SESSION['mensagem_sucesso'];
        unset($_SESSION['mensagem_sucesso']);
    } else {
        $mensagem = "O seu agendamento foi confirmado! Acompanhe em 'A Minha Conta'.";
    }
} elseif (isset($_GET['erro_stripe'])) {
    // O checkout da assinatura foi cancelado ou não pôde ser iniciado.
    // A assinatura é 100% online: aqui apenas avisamos e convidamos a repetir.
    $mensagem_tipo_erro = true;
    if (isset($_SESSION['mensagem_erro'])) {
        $mensagem = $_SESSION['mensagem_erro'];
        unset($_SESSION['mensagem_erro']);
    } else {
        $mensagem = "O pagamento da assinatura foi cancelado. Nenhuma cobrança foi feita. "
            . "Você pode tentar novamente quando quiser.";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php include __DIR__ . '/pwa_head.php'; ?>
    <title>Agendamento Barbearia</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="<?= assetUrl('css/style.css') ?>">
    <link rel="stylesheet" href="css/custom_theme.css.php">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="<?= assetUrl('css/agendamento.css') ?>">
    
    <link rel="stylesheet" href="<?= assetUrl('css/agendamento_wizard.css') ?>">
    
    <style>
        :root {
            --app-accent: <?= htmlspecialchars($app_accent) ?>;
        }
        .plano-det-chip { display: inline-flex; align-items: center; gap: 6px; background: #f1f5f9; color: #334155; border-radius: 999px; padding: 5px 11px; font-size: 0.72rem; font-weight: 600; }
        .plano-det-chip i { color: #10b981; font-size: 0.72rem; }
        #plano-det-servicos li { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: #1e293b; }
        #plano-det-servicos li i { color: #10b981; font-size: 0.8rem; }
        #plano-det-servicos li .plano-det-preco { margin-left: auto; font-size: 0.72rem; color: #94a3b8; text-decoration: line-through; }
    </style>
</head>
<body>
    <?php 
    $pageTitle = 'Agendamento';
    $headerVariant = 'auth';
    require_once 'header_app.php';
    ?>

    <div id="wizard-loading" class="loading-overlay">
        <div class="skeleton-wrapper">
            <div class="skeleton skeleton-title"></div>
            <div class="skeleton skeleton-text"></div>
            <div class="skeleton skeleton-text short"></div>
            <div class="skeleton skeleton-box" style="margin-top: 25px;"></div>
            <div class="skeleton skeleton-box"></div>
            <div class="skeleton skeleton-btn"></div>
        </div>
        <div class="loading-text" id="loading-msg">Preparando o próximo passo...</div>
    </div>

    <div class="booking-layout">
        <main class="booking-main">
            
            <div class="booking-actions-top">
                <a href="agendamento" class="btn-refresh"><i class="fa fa-sync-alt"></i> Recomeçar</a>
                <?php if ($cliente_logado): ?>
                    <a href="cliente" class="btn-conta"><i class="fa fa-user-circle"></i> Minha Conta</a>
                    <a href="logout_cliente" class="btn-logout"><i class="fa fa-sign-out-alt"></i> Sair</a>
                <?php else: ?>
                    <a href="login_cliente?redirect=agendamento" class="btn-conta"><i class="fa fa-sign-in-alt"></i> Fazer Login</a>
                <?php endif; ?>
            </div>

            <section class="booking-hero" id="booking-hero" aria-label="Resumo do fluxo de agendamento">
                <div>
                    <span class="booking-kicker">Agendamento online</span>
                    <h1>Reserve seu horario em poucos passos</h1>
                    <p>Escolha profissional, servicos, data e confirme tudo com uma experiencia simples e segura.</p>
                </div>
                <div class="booking-hero-meta" aria-hidden="true">
                    <span><i class="fa fa-shield-alt"></i> Seguro</span>
                    <span><i class="fa fa-bolt"></i> Rapido</span>
                    <span><i class="fa fa-calendar-check"></i> Confirmado</span>
                </div>
            </section>
            
            <?php if ($mensagem):
                $isErro = ($mensagem_tipo_erro ?? false) || (strpos(strtolower($mensagem), 'erro') !== false || strpos(strtolower($mensagem), 'preencha') !== false);
                $alertClass = $isErro ? 'error' : 'success';
                $alertIcon = $isErro ? 'fa-exclamation-triangle' : 'fa-check-circle';
            ?>
                <div class="modern-alert <?= $alertClass ?>" id="main-alert">
                    <div class="modern-alert-icon">
                        <i class="fa <?= $alertIcon ?>"></i>
                    </div>
                    <div class="modern-alert-content" style="width: 100%;">
                        <h4 class="modern-alert-title"><?= $alertTitle ?? '' ?></h4>
                        <p class="modern-alert-text"><?= htmlspecialchars($mensagem) ?></p>
                        
                        <?php if (!$isErro && $cliente_logado): ?>
                            <div class="modern-alert-cta-wrapper">
                                <a href="cliente" class="btn-alert-cta">
                                    <i class="fa fa-user-circle"></i> Ir para Minha Conta
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="modern-alert-close" onclick="document.getElementById('main-alert').style.display='none'" style="align-self: flex-start;">
                        <i class="fa fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>

            <?php if (!$cliente_logado): ?>
            <div class="login-prompt">
                <i class="fa fa-lock" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 15px;"></i>
                <p>Para agendar um horário e garantir a sua vaga, é necessário entrar na sua conta.</p>
                <div>
                    <a href="login_cliente?redirect=agendamento">Fazer Login</a>
                    <a href="registro">Criar Nova Conta</a>
                </div>
            </div>
            <?php endif; ?>

            <div id="wizard-error-container" class="wizard-error-alert">
                <i class="fa fa-exclamation-circle"></i>
                <span id="wizard-error-text"></span>
            </div>

            <form method="POST" id="form-agendamento" class="<?= $form_disabled_class ?>">
                 
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <div class="step-indicator">
                    <div class="step-dot active" id="dot-1" title="Seus Dados" data-label="Dados"><i class="fa fa-user"></i></div>
                    <div class="step-line" id="line-1"></div>
                    <div class="step-dot" id="dot-2" title="Profissional" data-label="Profissional"><i class="fa fa-user-tie"></i></div>
                    <div class="step-line" id="line-2"></div>
                    <div class="step-dot" id="dot-3" title="Serviços" data-label="Servicos"><i class="fa fa-cut"></i></div>
                    <div class="step-line" id="line-3"></div>
                    <div class="step-dot" id="dot-4" title="Data e Hora" data-label="Agenda"><i class="fa fa-calendar-alt"></i></div>
                    <div class="step-line" id="line-4"></div>
                    <div class="step-dot" id="dot-5" title="Finalizar" data-label="Confirmar"><i class="fa fa-check"></i></div>
                </div>

                <div class="wizard-step active" id="step-1">
                    <?php 
                    if ($cliente_logado && $ultimo_agendamento && isset($barbeirosAtivosArr[$ultimo_agendamento['barbeiro_id']])): 
                        $nome_barbeiro_ultimo = $barbeirosAtivosArr[$ultimo_agendamento['barbeiro_id']]['nome'];
                        
                        $foto_b_ultimo = trim($barbeirosAtivosArr[$ultimo_agendamento['barbeiro_id']]['foto'] ?? '');
                        $foto_barbeiro_ultimo = (!empty($foto_b_ultimo) && file_exists($foto_b_ultimo)) ? $foto_b_ultimo : 'uploads/default-profile.jpg';
                        
                        $servicos_ultimo_arr = explode(',', $ultimo_agendamento['servicos_ids']);
                        $nomes_servicos_ultimo = [];
                        $ids_servicos_puros = [];
                        $ids_combos_puros = [];
                        $valor_total_ultimo = 0;
                        $slots_total_ultimo = 0;
                        $todos_servicos_disponiveis = true;

                        foreach($servicos_ultimo_arr as $sid) {
                            $sid = trim($sid);
                            if (isset($servicosArr[$sid])) {
                                $nomes_servicos_ultimo[] = $servicosArr[$sid]['nome'];
                                $ids_servicos_puros[] = $sid;
                                $valor_total_ultimo += (float)$servicosArr[$sid]['valor'];
                                $slots_total_ultimo += max(1, (int)($servicosArr[$sid]['slots'] ?? 1));
                            } elseif (isset($combosArr[$sid])) {
                                $nomes_servicos_ultimo[] = $combosArr[$sid]['nome'];
                                $ids_combos_puros[] = $sid;
                                $valor_total_ultimo += (float)$combosArr[$sid]['valor'];
                                $slots_total_ultimo += max(1, (int)($combosArr[$sid]['slots'] ?? 1));
                            } else {
                                $todos_servicos_disponiveis = false;
                            }
                        }

                        if (!empty($nomes_servicos_ultimo) && $todos_servicos_disponiveis):
                            $duracao_minutos = $slots_total_ultimo * 30;
                    ?>
                    <div class="repetir-ultimo-card">
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <img src="<?= htmlspecialchars($foto_barbeiro_ultimo) ?>" alt="<?= htmlspecialchars($nome_barbeiro_ultimo) ?>" style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover; border: 2px solid white;">
                            <div>
                                <h3 style="margin: 0 0 5px; font-size: 1.25rem; display: flex; align-items: center; gap: 8px; color: white;"><i class="fa fa-history"></i> Repetir Último Serviço?</h3>
                                <p style="margin: 0; font-size: 0.95rem; opacity: 0.95;">Com <strong><?= htmlspecialchars($nome_barbeiro_ultimo) ?></strong>: <?= htmlspecialchars(implode(', ', $nomes_servicos_ultimo)) ?></p>
                                <div style="margin-top: 5px; font-size: 0.85rem; background: rgba(0,0,0,0.2); display: inline-block; padding: 4px 10px; border-radius: 20px;">
                                    <i class="fa fa-wallet"></i> R$ <?= number_format($valor_total_ultimo, 2, ',', '.') ?> &nbsp;&bull;&nbsp; <i class="fa fa-clock"></i> <?= $duracao_minutos ?> min
                                </div>
                            </div>
                        </div>
                        <button type="button" id="btn-repetir-ultimo" 
                                data-barbeiro="<?= htmlspecialchars($ultimo_agendamento['barbeiro_id']) ?>" 
                                data-servicos="<?= htmlspecialchars(implode(',', $ids_servicos_puros)) ?>"
                                data-combos="<?= htmlspecialchars(implode(',', $ids_combos_puros)) ?>"
                                style="background: white; color: #d97706; border: none; padding: 12px 20px; border-radius: 10px; font-weight: 700; font-size: 0.95rem; cursor: pointer; transition: 0.3s; box-shadow: 0 4px 6px rgba(0,0,0,0.1); display: inline-flex; align-items: center; gap: 8px;">
                            <i class="fa fa-bolt"></i> Sim, quero este!
                        </button>
                    </div>
                    <?php endif; endif; ?>

                    <div class="booking-step-card">
                        <h3 class="step-title"><i class="fa fa-id-card"></i> 1. Seus Dados</h3>
                        <p class="step-helper">Usamos estes dados para identificar sua reserva e enviar os detalhes do atendimento.</p>
                        
                        <?php if ($cliente_logado): ?>
                            <div class="client-summary-card">
                                <div class="client-avatar">
                                    <?= strtoupper(substr($_SESSION['cliente_nome'] ?? 'C', 0, 1)) ?>
                                </div>
                                <div class="client-summary-info">
                                    <h4><?= htmlspecialchars($_SESSION['cliente_nome'] ?? '') ?></h4>
                                    <p>
                                        <i class="fa fa-phone" style="width: 16px;"></i> <?= htmlspecialchars($_SESSION['cliente_telefone'] ?? '') ?> <br> 
                                        <i class="fa fa-envelope" style="width: 16px;"></i> <?= htmlspecialchars($_SESSION['cliente_email'] ?? '') ?>
                                    </p>
                                </div>
                                <div class="client-summary-status">
                                    <i class="fa fa-check-circle" style="color: #10b981; font-size: 2.2rem;"></i>
                                </div>
                            </div>
                            <input type="hidden" name="nome" id="nome" value="<?= htmlspecialchars($_SESSION['cliente_nome'] ?? '') ?>">
                            <input type="hidden" name="telefone" id="telefone" value="<?= htmlspecialchars($_SESSION['cliente_telefone'] ?? '') ?>">
                            <input type="hidden" name="email" id="email" value="<?= htmlspecialchars($_SESSION['cliente_email'] ?? '') ?>">
                        <?php else: ?>
                            <div class="guest-locked-notice">
                                <i class="fa fa-lock"></i>
                                <p>Seus dados aparecerão aqui assim que você entrar na sua conta. Use os botões de login acima para continuar.</p>
                            </div>
                            <input type="hidden" name="nome" id="nome" value="">
                            <input type="hidden" name="telefone" id="telefone" value="">
                            <input type="hidden" name="email" id="email" value="">
                        <?php endif; ?>

                    </div>
                    <div class="wizard-buttons" style="justify-content: flex-end;">
                        <button type="button" class="btn-wizard btn-next" onclick="nextStep(1)">Escolher Profissional <i class="fa fa-arrow-right"></i></button>
                    </div>
                </div>

                <div class="wizard-step" id="step-2">
                    <div class="booking-step-card">
                        <h3 class="step-title"><i class="fa fa-user-tie"></i> 2. Escolha o Profissional</h3>
                        <p class="step-helper">Selecione um barbeiro especifico ou deixe que o sistema encontre o proximo profissional livre.</p>
                        
                        <div class="form-group modern-group" style="margin-bottom: 0;">
                            <label><i class="fa fa-scissors"></i> Quem irá te atender?</label>
                            
                            <div class="custom-select-selected" style="display: none;"></div>
                            
                            <div class="barber-grid" id="barber-selection-grid">
                                <!-- Card: Qualquer Profissional -->
                                <div class="barber-card-selectable custom-option" data-value="qualquer" onclick="selectBarberCard('qualquer', this, event)">
                                    <div style="width: 100px; height: 100px; border-radius: 50%; background: #f1f5f9; border: 3px solid #e2e8f0; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 12px; font-size: 2.5rem; color: #94a3b8;">
                                        <i class="fa fa-users"></i>
                                    </div>
                                    <h4 class="barber-name">Qualquer</h4>
                                    <div class="barber-rating" style="color: #64748b; font-weight: 500;">
                                        Próximo livre
                                    </div>
                                </div>

                                <!-- Cards Individuais -->
                                <?php foreach ($barbeirosAtivosArr as $id => $b): 
                                    $foto_b = trim($b['foto'] ?? '');
                                    $foto_segura = (!empty($foto_b) && file_exists($foto_b)) ? $foto_b : 'uploads/default-profile.jpg';
                                    
                                    $avaliacoesDoBarbeiro = array_filter($avaliacoesData ?? [], fn($av) => $av['barbeiro_id'] === $id);
                                    $media = 5.0; 
                                    if (count($avaliacoesDoBarbeiro) > 0) {
                                        $somaNotas = array_sum(array_column($avaliacoesDoBarbeiro, 'rating'));
                                        $media = round($somaNotas / count($avaliacoesDoBarbeiro), 1);
                                    }
                                    $isFavorito = in_array($id, $favoritosBarbeirosIds, true);
                                ?>
                                    <div class="barber-card-selectable custom-option <?= $isFavorito ? 'is-favorite' : '' ?>" data-value="<?= htmlspecialchars($id) ?>" onclick="selectBarberCard('<?= htmlspecialchars($id) ?>', this, event)">
                                        <button type="button" class="btn-favorite-barber <?= $isFavorito ? 'active' : '' ?>" data-barbeiro-id="<?= htmlspecialchars($id) ?>" title="<?= $isFavorito ? 'Remover dos favoritos' : 'Favoritar barbeiro' ?>" aria-label="<?= $isFavorito ? 'Remover dos favoritos' : 'Favoritar barbeiro' ?>">
                                            <i class="<?= $isFavorito ? 'fa' : 'far' ?> fa-star"></i>
                                        </button>
                                        <img src="<?= htmlspecialchars($foto_segura) ?>" alt="<?= htmlspecialchars($b['nome']) ?>" class="barber-avatar">
                                        <h4 class="barber-name"><?= htmlspecialchars($b['nome']) ?></h4>
                                        <div class="barber-rating">
                                            <i class="fa fa-star"></i> <?= number_format($media, 1, ',', '.') ?>
                                        </div>
                                        <?php if ($isFavorito): ?>
                                            <span class="favorite-badge"><i class="fa fa-star"></i> Favorito</span>
                                        <?php endif; ?>
                                        <button type="button" class="btn-detalhes-barbeiro-card btn-detalhes-barbeiro" data-barbeiro-id="<?= htmlspecialchars($id) ?>">
                                            <i class="fa fa-info-circle"></i> Perfil
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <input type="hidden" name="barbeiro" id="barbeiro" required>
                        </div>
                    </div>

                    <div class="wizard-buttons">
                        <button type="button" class="btn-wizard btn-prev" onclick="prevStep(2)"><i class="fa fa-arrow-left"></i> Voltar</button>
                        <button type="button" class="btn-wizard btn-next" onclick="nextStep(2)">Escolher Serviço <i class="fa fa-arrow-right"></i></button>
                    </div>
                </div>

                <div class="wizard-step" id="step-3">
                    <div class="booking-step-card">
                        <h3 class="step-title"><i class="fa fa-cut"></i> 3. O que vamos fazer hoje?</h3>
                        <p class="step-helper">Toque nos servicos desejados. O resumo e os horarios sao atualizados automaticamente.</p>
                        <div class="form-group" style="margin-bottom: 0;">
                            <div id="servicos-container">
                                
                                <?php foreach ($itensAgrupados as $index => $categoria): ?>
                                    <h4 class="categoria-titulo" style="margin-top: 20px; font-size: 1.1rem; color: #1e293b; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; font-weight: 700;">
                                        <?php 
                                            $catIcon = 'fa-tag';
                                            if (stripos($categoria['nome'], 'barba') !== false) $catIcon = 'fa-magic';
                                            if (stripos($categoria['nome'], 'cabelo') !== false || stripos($categoria['nome'], 'corte') !== false) $catIcon = 'fa-cut';
                                            if (stripos($categoria['nome'], 'combo') !== false) $catIcon = 'fa-star';
                                        ?>
                                        <i class="fa <?= $catIcon ?>" style="color: var(--app-accent); margin-right: 8px;"></i>
                                        <?= htmlspecialchars($categoria['nome']) ?>
                                    </h4>
                                    
                                    <div class="services-grid-wrapper" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; margin-top: 15px;">
                                        <?php foreach ($categoria['combos'] as $c): 
                                            $icone = obterIconeServico($c['nome']);
                                        ?>
                                            <button type="button" class="servico-btn combo-btn" data-id="<?= htmlspecialchars($c['id']) ?>" data-servicos-ids="<?= htmlspecialchars($c['servicos_ids']) ?>" data-valor="<?= htmlspecialchars($c['valor']) ?>" data-slots="<?= htmlspecialchars($c['slots']) ?>" style="margin: 0; width: 100%;">
                                                <span>
                                                    <i class="fa <?= $icone ?>" style="color: var(--app-accent); margin-right: 6px; font-size: 1.1em; width: 20px; text-align: center;"></i>
                                                    <?= htmlspecialchars($c['nome']) ?> <br>
                                                    <small style="font-weight: normal; color: #b45309; margin-left: 28px;">R$ <?= htmlspecialchars($c['valor']) ?></small>
                                                </span>
                                                <span class="duracao"><i class="fa fa-clock"></i> <?= htmlspecialchars($c['slots'] * 30) ?> min</span>
                                            </button>
                                        <?php endforeach; ?>

                                        <?php foreach ($categoria['servicos'] as $s): 
                                              $slots_servico = (int)($s['slots'] ?? 1);
                                              if ($slots_servico <= 0) $slots_servico = 1;
                                              $icone = obterIconeServico($s['nome']);
                                         ?>
                                            <button type="button" class="servico-btn" data-id="<?= htmlspecialchars($s['id']) ?>" data-valor="<?= htmlspecialchars($s['valor']) ?>" data-slots="<?= $slots_servico ?>" style="margin: 0; width: 100%;">
                                                <span>
                                                    <i class="fa <?= $icone ?>" style="color: var(--app-accent); margin-right: 6px; font-size: 1.1em; width: 20px; text-align: center;"></i>
                                                    <?= htmlspecialchars($s['nome']) ?> <br>
                                                    <small style="font-weight: normal; color: #64748b; margin-left: 28px;">R$ <?= htmlspecialchars($s['valor']) ?></small>
                                                </span>
                                                <span class="duracao"><i class="fa fa-clock"></i> <?= $slots_servico * 30 ?> min</span>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <input type="hidden" name="servicos" id="servicos">
                            <input type="hidden" name="combos_selecionados" id="combos_selecionados">
                            
                            <div id="upsell-container" class="upsell-highlight" style="display: none;">
                                <span class="upsell-badge">Aproveite</span>
                                <h4 class="upsell-title">
                                    <i class="fa fa-bolt" style="color: #f59e0b; font-size: 1.5rem;"></i> Oferta Especial Rápida!
                                </h4>
                                <p class="upsell-desc">Que tal adicionar um destes serviços ao seu atendimento com apenas um clique?</p>
                                <div id="upsell-items" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="wizard-buttons">
                        <button type="button" class="btn-wizard btn-prev" onclick="prevStep(3)"><i class="fa fa-arrow-left"></i> Voltar</button>
                        <button type="button" class="btn-wizard btn-next" onclick="nextStep(3)">Escolher Data/Hora <i class="fa fa-arrow-right"></i></button>
                    </div>
                </div>

                <div class="wizard-step" id="step-4">
                    <div class="booking-step-card">
                        <h3 class="step-title"><i class="fa fa-calendar-alt"></i> 4. Escolha Data e Horário</h3>
                        <p class="step-helper">Datas e horarios aparecem conforme a duracao dos servicos escolhidos.</p>
                        <div class="form-group modern-group">
                            <label for="data"><i class="fa fa-calendar-day"></i> Selecione o dia desejado</label>
                            <div class="input-icon">
                                <input type="text" name="data" id="data" required <?= $input_disabled_attr ?> placeholder="Clique para abrir o calendário">
                            </div>
                            <div id="dias-disponiveis-info" style="margin-top: 8px; font-size: 0.85rem; color: #64748b;"></div>
                        </div>

                        <div class="form-group modern-group" style="margin-bottom: 0;">
                            <label><i class="fa fa-clock"></i> Horários Livres neste dia</label>
                            <div id="horarios-container" class="horario-grid">
                                <div style="grid-column: 1 / -1; text-align: center; padding: 20px; background: #ffffff; border: 1px dashed #cbd5e1; border-radius: 10px; color: #94a3b8;">
                                    Selecione uma data para ver os horários disponíveis.
                                </div>
                            </div>
                            <input type="hidden" name="horario" id="horario" required>
                            <input type="hidden" name="horario_barbeiro_id" id="horario_barbeiro_id">
                        </div>
                    </div>
                    <div class="wizard-buttons">
                        <button type="button" class="btn-wizard btn-prev" onclick="prevStep(4)"><i class="fa fa-arrow-left"></i> Voltar</button>
                        <button type="button" class="btn-wizard btn-next" onclick="nextStep(4)">Ir para Finalização <i class="fa fa-arrow-right"></i></button>
                    </div>
                </div>

                <div class="wizard-step" id="step-5">
                    
                    <div class="receipt-ticket">
                        <div class="receipt-header">
                            <h3 style="margin: 0; font-size: 1.4rem; font-weight: 800; letter-spacing: 1px;">
                                <i class="fa fa-receipt" style="color: var(--app-accent);"></i> RESUMO DO AGENDAMENTO
                            </h3>
                            <p style="margin: 5px 0 0; font-size: 0.9rem; color: #64748b;">Confirme os dados para finalizar</p>
                        </div>
                        
                        <div class="receipt-body">
                            <div id="confirmacao-resumo-premium" class="confirmation-premium">
                                <div class="confirmation-empty">
                                    <i class="fa fa-clipboard-check"></i>
                                    <p>Seu resumo aparecerá aqui assim que você escolher serviços, data e horário.</p>
                                </div>
                            </div>
                            
                            <?php if ($cliente_logado && $assinaturaAtiva):
                                $planoAtual = $planosArr[$assinaturaAtiva['plano_id']] ?? null;
                                $diasRestantes = max(0, (int)ceil((strtotime($assinaturaAtiva['data_fim']) - time()) / (60 * 60 * 24)));
                                $corStatus = ($diasRestantes <= 5) ? '#f59e0b' : '#10b981';
                                $cancelAgendado = ($assinaturaAtiva['status'] ?? '') === 'cancelamento_agendado';
                            ?>
                            <div style="background: #f0fdf4; padding: 15px; border: 1px solid <?= $corStatus ?>; border-radius: 12px; margin-bottom: 20px;">
                                <div style="display: flex; align-items: center; gap: 15px;">
                                    <div style="background: <?= $corStatus ?>; color: white; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0;"><i class="fa fa-crown"></i></div>
                                    <div style="min-width: 0;">
                                        <h4 style="margin: 0; font-size: 0.95rem; color: #064e3b;">
                                            Assinatura Ativa<?= $planoAtual ? ' · ' . htmlspecialchars($planoAtual['nome']) : '' ?>
                                        </h4>
                                        <span style="font-size: 0.8em; color: #475569;">Benefícios do plano aplicados a este agendamento.</span>
                                    </div>
                                </div>
                                <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px;">
                                    <span class="plano-det-chip" style="background:#dcfce7; color:#065f46;">
                                        <i class="fa fa-calendar-check"></i>
                                        <?= $cancelAgendado ? 'Encerra' : 'Válido até' ?> <?= date('d/m/Y', strtotime($assinaturaAtiva['data_fim'])) ?>
                                    </span>
                                    <span class="plano-det-chip" style="background:#dcfce7; color:#065f46;">
                                        <i class="fa fa-hourglass-half"></i>
                                        <?= (int)$diasRestantes ?> dia<?= $diasRestantes == 1 ? '' : 's' ?> restante<?= $diasRestantes == 1 ? '' : 's' ?>
                                    </span>
                                    <?php if ($cancelAgendado): ?>
                                        <span class="plano-det-chip" style="background:#fef3c7; color:#92400e;"><i class="fa fa-triangle-exclamation" style="color:#f59e0b;"></i> Cancelamento agendado</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($planosArr) && !$assinaturaAtiva && $cliente_logado && $stripeConfigurado): ?>
                            <div style="background: #f8fafc; padding: 15px; border: 1px dashed #cbd5e1; border-radius: 12px; margin-bottom: 20px;">
                                <label style="color: #0f172a; display: flex; align-items: center; cursor: pointer; font-weight: 700; font-size: 0.95em; margin: 0;">
                                    <input type="checkbox" name="aderir_plano" id="aderir_plano" value="1" style="width: 20px; height: 20px; margin-right: 12px; accent-color: #10b981; flex-shrink: 0;">
                                    <span><i class="fa fa-crown" style="color: #10b981;"></i> Quero aderir à Assinatura!</span>
                                </label>

                                <div id="selecao-plano-container" style="display: none; margin-top: 15px; padding-top: 15px; border-top: 1px solid #e2e8f0;">
                                    <select name="plano_escolhido_id" id="plano_escolhido_id" style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.95em; background: white; color: #1e293b; font-weight: 600;">
                                        <option value="">Selecione um Plano...</option>
                                        <?php foreach ($planosArr as $p): ?>
                                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nome']) ?> - R$ <?= number_format((float)$p['valor'], 2, ',', '.') ?>/mês</option>
                                        <?php endforeach; ?>
                                    </select>

                                    <!-- Detalhes do plano escolhido (preenchido por js/agendamento.js) -->
                                    <div id="plano-detalhes-box" style="display: none; margin-top: 14px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden;">
                                        <div style="background: linear-gradient(135deg, #0f172a, #1e293b); color: #fff; padding: 14px 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                                            <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
                                                <i class="fa fa-crown" style="color: #fbbf24;"></i>
                                                <strong id="plano-det-nome" style="font-size: 0.98rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"></strong>
                                            </div>
                                            <div style="text-align: right; flex-shrink: 0;">
                                                <strong id="plano-det-valor" style="font-size: 1.05rem; color: #34d399;"></strong>
                                                <span style="display: block; font-size: 0.68rem; color: #cbd5e1;">por mês</span>
                                            </div>
                                        </div>
                                        <div style="padding: 14px 16px;">
                                            <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px;">
                                                <span class="plano-det-chip"><i class="fa fa-rotate"></i> Renova a cada 30 dias</span>
                                                <span class="plano-det-chip"><i class="fa fa-ban"></i> Cancele quando quiser</span>
                                                <span class="plano-det-chip"><i class="fa fa-bolt"></i> Ativação imediata</span>
                                            </div>
                                            <div id="plano-det-servicos-wrap">
                                                <small style="display: block; color: #64748b; font-weight: 700; text-transform: uppercase; font-size: 0.66rem; letter-spacing: .04em; margin-bottom: 8px;">Serviços ilimitados no plano</small>
                                                <ul id="plano-det-servicos" style="list-style: none; margin: 0; padding: 0; display: grid; gap: 6px;"></ul>
                                            </div>
                                            <p id="plano-det-validade" style="margin: 12px 0 0; font-size: 0.78rem; color: #475569;"></p>
                                        </div>
                                    </div>

                                    <p style="margin: 10px 0 0; font-size: 0.8rem; color: #64748b;"><i class="fab fa-stripe" style="color: #635bff;"></i> Pagamento seguro e recorrente via Stripe. Você será redirecionado para concluir a assinatura.</p>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($cliente_logado && ($config_fidelidade['ativado'] ?? 0)): 
                                $pontos_atuais = getClientFidelityPoints($_SESSION['cliente_id']);
                                $pontos_necessarios = $config_fidelidade['pontos_necessarios'] ?? 10;
                            ?>
                                <div class="fidelity-points-inline">
                                    <span class="fidelity-points-icon"><i class="fa fa-star"></i></span>
                                    <span>
                                        <small>Seus pontos fidelidade</small>
                                        <strong><?= $pontos_atuais ?> pts</strong>
                                    </span>
                                </div>
                            <?php if ($pontos_atuais >= $pontos_necessarios):
                                $regrasFidAg = function_exists('getFidelidadeRegras') ? getFidelidadeRegras() : ['tipo_recompensa' => 'percentual', 'desconto_percentual' => ($config_fidelidade['desconto_percentual'] ?? 50), 'valor_desconto_fixo' => 0];
                                if ($regrasFidAg['tipo_recompensa'] === 'valor_fixo') {
                                    $recompensaAgTxt = 'R$ ' . number_format($regrasFidAg['valor_desconto_fixo'], 2, ',', '.') . ' de desconto';
                                } elseif ($regrasFidAg['tipo_recompensa'] === 'servico_gratis') {
                                    $recompensaAgTxt = 'um serviço grátis';
                                } else {
                                    $recompensaAgTxt = (float)$regrasFidAg['desconto_percentual'] . '% de desconto';
                                }
                            ?>
                                <div style="background: #fffbeb; padding: 15px; border: 1px dashed #fcd34d; border-radius: 12px; margin-bottom: 20px;">
                                    <label style="color: #92400e; display: flex; align-items: center; cursor: pointer; font-weight: 700; font-size: 0.95em; margin: 0;">
                                        <input type="checkbox" name="usar_fidelidade" value="1" style="width: 20px; height: 20px; margin-right: 12px; accent-color: #f59e0b; flex-shrink: 0;" <?= $input_disabled_attr ?>>
                                        <span><i class="fa fa-star"></i> Resgatar <strong><?= $pontos_necessarios ?> pontos</strong> e ganhar <strong><?= $recompensaAgTxt ?></strong>?</span>
                                    </label>
                                </div>
                            <?php endif; ?>
                            <?php endif; ?>

                            <div class="form-group modern-group discount-box" style="margin-bottom: 20px;">
                                <label for="cupom" style="font-size: 0.9rem;">
                                    <i class="fa fa-ticket-alt" style="transform: rotate(-45deg); color: #64748b;"></i> Código Promocional
                                </label>
                                <div class="discount-apply-row">
                                    <input type="text" name="cupom" id="cupom" placeholder="INSIRA O CODIGO" <?= $input_disabled_attr ?>>
                                    <button type="button" id="btn-aplicar-cupom"><i class="fa fa-check"></i> Aplicar</button>
                                </div>
                                <div id="cupom-feedback" class="discount-feedback" aria-live="polite"></div>
                            </div>

                            <div class="receipt-divider"></div>

                            <div class="form-group modern-group" style="margin-bottom: 0;">
                                <label for="observacoes" style="font-size: 0.9rem;">
                                    <i class="fa fa-comment-dots" style="color: #64748b;"></i> Notas para o Barbeiro (Opcional)
                                </label>
                                <div class="input-icon">
                                    <textarea name="observacoes" id="observacoes" rows="2" placeholder="Ex: Cabelo mais sensível, preferência de estilo..." <?= $input_disabled_attr ?> style="resize: vertical; line-height: 1.5; font-size: 0.9rem; padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0;"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="wizard-buttons">
                        <button type="button" class="btn-wizard btn-prev" onclick="prevStep(5)"><i class="fa fa-arrow-left"></i> Voltar</button>
                        <button type="submit" class="btn-wizard btn-primary" <?= $input_disabled_attr ?> style="flex-grow: 1; padding: 15px; font-size: 1.1rem; box-shadow: 0 10px 20px -5px rgba(245, 158, 11, 0.4);"><i class="fa fa-check-circle"></i> Confirmar Agendamento</button>
                    </div>
                </div>

            </form>
        </main>
    </div>

    <div id="modal-barbeiro-detalhes" class="modal-overlay">
        <div class="modal-content">
            <button class="modal-close">&times;</button>
            <div id="barbeiro-detalhes-content"></div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://npmcdn.com/flatpickr/dist/l10n/pt.js"></script>
    
    <script>
        const agendamentoConfig = {
            antecedencia_maxima: <?= (int)($configAgendamento['antecedencia_maxima'] ?? 30) ?>,
            max_servicos: <?= (int)($configAgendamento['max_servicos'] ?? 4) ?>
        };

        const arrayBarbeirosBruto = <?= json_encode(array_values($barbeirosAtivosArr)) ?>;
        const arrayTodosBarbeirosBruto = <?= json_encode(array_values($barbeirosArr)) ?>;
        
        let barbeirosData = arrayBarbeirosBruto.map(b => {
            const fotoStr = b.foto ? b.foto.trim() : '';
            b.foto = fotoStr !== '' ? fotoStr : 'uploads/default-profile.jpg';
            return b;
        });

        const todosBarbeirosData = arrayTodosBarbeirosBruto.map(b => {
            const fotoStr = b.foto ? b.foto.trim() : '';
            b.foto = fotoStr !== '' ? fotoStr : 'uploads/default-profile.jpg';
            return b;
        });

        const servicosData = <?= json_encode($servicosArr) ?>;
        const combosData = <?= json_encode($combosArr) ?>;
        const avaliacoesData = <?= json_encode(array_values($avaliacoesArr)) ?>; 
        const clientesData = <?= json_encode($clientesArr) ?>;
        const planosData = <?= json_encode($planosArr) ?>;
        const clienteAssinaturaAtiva = <?= json_encode($assinaturaAtiva) ?>;
        const isClienteLogado = <?= $cliente_logado ? 'true' : 'false' ?>;
        const favoritosBarbeirosIds = <?= json_encode(array_values($favoritosBarbeirosIds)) ?>;
        
        const barbeiroPreselecionado = '<?= $barbeiro_preselecionado ?? "" ?>';
        const servicosPreselecionados = <?= json_encode($servicos_preselecionados ?? []) ?>;

        function selectBarberCard(id, element, event) {
            // Ignora a seleção do card se o clique foi no botão de perfil
            if (event && event.target.closest('.btn-detalhes-barbeiro, .btn-favorite-barber')) {
                return;
            }

            if (element && element.classList.contains('disabled')) {
                if (typeof mostrarErroWizard === 'function') {
                    mostrarErroWizard('Este profissional nao realiza todos os servicos selecionados.', document.getElementById('barbeiro'));
                }
                return;
            }
            
            document.querySelectorAll('.barber-card-selectable').forEach(el => el.classList.remove('selected'));
            if(element) element.classList.add('selected');
            
            const hiddenInput = document.getElementById('barbeiro');
            if(hiddenInput.value !== id) {
                hiddenInput.value = id;
                hiddenInput.dispatchEvent(new Event('change'));
            }
        }

        document.getElementById('barbeiro').addEventListener('change', function() {
            const currentVal = this.value;
            const targetCard = document.querySelector(`.barber-card-selectable[data-value="${currentVal}"]`);
            if(targetCard && !targetCard.classList.contains('selected')) {
                document.querySelectorAll('.barber-card-selectable').forEach(el => el.classList.remove('selected'));
                targetCard.classList.add('selected');
            }
        });
    </script>
    
    <script src="<?= assetUrl('js/agendamento_wizard.js') ?>"></script>
    <script src="<?= assetUrl('js/script.js') ?>"></script>
    <script src="<?= assetUrl('js/agendamento.js') ?>"></script>
    <?php include 'chatbot_widget.php'; ?>
</body>
</html>
