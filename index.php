<?php
// index
// Carrega as funções e as configurações gerais da barbearia
require_once 'functions.php';
$configGeral = carregarConfigGeral();
$landingConfig = carregarLandingPageConfig();

// Carrega as configurações de cores para a Landing Page
$themeConfig = function_exists('_lerConfigSQLite') ? _lerConfigSQLite('theme_config', []) : [];

// Carrega dados dinâmicos para a página via SQLite
$barbeirosArr = lerDados('barbeiros', ['id', 'nome', 'foto', 'username', 'status', 'servicos_ids']);
$servicosArr = lerDados('servicos', ['id', 'nome', 'valor', 'slots', 'categoria_id']);
// Descrições (coluna nova) buscadas de forma segura para não quebrar caso ainda não exista.
$servicoDescricoes = [];
try {
    foreach (getDB()->query("SELECT id, descricao FROM servicos") as $rDesc) {
        $servicoDescricoes[$rDesc['id']] = $rDesc['descricao'] ?? '';
    }
} catch (Exception $e) {}
$avaliacoesArr = lerDados('avaliacoes', ['id', 'agendamento_id', 'cliente_id', 'barbeiro_id', 'rating', 'comment', 'timestamp']);
$clientesArr = lerDados('clientes', ['id', 'nome', 'foto_perfil']);
$planosArr = lerDados('planos', ['id', 'nome', 'valor', 'servicos_ids']);

// Carrega as respostas das avaliações
$respostasAvaliacoesRaw = lerDados('respostas_avaliacoes', ['id_resposta', 'id_avaliacao', 'texto_resposta', 'timestamp']);
$respostasAvaliacoesArr = [];
foreach ($respostasAvaliacoesRaw as $resp) {
    if (isset($resp['id_avaliacao'])) {
        $respostasAvaliacoesArr[$resp['id_avaliacao']] = $resp;
    }
}

// --- Leitura Segura de Horários de Trabalho (SQLite) ---
$horariosTrabalhoRaw = [];
try {
    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS horarios_trabalho (barbeiro_id TEXT, dia TEXT, inicio TEXT, fim TEXT, ativo TEXT)");

    $stmtWork = $pdo->query("SELECT barbeiro_id, dia, inicio, fim FROM horarios_trabalho WHERE ativo = '1'");
    if ($stmtWork) {
        $horariosTrabalhoRaw = $stmtWork->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {}

// Filtra para criar um array APENAS com barbeiros ativos
$barbeirosAtivosArr = array_filter($barbeirosArr, function($barbeiro) {
    $status = $barbeiro['status'] ?? 'ativo';
    if(empty($status)) $status = 'ativo';
    return $status === 'ativo';
});

// --- Lógica de Avaliações ---
$avaliacoesDestacadasIDs = lerDados('avaliacoes_destacadas', ['id_avaliacao']);
$avaliacoesParaExibir = [];
if (!empty($avaliacoesDestacadasIDs)) {
    foreach ($avaliacoesDestacadasIDs as $id_destacado => $data) {
        if (isset($avaliacoesArr[$id_destacado])) {
            $avaliacoesParaExibir[$id_destacado] = $avaliacoesArr[$id_destacado];
        }
    }
    shuffle($avaliacoesParaExibir);
} else {
    $avaliacoesBoas = array_filter($avaliacoesArr, fn($av) => (int)($av['rating'] ?? 0) >= 4);
    shuffle($avaliacoesBoas);
    $avaliacoesParaExibir = array_slice($avaliacoesBoas, 0, 4);
}

// Calcula a média de avaliações para cada barbeiro
$mediaAvaliacoesBarbeiros = [];
foreach ($barbeirosAtivosArr as $barbeiroId => $barbeiro) {
    $avaliacoesDoBarbeiro = array_filter($avaliacoesArr, fn($av) => $av['barbeiro_id'] === $barbeiroId);
    if (count($avaliacoesDoBarbeiro) > 0) {
        $somaNotas = array_sum(array_column($avaliacoesDoBarbeiro, 'rating'));
        $mediaAvaliacoesBarbeiros[$barbeiroId] = round($somaNotas / count($avaliacoesDoBarbeiro), 1);
    } else {
        $mediaAvaliacoesBarbeiros[$barbeiroId] = 0;
    }
}

// Determina o Barbeiro em Destaque (Com Fallback se não houver avaliações)
$barbeiroDoMesId = null;
if (!empty($mediaAvaliacoesBarbeiros) && max($mediaAvaliacoesBarbeiros) > 0) {
    $barbeiroDoMesId = array_keys($mediaAvaliacoesBarbeiros, max($mediaAvaliacoesBarbeiros))[0];
} elseif (!empty($barbeirosAtivosArr)) {
    reset($barbeirosAtivosArr);
    $barbeiroDoMesId = key($barbeirosAtivosArr);
    $mediaAvaliacoesBarbeiros[$barbeiroDoMesId] = 5.0;
}

// --- Prova social agregada (para a faixa logo abaixo do hero) ---
$totalAvaliacoes = count($avaliacoesArr);
$mediaGeralAvaliacoes = 0;
if ($totalAvaliacoes > 0) {
    $somaGeral = array_sum(array_column($avaliacoesArr, 'rating'));
    $mediaGeralAvaliacoes = round($somaGeral / $totalAvaliacoes, 1);
}

// --- Serviços em destaque (seleção determinística pelos de maior valor, em vez de sorteio) ---
$servicosDestaque = $servicosArr;
usort($servicosDestaque, fn($a, $b) => (float)($b['valor'] ?? 0) <=> (float)($a['valor'] ?? 0));
$servicosDestaque = array_slice($servicosDestaque, 0, 4);

// --- Consolidação de Horários ---
$horariosConsolidadosPorDia = [];
foreach ($horariosTrabalhoRaw as $row) {
    $barbeiro_id_horario = $row['barbeiro_id'] ?? '';
    $dia = (int)($row['dia'] ?? 0);
    $inicio = $row['inicio'] ?? '';
    $fim = $row['fim'] ?? '';

    if (isset($barbeirosAtivosArr[$barbeiro_id_horario]) && !empty($inicio) && !empty($fim)) {
        if (!isset($horariosConsolidadosPorDia[$dia])) {
            $horariosConsolidadosPorDia[$dia] = ['inicio' => $inicio, 'fim' => $fim];
        } else {
            if ($inicio < $horariosConsolidadosPorDia[$dia]['inicio']) $horariosConsolidadosPorDia[$dia]['inicio'] = $inicio;
            if ($fim > $horariosConsolidadosPorDia[$dia]['fim']) $horariosConsolidadosPorDia[$dia]['fim'] = $fim;
        }
    }
}

$horariosPorDia = [];
$dias_semana_texto = ['1' => 'Segunda', '2' => 'Terça', '3' => 'Quarta', '4' => 'Quinta', '5' => 'Sexta', '6' => 'Sábado', '0' => 'Domingo'];
foreach ($horariosConsolidadosPorDia as $dia => $horas) {
    $horarioKey = $horas['inicio'] . ' às ' . $horas['fim'];
    $horariosPorDia[$horarioKey][] = $dia;
}

// --- WhatsApp (usado no botão flutuante e na seção de contato) ---
$whatsappNumero = !empty($configGeral['link_whatsapp']) ? preg_replace('/\D/', '', $configGeral['link_whatsapp']) : '';

// --- Metadados para compartilhamento (Open Graph) ---
$baseUrl = appBaseUrl(); // inclui a subpasta de instalacao (ver lib/utils_functions.php)
$logoPath = $configGeral['logo_path'] ?? 'uploads/logo.png';
$ogImageUrl = $baseUrl . '/' . ltrim($logoPath, '/');

// Dimensoes reais da logo, escaladas para a caixa de 240px do hero. Sem isso a
// tag sai sempre 240x240 e a logo estica quando nao e quadrada (ou quando o CSS
// mobile reduz so a largura).
$logoW = 240; $logoH = 240;
$logoArquivo = __DIR__ . '/' . ltrim($logoPath, '/');
if (is_file($logoArquivo) && ($dim = @getimagesize($logoArquivo)) && $dim[0] > 0 && $dim[1] > 0) {
    $escala = 240 / max($dim[0], $dim[1]);
    $logoW = (int) round($dim[0] * $escala);
    $logoH = (int) round($dim[1] * $escala);
}
$metaDescricao = $landingConfig['hero_subtitle'] ?? 'Agende seu horário com os melhores profissionais da região.';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php include __DIR__ . '/pwa_head.php'; ?>
    <title><?= htmlspecialchars($configGeral['nome_barbearia'] ?? 'Nossa Barbearia') ?> - Bem-vindo</title>
    <meta name="description" content="<?= htmlspecialchars($metaDescricao) ?>">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= htmlspecialchars($configGeral['nome_barbearia'] ?? 'Nossa Barbearia') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($metaDescricao) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($ogImageUrl) ?>">

    <?php // Dados estruturados (schema.org HairSalon). A funcao omite sozinha
       // o que nao estiver configurado: sem avaliacoes nao emite aggregateRating,
       // e campos ainda com o valor placeholder do install nao entram. ?>
    <?= seoRenderizarJsonLd([
        'config'          => $configGeral,
        'baseUrl'         => $baseUrl,
        'descricao'       => $metaDescricao,
        'horarios'        => $horariosConsolidadosPorDia,
        'servicos'        => $servicosArr,
        'totalAvaliacoes' => $totalAvaliacoes,
        'mediaAvaliacoes' => $mediaGeralAvaliacoes,
    ]) ?>

    <!-- FONTES MODERNAS: Outfit (Títulos) e Inter (Textos) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Outfit:wght@500;700;800;900&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<?php include __DIR__ . '/partials/index_style.php'; ?>
</head>
<body>

    <header class="hero" id="topo">
        <div class="bg-container">
            <?php
            $video_url = '';
            if (!empty($landingConfig['hero_video_path'])) {
                $video_path_teste = __DIR__ . '/' . ltrim($landingConfig['hero_video_path'], '/');
                if (file_exists($video_path_teste)) {
                    $video_url = $landingConfig['hero_video_path'];
                }
            }
            ?>

            <?php if (!empty($video_url)):
                $file_info = pathinfo($video_url);
                $video_type = (isset($file_info['extension']) && strtolower($file_info['extension']) === 'webm') ? 'video/webm' : 'video/mp4';
            ?>
            <video class="bg-video" autoplay loop muted playsinline preload="metadata">
                <source src="<?= htmlspecialchars($video_url) ?>" type="<?= $video_type ?>">
            </video>
            <?php else: ?>
                <div style="width:100%; height:100%; background: linear-gradient(135deg, <?= htmlspecialchars($app_primary) ?>, var(--app-bg));"></div>
            <?php endif; ?>

            <div class="bg-overlay"></div>
        </div>

        <div class="top-actions">
            <a href="cliente" class="action-btn"><i class="fas fa-user-circle"></i> Área do Cliente</a>
            <a href="admin.php" class="action-btn admin-btn"><i class="fas fa-sliders-h"></i> Admin</a>
        </div>

        <div class="hero-content">
            <?= imgComWebp($logoPath, [
                'alt' => 'Logo', 'class' => 'logo-main',
                'width' => $logoW, 'height' => $logoH, 'fetchpriority' => 'high',
            ]) ?>
            <h1 class="hero-title"><?= htmlspecialchars($landingConfig['hero_title'] ?? 'Nossa Barbearia') ?></h1>
            <p class="hero-subtitle"><?= htmlspecialchars($landingConfig['hero_subtitle'] ?? 'Estilo e Tradição com um toque de modernidade.') ?></p>

            <div class="hero-btn-wrapper">
                <a href="agendamento" class="btn-agendar"><i class="fas fa-calendar-check"></i> <?= htmlspecialchars($landingConfig['hero_cta_button'] ?? 'Agendar Agora') ?></a>
                <a href="#equipe" class="btn-agendar-secondary"><i class="fas fa-users"></i> <?= htmlspecialchars($landingConfig['hero_secondary_button'] ?? 'Conheça nossos Barbeiros') ?></a>
            </div>
        </div>

        <a href="#sobre" class="scroll-hint" aria-label="Rolar para ver mais"><i class="fas fa-chevron-down"></i></a>
    </header>

    <?php if ($totalAvaliacoes > 0 || !empty($landingConfig['stat1_number'])): ?>
    <div class="proof-strip reveal">
        <div class="proof-card">
            <?php if ($totalAvaliacoes > 0): ?>
            <div class="proof-item">
                <i class="fas fa-star"></i>
                <div>
                    <div class="proof-value"><?= number_format($mediaGeralAvaliacoes, 1, ',', '.') ?> / 5</div>
                    <div class="proof-label"><?= $totalAvaliacoes ?> avaliações</div>
                </div>
            </div>
            <div class="proof-divider"></div>
            <?php endif; ?>
            <div class="proof-item">
                <i class="fas fa-users"></i>
                <div>
                    <div class="proof-value"><?= htmlspecialchars($landingConfig['stat1_number'] ?? '—') ?>+</div>
                    <div class="proof-label"><?= htmlspecialchars($landingConfig['stat1_label'] ?? 'Clientes') ?></div>
                </div>
            </div>
            <div class="proof-divider"></div>
            <div class="proof-item">
                <i class="fas fa-user-tie"></i>
                <div>
                    <div class="proof-value"><?= count($barbeirosAtivosArr) ?></div>
                    <div class="proof-label">Profissionais</div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <nav class="bottom-nav">
        <a href="#sobre" class="nav-btn" data-section="sobre"><i class="fas fa-building"></i> Sobre</a>
        <a href="#servicos" class="nav-btn" data-section="servicos"><i class="fas fa-cut"></i> Serviços</a>
        <a href="#equipe" class="nav-btn" data-section="equipe"><i class="fas fa-users"></i> Equipe</a>
        <a href="#avaliacoes" class="nav-btn" data-section="avaliacoes"><i class="fas fa-star"></i> Avaliações</a>
        <a href="#local" class="nav-btn" data-section="local"><i class="fas fa-map-marker-alt"></i> Local</a>
    </nav>

    <!-- SEÇÃO: SOBRE NÓS -->
    <section id="sobre" class="content-section reveal">
        <h2 class="section-title"><i class="fas fa-building" style="color:var(--app-accent);"></i> <?= htmlspecialchars($landingConfig['about_title'] ?? 'Sobre Nossa Barbearia') ?></h2>
        <div class="glass-card about-grid">
            <p style="font-size: 1.15rem; line-height: 1.8; color: #e2e8f0; text-align: justify; margin:0;">
                <?= nl2br(htmlspecialchars($landingConfig['about_text'] ?? 'Seja bem-vindo(a).')) ?>
            </p>
            <div class="stats-container">
                <div class="stat-item"><div class="stat-number" data-target="<?= htmlspecialchars($landingConfig['stat1_number'] ?? '100') ?>">0</div><div class="stat-label"><?= htmlspecialchars($landingConfig['stat1_label'] ?? 'Clientes') ?></div></div>
                <div class="stat-item"><div class="stat-number" data-target="<?= htmlspecialchars($landingConfig['stat2_number'] ?? '5') ?>">0</div><div class="stat-label"><?= htmlspecialchars($landingConfig['stat2_label'] ?? 'Anos') ?></div></div>
                <div class="stat-item"><div class="stat-number" data-target="<?= htmlspecialchars($landingConfig['stat3_number'] ?? '1000') ?>">0</div><div class="stat-label"><?= htmlspecialchars($landingConfig['stat3_label'] ?? 'Cortes') ?></div></div>
            </div>
        </div>
        <div class="text-center" style="margin-top: 50px;">
            <a href="agendamento" class="action-btn" style="display: inline-flex; background: var(--app-accent); color: #fff; border: none; padding: 18px 40px; font-size: 1.1rem; border-radius: 50px;">Agendar Meu Horário</a>
        </div>
    </section>

    <!-- SEÇÃO: SERVIÇOS -->
    <section id="servicos" class="content-section reveal">
        <h2 class="section-title"><i class="fas fa-cut" style="color:var(--app-accent);"></i> <?= htmlspecialchars($landingConfig['services_title'] ?? 'Nossos Serviços') ?></h2>

        <h3 class="section-title" style="text-align: left; font-size: 1.3rem;"><i class="fas fa-fire" style="color:var(--app-accent); font-size:1.3rem;"></i> Destaques</h3>
        <div class="services-grid reveal-stagger">
            <?php foreach ($servicosDestaque as $servico): ?>
            <div class="glass-card service-card">
                <div class="service-icon"><i class="fas fa-magic"></i></div>
                <h3 style="margin: 0 0 10px 0;"><?= htmlspecialchars($servico['nome']) ?></h3>
                <?php $descServ = trim($servicoDescricoes[$servico['id']] ?? ''); ?>
                <?php if ($descServ !== ''): ?>
                    <p style="margin: 0 0 12px; font-size: .92rem; opacity: .8; line-height: 1.5;"><?= htmlspecialchars($descServ) ?></p>
                <?php endif; ?>
                <div style="font-family: var(--font-heading); font-size: 1.5rem; font-weight: 800; color: var(--app-accent);">R$ <?= number_format((float)$servico['valor'], 2, ',', '.') ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="text-center" style="margin-bottom: 60px;">
            <a href="agendamento" class="action-btn" style="display: inline-flex; border-color: rgba(255,255,255,0.2); color: #fff; padding: 15px 35px; border-radius: 50px;">Ver todos na Agenda <i class="fas fa-arrow-right" style="margin-left:5px;"></i></a>
        </div>

        <?php if (!empty($planosArr)): ?>
            <h3 class="section-title" style="text-align: left; border-top: 1px solid var(--glass-border); padding-top: 50px;"><i class="fas fa-crown" style="color:var(--app-accent); font-size:1.5rem;"></i> Barbearia por assinatura</h3>
            <p style="color: #94a3b8; font-size: 1.1rem; margin-bottom: 40px;">Tenha acesso ilimitado aos nossos serviços por um valor fixo mensal. Praticidade e economia.</p>

            <div class="services-grid reveal-stagger">
                <?php foreach ($planosArr as $plano): ?>
                <div class="glass-card vip-card">
                    <div class="vip-badge"><i class="fas fa-star"></i> ASSINATURA</div>
                    <div>
                        <div class="service-icon" style="color: var(--app-accent); margin-top:10px;"><i class="fas fa-gem"></i></div>
                        <h3 style="font-size: 1.5rem; margin-bottom: 5px;"><?= htmlspecialchars($plano['nome']) ?></h3>
                        <div style="margin: 20px 0;">
                            <span style="font-family: var(--font-heading); font-size: 2.8rem; font-weight: 900; color: #fff;">R$ <?= number_format((float)$plano['valor'], 2, ',', '.') ?></span>
                            <span style="color: #94a3b8; font-weight: 600;">/mês</span>
                        </div>

                        <ul style="list-style: none; padding: 0; color: #cbd5e1; margin-bottom: 30px; text-align: left; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 20px;">
                            <?php
                            $idsServicos = explode(',', $plano['servicos_ids']);
                            foreach($idsServicos as $sid):
                                $nomeServico = '';
                                $sid = trim($sid);
                                if(isset($servicosArr[$sid])) $nomeServico = $servicosArr[$sid]['nome'];
                                if($nomeServico):
                            ?>
                            <li style="margin-bottom: 12px; display: flex; align-items: center; font-size: 1rem;">
                                <i class="fas fa-check-circle" style="color: var(--app-accent); margin-right: 12px; font-size: 1.1rem;"></i>
                                <?= htmlspecialchars($nomeServico) ?>
                            </li>
                            <?php endif; endforeach; ?>
                        </ul>
                    </div>
                    <a href="agendamento" class="action-btn" style="background: var(--app-accent); color: #fff; border: none; justify-content: center; padding: 15px; border-radius: 16px;">Assinar Plano</a>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- SEÇÃO: EQUIPE -->
    <section id="equipe" class="content-section reveal">
        <h2 class="section-title"><i class="fas fa-users" style="color:var(--app-accent);"></i> <?= htmlspecialchars($landingConfig['team_title'] ?? 'Nossa Equipe') ?></h2>

        <?php
        if($barbeiroDoMesId && isset($barbeirosAtivosArr[$barbeiroDoMesId])):
            $barbeiroDestaque = $barbeirosAtivosArr[$barbeiroDoMesId];
            $foto_destaque = (!empty(trim($barbeiroDestaque['foto'] ?? '')) && file_exists(trim($barbeiroDestaque['foto']))) ? trim($barbeiroDestaque['foto']) : 'uploads/default-profile.jpg';
        ?>
        <div class="glass-card featured-barber">
            <h3 style="color: var(--app-accent); margin: 0 0 10px 0; font-size: 1.6rem; font-weight: 800;"><i class="fas fa-trophy"></i> <?= htmlspecialchars($landingConfig['featured_title'] ?? 'Barbeiro em Destaque') ?></h3>
            <p style="color: #94a3b8; font-size: 1.05rem; margin-bottom: 25px;"><?= htmlspecialchars($landingConfig['featured_subtitle'] ?? 'Nosso melhor profissional do momento.') ?></p>

            <img src="<?= htmlspecialchars($foto_destaque) ?>" alt="Foto do profissional em destaque" class="team-avatar" width="140" height="140" loading="lazy" decoding="async" style="width: 140px; height: 140px;">
            <h2 style="margin: 0 0 10px 0; font-size: 1.8rem;"><?= strtoupper(htmlspecialchars($barbeiroDestaque['nome'])) ?></h2>
            <div class="rating-stars" style="margin-bottom: 25px; font-size: 1.4rem;">
                <?= str_repeat('★', floor($mediaAvaliacoesBarbeiros[$barbeiroDoMesId])) . str_repeat('☆', 5 - floor($mediaAvaliacoesBarbeiros[$barbeiroDoMesId])) ?>
                <span style="color:#64748b; font-size: 0.95rem; margin-left: 8px; font-family: var(--font-body);">(Média <?= $mediaAvaliacoesBarbeiros[$barbeiroDoMesId] ?>)</span>
            </div>
            <a href="agendamento?barbeiro=<?= $barbeiroDoMesId ?>" class="action-btn" style="display: inline-flex; background: var(--app-accent); border: none; padding: 15px 35px; border-radius: 50px;">Agendar Exclusivo</a>
        </div>
        <?php endif; ?>

        <h3 class="section-title" style="text-align: left; border-bottom: 1px solid var(--glass-border); padding-bottom: 15px; margin-bottom: 30px;">O Time Completo</h3>
        <div class="team-grid reveal-stagger">
            <?php foreach ($barbeirosAtivosArr as $id => $barbeiro):
                $foto_barbeiro_grade = (!empty(trim($barbeiro['foto'] ?? '')) && file_exists(trim($barbeiro['foto']))) ? trim($barbeiro['foto']) : 'uploads/default-profile.jpg';
            ?>
            <div class="glass-card team-card">
                <img src="<?= htmlspecialchars($foto_barbeiro_grade) ?>" alt="Foto do profissional" class="team-avatar" width="120" height="120" loading="lazy" decoding="async">
                <h3 style="margin: 0 0 8px 0; font-size: 1.3rem;"><?= htmlspecialchars($barbeiro['nome']) ?></h3>
                <div class="rating-stars">
                    <?= str_repeat('★', floor($mediaAvaliacoesBarbeiros[$id])) . str_repeat('☆', 5 - floor($mediaAvaliacoesBarbeiros[$id])) ?>
                </div>
                <div style="font-size: 0.85rem; color: #64748b; margin-top: 8px; font-weight: 600;">Nota: <?= $mediaAvaliacoesBarbeiros[$id] ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- SEÇÃO: AVALIAÇÕES -->
    <section id="avaliacoes" class="content-section reveal">
        <h2 class="section-title"><i class="fas fa-star" style="color:var(--app-accent);"></i> <?= htmlspecialchars($landingConfig['testimonials_title'] ?? 'O que dizem sobre nós') ?></h2>
        <?php if(!empty($avaliacoesParaExibir)): ?>
        <div class="reviews-grid reveal-stagger">
            <?php
            foreach ($avaliacoesParaExibir as $avaliacao):
                $cliente = $clientesArr[$avaliacao['cliente_id']] ?? null;
                $nomeCliente = $cliente['nome'] ?? 'Anônimo';
                $fotoCliente = ($cliente && !empty(trim($cliente['foto_perfil'] ?? '')) && file_exists(trim($cliente['foto_perfil']))) ? trim($cliente['foto_perfil']) : 'uploads/default-profile.jpg';
                $nomeBarbeiro = $barbeirosArr[$avaliacao['barbeiro_id']]['nome'] ?? 'Nosso Time';

                $id_av = $avaliacao['id'];
                $resposta = $respostasAvaliacoesArr[$id_av]['texto_resposta'] ?? null;
            ?>
            <div class="glass-card review-card">
                <div class="review-header">
                    <img src="<?= htmlspecialchars($fotoCliente) ?>" alt="Foto do cliente" width="60" height="60" loading="lazy" decoding="async">
                    <div>
                        <strong style="display:block; font-size: 1.15rem; font-family: var(--font-heading);"><?= htmlspecialchars($nomeCliente) ?></strong>
                        <span class="rating-stars" style="font-size:1rem;">
                            <?= str_repeat('★', (int)$avaliacao['rating']) . str_repeat('☆', 5 - (int)$avaliacao['rating']) ?>
                        </span>
                    </div>
                </div>
                <p class="review-comment">"<?= htmlspecialchars($avaliacao['comment']) ?>"</p>

                <?php if ($resposta): ?>
                <div style="margin-top: 20px; padding: 15px; background: rgba(0, 0, 0, 0.15); border-left: 4px solid var(--app-accent); border-radius: 0 12px 12px 0;">
                    <span style="display: block; font-size: 0.85rem; font-weight: 800; color: var(--app-accent); margin-bottom: 6px; text-transform: uppercase;"><i class="fas fa-reply"></i> Resposta da Barbearia</span>
                    <span style="font-size: 1rem; color: #cbd5e1; font-style: italic; line-height: 1.6;">"<?= htmlspecialchars($resposta) ?>"</span>
                </div>
                <?php endif; ?>

                <div style="color:var(--app-accent); font-size: 0.9rem; margin-top: 20px; font-weight: 700;">
                    <i class="fas fa-cut"></i> Atendido por <?= htmlspecialchars($nomeBarbeiro) ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="glass-card text-center" style="max-width: 600px; margin: 0 auto;">
                <i class="fas fa-comment-slash" style="font-size: 4rem; color: rgba(255,255,255,0.1); margin-bottom: 20px;"></i>
                <p style="color:#94a3b8; font-size: 1.2rem; line-height: 1.6;">Ainda não temos avaliações destacadas. Venha nos visitar e seja o primeiro a deixar o seu feedback!</p>
            </div>
        <?php endif; ?>
    </section>

    <!-- CTA FINAL -->
    <section class="cta-final reveal">
        <h2><?= htmlspecialchars($landingConfig['cta_title'] ?? 'Pronto para o Próximo Nível?') ?></h2>
        <p><?= htmlspecialchars($landingConfig['cta_text'] ?? 'Sua cadeira está esperando por você. Transforme seu visual e sua confiança hoje mesmo.') ?></p>
        <a href="agendamento" class="btn-agendar" style="animation: none;"><i class="fas fa-calendar-check"></i> <?= htmlspecialchars($landingConfig['cta_button'] ?? 'Agendar Meu Horário') ?></a>
    </section>

    <!-- SEÇÃO: CONTATO -->
    <section id="local" class="content-section reveal">
        <h2 class="section-title"><i class="fas fa-map-marker-alt" style="color:var(--app-accent);"></i> <?= htmlspecialchars($landingConfig['contact_title'] ?? 'Localização e Contato') ?></h2>
        <div class="info-grid">
            <div class="glass-card">
                <h3 style="margin-top:0; color:var(--app-accent); font-size: 1.6rem; font-family: var(--font-heading); margin-bottom: 25px;">Fale Conosco</h3>
                <ul class="info-list">
                    <li><i class="fas fa-map-pin"></i> <?= htmlspecialchars($configGeral['endereco'] ?? 'Endereço não configurado') ?></li>
                    <li><i class="fas fa-phone-alt"></i> <?= htmlspecialchars($configGeral['telefone_contato'] ?? 'Telefone não configurado') ?></li>
                    <?php if (!empty($whatsappNumero)): ?>
                        <li><i class="fab fa-whatsapp"></i> <a href="https://wa.me/<?= htmlspecialchars($whatsappNumero) ?>" target="_blank" style="color:#22c55e; text-decoration:none; font-weight: 700;">Chamar no WhatsApp</a></li>
                    <?php endif; ?>
                </ul>

                <div class="social-links">
                    <?php if (!empty($configGeral['link_instagram'])): ?><a href="<?= htmlspecialchars($configGeral['link_instagram']) ?>" target="_blank" class="social-btn"><i class="fab fa-instagram"></i></a><?php endif; ?>
                    <?php if (!empty($configGeral['link_facebook'])): ?><a href="<?= htmlspecialchars($configGeral['link_facebook']) ?>" target="_blank" class="social-btn"><i class="fab fa-facebook-f"></i></a><?php endif; ?>
                </div>

                <h3 style="margin-top:50px; color:var(--app-accent); font-size: 1.6rem; font-family: var(--font-heading); margin-bottom: 25px;">Horário de Funcionamento</h3>
                <ul class="info-list">
                    <?php if(empty($horariosPorDia)): ?>
                        <li><span style="color: #64748b;">Verifique a disponibilidade na página de agendamento.</span></li>
                    <?php else: ?>
                        <?php foreach($horariosPorDia as $horario => $dias):
                            sort($dias);
                            $dia_str = '';
                            if(count($dias) > 1) {
                                $is_sequential = true;
                                for($i=0; $i<count($dias)-1; $i++) {
                                    if($dias[$i+1] - $dias[$i] != 1) $is_sequential = false;
                                }
                                if ($is_sequential) {
                                    $dia_str = $dias_semana_texto[$dias[0]] . ' a ' . $dias_semana_texto[end($dias)];
                                } else {
                                    $nomes_dias = array_map(function($d) use ($dias_semana_texto) { return $dias_semana_texto[$d]; }, $dias);
                                    $dia_str = implode(', ', $nomes_dias);
                                }
                            } else {
                                $dia_str = $dias_semana_texto[$dias[0]];
                            }
                        ?>
                        <li style="border-bottom: 1px dashed rgba(255,255,255,0.1); padding-bottom: 10px; margin-bottom: 10px;">
                            <strong style="color:#e2e8f0; font-weight:600;"><?= $dia_str ?></strong>
                            <span style="float: right; font-weight:700; color:var(--app-accent);"><?= $horario ?></span>
                        </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="glass-card" style="padding: 0; overflow: hidden; display: flex; flex-direction: column;">
                <div style="padding: 30px; background: rgba(0,0,0,0.2); border-bottom: 1px solid var(--glass-border);">
                    <h3 style="margin:0; color:var(--app-accent); font-size: 1.6rem; font-family: var(--font-heading);">Como chegar</h3>
                </div>
                <?php
                $mapQuery = !empty($configGeral['endereco']) ? urlencode($configGeral['endereco']) : 'Brasil';
                if (!empty($configGeral['geofence_lat']) && !empty($configGeral['geofence_lon'])) {
                    $mapQuery = htmlspecialchars($configGeral['geofence_lat']) . ',' . htmlspecialchars($configGeral['geofence_lon']);
                }
                ?>

                <div id="map-container" style="border-radius: 0; border: none; flex-grow: 1;">
                    <iframe
                        width="100%"
                        height="100%"
                        frameborder="0"
                        style="border:0;"
                        allowfullscreen=""
                        aria-hidden="false"
                        tabindex="0"
                        src="https://maps.google.com/maps?q=<?= $mapQuery ?>&hl=pt-BR&z=16&output=embed">
                    </iframe>
                </div>
            </div>
        </div>
    </section>

    <footer class="site-footer">
        <div class="legal-links">
            <a href="#" onclick="openLegalModal('modal-termos'); return false;">Termos de Uso</a> •
            <a href="#" onclick="openLegalModal('modal-privacidade'); return false;">Política de Privacidade</a> •
            <a href="cliente">Minha Conta</a> •
            <a href="admin.php">Painel Admin</a>
            <p style="color: #475569; font-size: 0.9rem; margin-top: 20px;">© <?= date("Y") ?> <?= htmlspecialchars($configGeral['nome_barbearia'] ?? 'Nossa Barbearia') ?> – Todos os direitos reservados.</p>
        </div>
    </footer>

    <!-- MODAIS LEGAIS -->
    <div id="modal-termos" class="legal-modal">
        <div class="legal-modal-content">
            <button class="close-btn" style="position: absolute; top: 25px; right: 25px;" onclick="closeLegalModal('modal-termos')"><i class="fas fa-times"></i></button>
            <h2 style="margin-top: 0; margin-bottom: 25px; font-family: var(--font-heading); font-size: 2.2rem; color: var(--app-accent);">Termos de Uso</h2>
            <div class="legal-text"><?= nl2br($landingConfig['terms_of_use'] ?? 'Termos de Uso não definidos.') ?></div>
        </div>
    </div>

    <div id="modal-privacidade" class="legal-modal">
        <div class="legal-modal-content">
            <button class="close-btn" style="position: absolute; top: 25px; right: 25px;" onclick="closeLegalModal('modal-privacidade')"><i class="fas fa-times"></i></button>
            <h2 style="margin-top: 0; margin-bottom: 25px; font-family: var(--font-heading); font-size: 2.2rem; color: var(--app-accent);">Política de Privacidade</h2>
             <div class="legal-text"><?= nl2br($landingConfig['privacy_policy'] ?? 'Política de Privacidade não definida.') ?></div>
        </div>
    </div>

<?php include __DIR__ . '/partials/index_script.php'; ?>
    <?php include 'chatbot_widget.php'; ?>
</body>
</html>
