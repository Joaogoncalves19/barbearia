<?php
if (session_status() === PHP_SESSION_NONE) {
    if (function_exists('iniciarSessaoSegura')) {
        iniciarSessaoSegura();
    } else {
        session_start();
    }
}

if (!isset($configGeral)) {
    if (file_exists('functions.php')) {
        require_once 'functions.php';
        $configGeral = carregarConfigGeral();
    } elseif (file_exists('../functions.php')) {
        require_once '../functions.php';
        $configGeral = carregarConfigGeral();
    }
}

$pageTitle = $pageTitle ?? ($configGeral['nome_barbearia'] ?? 'Barbearia');
$brandName = trim($configGeral['nome_barbearia'] ?? 'Barbearia');
$headerSlogan = trim($configGeral['header_slogan'] ?? '');
$authPageTitles = ['Acesso Restrito', 'Login', 'Cadastro', 'Recuperar Senha', 'Redefinir Senha'];
$headerVariant = $headerVariant ?? (in_array($pageTitle, $authPageTitles, true) ? 'auth' : 'app');
$isAuthHeader = $headerVariant === 'auth';

$logoPath = trim($configGeral['logo_path'] ?? '');
$logoExists = $logoPath !== '' && file_exists($logoPath);
$logoFormat = $configGeral['header_logo_format'] ?? 'auto';
if (!in_array($logoFormat, ['auto', 'compacta', 'horizontal'], true)) {
    $logoFormat = 'auto';
}
if ($logoFormat === 'auto' && $logoExists) {
    $logoSize = @getimagesize($logoPath);
    $logoFormat = $logoSize && $logoSize[1] > 0 && ($logoSize[0] / $logoSize[1]) >= 1.55
        ? 'horizontal'
        : 'compacta';
}
if ($logoFormat === 'auto') {
    $logoFormat = 'compacta';
}

$brandInitial = function_exists('mb_substr') ? mb_substr($brandName, 0, 1, 'UTF-8') : substr($brandName, 0, 1);
?>

<style>
    :root {
        --header-height: <?= $isAuthHeader ? '92px' : '78px' ?>;
        --header-text: #1e293b;
        --header-subtext: #64748b;
        --header-border: #e2e8f0;
    }

    body {
        padding-top: calc(var(--header-height) + 15px) !important;
    }

    .app-header {
        position: fixed;
        inset: 0 0 auto;
        z-index: 10000;
        width: 100%;
        height: var(--header-height);
        box-sizing: border-box;
        background: #fff;
        border-bottom: 2px solid color-mix(in srgb, var(--secondary-color, #007bff) 28%, #e2e8f0);
        box-shadow: 0 8px 26px -24px rgba(15, 23, 42, .75);
    }

    .app-header-inner {
        width: min(1320px, 100%);
        height: 100%;
        margin: 0 auto;
        padding: 0 18px;
        box-sizing: border-box;
        display: grid;
        grid-template-columns: 42px minmax(0, 1fr) 42px;
        align-items: center;
        gap: 14px;
    }

    .app-header-left,
    .app-header-right {
        display: flex;
        align-items: center;
    }

    .app-header-left { justify-content: flex-start; }
    .app-header-right { justify-content: flex-end; }

    .app-header-center {
        min-width: 0;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .header-brand-link {
        min-width: 0;
        max-width: 100%;
        display: flex;
        align-items: center;
        gap: 12px;
        color: inherit;
        text-decoration: none;
    }

    .header-logo-frame {
        width: 50px;
        height: 50px;
        padding: 5px;
        box-sizing: border-box;
        flex: 0 0 auto;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        background: #fff;
        border: 1px solid var(--header-border);
        border-radius: 8px;
        box-shadow: 0 7px 18px -16px rgba(15, 23, 42, .9);
    }

    .app-header--auth .header-logo-frame {
        width: 60px;
        height: 60px;
        padding: 6px;
    }

    .header-logo-frame.is-horizontal {
        width: 88px;
    }

    .app-header--auth .header-logo-frame.is-horizontal {
        width: 108px;
    }

    .header-logo {
        width: 100%;
        height: 100%;
        display: block;
        object-fit: contain;
    }

    .header-logo-fallback {
        color: var(--secondary-color, #007bff);
        font-size: 1.35rem;
        font-weight: 900;
        text-transform: uppercase;
    }

    .header-brand-text {
        min-width: 0;
        display: flex;
        flex-direction: column;
        justify-content: center;
        gap: 2px;
        line-height: 1.2;
        text-align: left;
    }

    .app-brand-name,
    .app-page-context,
    .app-brand-slogan {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .app-brand-name {
        color: var(--header-text);
        font-size: 1.02rem;
        font-weight: 850;
        letter-spacing: 0;
    }

    .app-header--auth .app-brand-name {
        font-size: 1.12rem;
    }

    .app-brand-slogan {
        color: var(--header-subtext);
        font-size: .73rem;
        font-weight: 550;
    }

    .app-page-context {
        color: var(--secondary-color, #007bff);
        font-size: .74rem;
        font-weight: 800;
        text-transform: uppercase;
    }

    .btn-header {
        width: 40px;
        height: 40px;
        padding: 0;
        border: 1px solid var(--header-border);
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #475569;
        background: #f8fafc;
        font-size: 1rem;
        text-decoration: none;
        cursor: pointer;
        transition: color .2s, border-color .2s, background .2s;
    }

    .btn-header:hover,
    .btn-header:focus-visible {
        color: var(--secondary-color, #007bff);
        border-color: color-mix(in srgb, var(--secondary-color, #007bff) 36%, #e2e8f0);
        background: color-mix(in srgb, var(--secondary-color, #007bff) 6%, #fff);
        outline: none;
    }

    .container { margin-top: 0 !important; }

    @media (max-width: 600px) {
        :root {
            --header-height: <?= $isAuthHeader ? '84px' : '72px' ?>;
        }

        .app-header-inner {
            padding: 0 12px;
            grid-template-columns: 38px minmax(0, 1fr) 38px;
            gap: 8px;
        }

        .btn-header {
            width: 38px;
            height: 38px;
        }

        .header-brand-link { gap: 8px; }
        .header-logo-frame { width: 44px; height: 44px; padding: 4px; }
        .app-header--auth .header-logo-frame { width: 50px; height: 50px; padding: 5px; }
        .header-logo-frame.is-horizontal { width: 64px; }
        .app-header--auth .header-logo-frame.is-horizontal { width: 72px; }
        .app-brand-name, .app-header--auth .app-brand-name { font-size: .94rem; }
        .app-brand-slogan { font-size: .68rem; }
        .app-page-context { font-size: .68rem; }
    }

    @media (max-width: 390px) {
        .app-brand-slogan { display: none; }
        .header-logo-frame.is-horizontal,
        .app-header--auth .header-logo-frame.is-horizontal { width: 60px; }
        .app-brand-name, .app-header--auth .app-brand-name { font-size: .88rem; }
        .app-page-context { font-size: .64rem; }
    }
</style>

<header class="app-header app-header--<?= htmlspecialchars($headerVariant) ?>">
    <div class="app-header-inner">
        <div class="app-header-left">
            <?php if (!empty($backLink)): ?>
                <a href="<?= htmlspecialchars($backLink) ?>" class="btn-header" title="Voltar" aria-label="Voltar">
                    <i class="fa fa-chevron-left"></i>
                </a>
            <?php else: ?>
                <button type="button" onclick="if(document.referrer.indexOf(window.location.hostname) !== -1) { history.back(); } else { window.location.href = 'index'; }" class="btn-header" title="Voltar" aria-label="Voltar">
                    <i class="fa fa-chevron-left"></i>
                </button>
            <?php endif; ?>
        </div>

        <div class="app-header-center">
            <a href="index" class="header-brand-link" title="Ir para o início" aria-label="<?= htmlspecialchars($brandName) ?>">
                <span class="header-logo-frame is-<?= htmlspecialchars($logoFormat) ?>">
                    <?php if ($logoExists): ?>
                        <img src="<?= htmlspecialchars($logoPath) ?>" alt="<?= htmlspecialchars($brandName) ?>" class="header-logo">
                    <?php else: ?>
                        <span class="header-logo-fallback"><?= htmlspecialchars($brandInitial) ?></span>
                    <?php endif; ?>
                </span>
                <span class="header-brand-text">
                    <strong class="app-brand-name"><?= htmlspecialchars($brandName) ?></strong>
                    <?php if ($isAuthHeader && $headerSlogan !== ''): ?>
                        <span class="app-brand-slogan"><?= htmlspecialchars($headerSlogan) ?></span>
                    <?php endif; ?>
                    <span class="app-page-context"><?= htmlspecialchars($pageTitle) ?></span>
                </span>
            </a>
        </div>

        <div class="app-header-right">
            <a href="index" class="btn-header" title="Início" aria-label="Ir para o início">
                <i class="fa fa-home"></i>
            </a>
        </div>
    </div>
</header>
