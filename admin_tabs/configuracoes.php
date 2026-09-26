<?php
// admin_tabs/configuracoes.php

$configStripe = function_exists('_lerConfigSQLite') ? _lerConfigSQLite('config_stripe', ['secret_key' => '', 'portal_url' => '', 'webhook_secret' => '']) : ['secret_key' => '', 'portal_url' => '', 'webhook_secret' => ''];
$configChatbot = function_exists('carregarConfigChatbot') ? carregarConfigChatbot() : ['ativo'=>1,'modo_ia'=>0,'provedor_ordem'=>'groq','groq_keys'=>'','groq_modelo'=>'openai/gpt-oss-20b','gemini_keys'=>'','gemini_modelo'=>'gemini-flash-lite-latest','saudacao'=>''];

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$webhook_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . str_replace("/admin.php", "", $_SERVER['PHP_SELF']) . "/webhook_stripe.php";

$csrf_token = generate_csrf_token();

// Estado do banco (aba Backup & Limpeza)
$dbPathCfg = defined('SQLITE_DB_PATH') ? SQLITE_DB_PATH : (DATA_DIR . 'database.sqlite');
$dbTamanho = file_exists($dbPathCfg) ? filesize($dbPathCfg) : 0;
$dbTamanhoFmt = $dbTamanho >= 1048576 ? number_format($dbTamanho / 1048576, 2, ',', '.') . ' MB' : number_format($dbTamanho / 1024, 1, ',', '.') . ' KB';

$backupInfo = function_exists('_lerConfigSQLite') ? _lerConfigSQLite('backup_info', ['ultimo_backup' => '', 'ultimo_tipo' => '']) : ['ultimo_backup' => ''];
$ultimoBackup = $backupInfo['ultimo_backup'] ?? '';

$contarTabela = function ($t) {
    try {
        $r = getDB()->query("SELECT COUNT(*) FROM " . preg_replace('/[^a-zA-Z0-9_]/', '', $t));
        return $r ? (int)$r->fetchColumn() : 0;
    } catch (Exception $e) { return 0; }
};
$cont = [
    'agendamentos' => $contarTabela('agendamentos'),
    'clientes'     => $contarTabela('clientes'),
    'barbeiros'    => $contarTabela('barbeiros'),
    'servicos'     => $contarTabela('servicos') + $contarTabela('combos') + $contarTabela('planos'),
    'avaliacoes'   => $contarTabela('avaliacoes'),
    'produtos'     => $contarTabela('produtos'),
    'financeiro'   => $contarTabela('despesas') + $contarTabela('comissoes_pagas'),
    'marketing'    => $contarTabela('cupoes') + $contarTabela('vouchers'),
    'notificacoes' => $contarTabela('notificacoes'),
    'kardex'       => $contarTabela('estoque_logs'),
];

// Estado dos segredos (não são pré-preenchidos por segurança)
$temSenhaEmail    = !empty($configEmail['password']);
$temStripeSecret  = !empty($configStripe['secret_key']);
$temStripeWebhook = !empty($configStripe['webhook_secret']);

// Fusos horários oferecidos (identificadores válidos do PHP)
$fusosDisponiveis = [
    'America/Sao_Paulo'  => 'Brasília / São Paulo (GMT-3)',
    'America/Bahia'      => 'Salvador / Bahia (GMT-3)',
    'America/Fortaleza'  => 'Fortaleza / Nordeste (GMT-3)',
    'America/Recife'     => 'Recife (GMT-3)',
    'America/Belem'      => 'Belém (GMT-3)',
    'America/Cuiaba'     => 'Cuiabá (GMT-4)',
    'America/Manaus'     => 'Manaus / Amazonas (GMT-4)',
    'America/Porto_Velho'=> 'Porto Velho (GMT-4)',
    'America/Rio_Branco' => 'Rio Branco / Acre (GMT-5)',
    'America/Noronha'    => 'Fernando de Noronha (GMT-2)',
    'Europe/Lisbon'      => 'Portugal / Lisboa (GMT+0/+1)',
    'UTC'                => 'UTC (GMT+0)',
];
$fusoAtual = $configGeral['fuso_horario'] ?? 'America/Sao_Paulo';
if (!isset($fusosDisponiveis[$fusoAtual])) { $fusoAtual = 'America/Sao_Paulo'; }
?>

<style>
    /* ===== Central de Configurações — layout coeso (sidebar + conteúdo) ===== */
    .cfg-shell { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 24px; align-items: start; animation: fadeIn 0.4s ease-out; }

    .cfg-sidebar { position: sticky; top: 20px; background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 10px; display: flex; flex-direction: column; gap: 2px; }
    .cfg-nav-item {
        display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 10px;
        background: transparent; border: none; text-align: left; cursor: pointer; color: #475569;
        font: inherit; font-weight: 600; transition: .15s; width: 100%;
    }
    .cfg-nav-item .ico { width: 34px; height: 34px; flex: 0 0 34px; border-radius: 9px; display: flex; align-items: center; justify-content: center; background: #f1f5f9; color: #64748b; font-size: .95rem; transition: .15s; }
    .cfg-nav-item .txt { display: flex; flex-direction: column; line-height: 1.25; min-width: 0; }
    .cfg-nav-item .txt small { color: #94a3b8; font-weight: 500; font-size: .72rem; }
    .cfg-nav-item:hover { background: #f8fafc; color: #1e293b; }
    .cfg-nav-item.active { background: color-mix(in srgb, var(--secondary-color, #007bff) 10%, white); color: #0f172a; }
    .cfg-nav-item.active .ico { background: var(--secondary-color, #007bff); color: #fff; }
    .cfg-nav-item.danger.active { background: #fef2f2; }
    .cfg-nav-item.danger.active .ico { background: #ef4444; }

    .cfg-main { min-width: 0; }
    .cfg-panel { display: none; animation: fadeIn 0.3s ease-out; }
    .cfg-panel.active { display: block; }

    .cfg-panel-head { margin-bottom: 18px; }
    .cfg-panel-head h3 { margin: 0; font-size: 1.35rem; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 10px; }
    .cfg-panel-head p { margin: 6px 0 0; color: #64748b; font-size: .92rem; }

    .cfg-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 24px; margin-bottom: 18px; }
    .cfg-card-head { display: flex; align-items: center; gap: 10px; margin: 0 0 18px; padding-bottom: 14px; border-bottom: 1px solid #f1f5f9; }
    .cfg-card-head h4 { margin: 0; color: #1e293b; font-size: 1.05rem; font-weight: 800; }
    .cfg-card-head i { color: var(--secondary-color, #007bff); }
    .cfg-card-head .head-action { margin-left: auto; }

    .cfg-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
    .cfg-grid .full { grid-column: 1 / -1; }
    .cfg-field { display: flex; flex-direction: column; gap: 6px; }
    .cfg-field > label { font-weight: 700; color: #334155; font-size: .88rem; }
    .cfg-field .hint { color: #94a3b8; font-size: .78rem; }
    .cfg-field .modern-input, .cfg-field .modern-select, .cfg-field textarea.modern-input { width: 100%; }

    /* Switch/toggle */
    .cfg-switch-row { display: flex; align-items: center; justify-content: space-between; gap: 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; }
    .cfg-switch-row .lbl strong { display: block; color: #1e293b; font-weight: 700; font-size: .92rem; }
    .cfg-switch-row .lbl small { color: #94a3b8; font-size: .78rem; }
    .switch { position: relative; display: inline-block; width: 46px; height: 26px; flex: 0 0 46px; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .switch .slider { position: absolute; inset: 0; background: #cbd5e1; border-radius: 30px; transition: .2s; cursor: pointer; }
    .switch .slider::before { content: ""; position: absolute; height: 20px; width: 20px; left: 3px; top: 3px; background: #fff; border-radius: 50%; transition: .2s; box-shadow: 0 1px 3px rgba(0,0,0,.2); }
    .switch input:checked + .slider { background: var(--secondary-color, #007bff); }
    .switch input:checked + .slider::before { transform: translateX(20px); }

    /* Campo secreto com olho */
    .secret-field { position: relative; display: flex; }
    .secret-field .modern-input { padding-right: 44px !important; }
    .secret-field .toggle-secret { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #94a3b8; cursor: pointer; width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; }
    .secret-field .toggle-secret:hover { background: #f1f5f9; color: #475569; }
    .secret-status { display: inline-flex; align-items: center; gap: 6px; font-size: .76rem; font-weight: 700; padding: 2px 9px; border-radius: 20px; margin-left: 8px; }
    .secret-status.ok { background: #ecfdf5; color: #047857; }
    .secret-status.none { background: #fef3c7; color: #b45309; }

    /* Barra de salvar fixa por formulário */
    .cfg-savebar { position: sticky; bottom: 0; display: flex; align-items: center; gap: 14px; background: #ffffffee; backdrop-filter: blur(6px); border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px 16px; margin-top: 4px; box-shadow: 0 -4px 12px rgba(15,23,42,.04); }
    .cfg-savebar .dirty-flag { display: none; align-items: center; gap: 7px; color: #b45309; font-weight: 700; font-size: .85rem; }
    .cfg-savebar.is-dirty .dirty-flag { display: inline-flex; }
    .cfg-btn-save { margin-left: auto; background: var(--secondary-color, #007bff); color: #fff; border: none; padding: 12px 26px; border-radius: 10px; font-weight: 700; font-size: .98rem; cursor: pointer; transition: .2s; display: inline-flex; align-items: center; gap: 9px; }
    .cfg-btn-save:hover { filter: brightness(.95); transform: translateY(-1px); }

    .btn-outline { background: transparent; border: 2px solid #e2e8f0; color: #475569; padding: 9px 16px; border-radius: 10px; font-weight: 600; cursor: pointer; transition: 0.2s; }
    .btn-outline:hover { background: #f1f5f9; border-color: #cbd5e1; color: #1e293b; }

    .cfg-info { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; color: #475569; font-size: .9rem; line-height: 1.5; margin-bottom: 18px; }
    .cfg-info i { color: var(--secondary-color, #007bff); }

    /* Teste de e-mail / IA */
    .cfg-test-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
    .cfg-test-row .cfg-field { flex: 1; min-width: 220px; }
    .cfg-test-btn { background: #0ea5e9; color: #fff; border: none; padding: 12px 20px; border-radius: 10px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; min-height: 46px; }
    .cfg-test-btn:hover { filter: brightness(.95); }
    .cfg-test-btn:disabled { opacity: .6; cursor: default; }
    .cfg-test-result { margin-top: 14px; }
    .cfg-test-result .ok  { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; border-radius: 10px; padding: 12px 14px; }
    .cfg-test-result .err { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 10px; padding: 12px 14px; }
    .cfg-test-result code { background: rgba(0,0,0,.06); padding: 1px 6px; border-radius: 5px; font-size: .82rem; word-break: break-all; }

    /* Zona de perigo / limpeza */
    .danger-checkbox { display: flex; align-items: center; gap: 10px; background: white; padding: 15px; border-radius: 8px; border: 1px solid #fca5a5; cursor: pointer; transition: 0.2s; }
    .danger-checkbox:hover { background: #fef2f2; }
    .danger-checkbox .limpar-count { margin-left: auto; background: #fee2e2; color: #991b1b; font-weight: 800; font-size: .78rem; padding: 2px 9px; border-radius: 20px; }
    .danger-checkbox.is-vazio { opacity: .5; cursor: not-allowed; }
    .danger-checkbox.is-vazio .limpar-count { background: #f1f5f9; color: #94a3b8; }

    .db-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; }
    .db-stat { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; }
    .db-stat small { display: block; color: #64748b; font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
    .db-stat strong { display: block; margin-top: 4px; color: #0f172a; font-size: 1.35rem; font-weight: 800; }

    .role-list article { display: flex; align-items: flex-start; gap: 14px; padding: 14px; border: 1px solid #e5e7eb; border-radius: 12px; margin-bottom: 10px; }
    .role-list article > span { width: 40px; height: 40px; border-radius: 10px; background: #f1f5f9; color: var(--secondary-color, #007bff); display: flex; align-items: center; justify-content: center; flex: 0 0 40px; }
    .role-list article strong { color: #1e293b; }
    .role-list article p { margin: 3px 0 4px; color: #64748b; font-size: .86rem; }
    .role-list article small { color: #94a3b8; font-weight: 700; font-size: .74rem; }

    #modal-tutorial-stripe p, #modal-tutorial-stripe li { line-height: 1.6; }
    #modal-tutorial-stripe a, #modal-tutorial-gemini a { text-decoration: none; font-weight: bold; color: #635bff; }
    #modal-tutorial-stripe a:hover { text-decoration: underline; }

    @media (max-width: 900px) {
        .cfg-shell { grid-template-columns: 1fr; }
        .cfg-sidebar { position: static; flex-direction: row; overflow-x: auto; }
        .cfg-nav-item { flex-direction: column; text-align: center; min-width: 96px; gap: 6px; padding: 10px 8px; }
        .cfg-nav-item .txt small { display: none; }
        .cfg-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="config-layout" id="config-wrapper">

    <div class="section-header-bar">
        <div class="section-header-info">
            <h3><i class="fa fa-cogs"></i> Configurações do Sistema</h3>
            <p>Tudo o que controla a sua barbearia, organizado por área.</p>
        </div>
    </div>

<?php
    // Painel de configuração ativo definido pelo servidor (evita "piscar" em
    // "Informações Gerais" antes do JS trocar, ao voltar de um salvamento com ?subtab=).
    $cfgMapa = [
        'geral' => 'config-geral', 'agendamento' => 'config-agendamento', 'pagamento' => 'config-pagamento',
        'gemini' => 'config-gemini', 'ia' => 'config-gemini', 'email' => 'config-email',
        'seguranca' => 'config-seguranca', 'sistema' => 'config-seguranca', 'dados' => 'config-dados',
    ];
    $cfgSubtab = $_GET['subtab'] ?? '';
    $cfgAtivoId = $cfgMapa[$cfgSubtab] ?? 'config-geral';
    $cfgAtivo = function ($id) use ($cfgAtivoId) { return $cfgAtivoId === $id ? 'active' : ''; };
?>
    <div class="cfg-shell">
        <!-- ===== SIDEBAR ===== -->
        <aside class="cfg-sidebar" id="cfg-sidebar">
            <button type="button" class="cfg-nav-item <?= $cfgAtivo('config-geral') ?>" data-config="config-geral">
                <span class="ico"><i class="fa fa-store"></i></span>
                <span class="txt">Informações Gerais<small>Marca, contato e fuso</small></span>
            </button>
            <button type="button" class="cfg-nav-item <?= $cfgAtivo('config-agendamento') ?>" data-config="config-agendamento">
                <span class="ico"><i class="fa fa-calendar-check"></i></span>
                <span class="txt">Agendamento<small>Regras e avisos</small></span>
            </button>
            <button type="button" class="cfg-nav-item <?= $cfgAtivo('config-pagamento') ?>" data-config="config-pagamento">
                <span class="ico"><i class="fa fa-credit-card"></i></span>
                <span class="txt">Pagamentos<small>Stripe / assinaturas</small></span>
            </button>
            <button type="button" class="cfg-nav-item <?= $cfgAtivo('config-gemini') ?>" data-config="config-gemini">
                <span class="ico"><i class="fa fa-robot"></i></span>
                <span class="txt">Inteligência Artificial<small>Chatbot e chaves</small></span>
            </button>
            <button type="button" class="cfg-nav-item <?= $cfgAtivo('config-email') ?>" data-config="config-email">
                <span class="ico"><i class="fa fa-envelope"></i></span>
                <span class="txt">E-mail (SMTP)<small>Envio e teste</small></span>
            </button>
            <button type="button" class="cfg-nav-item <?= $cfgAtivo('config-seguranca') ?>" data-config="config-seguranca">
                <span class="ico"><i class="fa fa-shield-alt"></i></span>
                <span class="txt">Segurança & Usuários<small>Acessos do painel</small></span>
            </button>
            <button type="button" class="cfg-nav-item danger <?= $cfgAtivo('config-dados') ?>" data-config="config-dados">
                <span class="ico"><i class="fa fa-database"></i></span>
                <span class="txt">Backup & Limpeza<small>Dados do sistema</small></span>
            </button>
        </aside>

        <!-- ===== CONTEÚDO ===== -->
        <div class="cfg-main">

            <!-- ===== GERAL ===== -->
            <section id="config-geral" class="cfg-panel <?= $cfgAtivo('config-geral') ?>">
                <div class="cfg-panel-head">
                    <h3><i class="fa fa-store"></i> Informações Gerais</h3>
                    <p>Identidade da barbearia, canais de contato e preferências regionais.</p>
                </div>
                <form method="POST" action="admin.php" enctype="multipart/form-data" data-cfg-form>
                    <input type="hidden" name="action" value="salvar_config_geral">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

                    <div class="cfg-card">
                        <div class="cfg-card-head"><i class="fa fa-id-card"></i><h4>Dados da Barbearia</h4></div>
                        <div class="cfg-grid">
                            <div class="cfg-field"><label>Nome da Barbearia</label><input type="text" name="nome_barbearia" class="modern-input" value="<?= htmlspecialchars($configGeral['nome_barbearia'] ?? 'Minha Barbearia') ?>" required></div>
                            <div class="cfg-field"><label>Telefone de Contato</label><input type="text" name="telefone_contato" class="modern-input" value="<?= htmlspecialchars($configGeral['telefone_contato'] ?? '') ?>"></div>
                            <div class="cfg-field"><label>E-mail de Contato</label><input type="email" name="email_contato" class="modern-input" value="<?= htmlspecialchars($configGeral['email_contato'] ?? '') ?>" placeholder="contato@suabarbearia.com"><span class="hint">Usado como responder-para (Reply-To) nos e-mails e destino padrão do teste de SMTP.</span></div>
                            <div class="cfg-field"><label>Fuso Horário</label>
                                <select name="fuso_horario" class="modern-select">
                                    <?php foreach ($fusosDisponiveis as $tzId => $tzLabel): ?>
                                        <option value="<?= htmlspecialchars($tzId) ?>" <?= $tzId === $fusoAtual ? 'selected' : '' ?>><?= htmlspecialchars($tzLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="hint">Afeta horários de agendamentos, relatórios e registros em todo o sistema.</span>
                            </div>
                            <div class="cfg-field full"><label>Endereço Completo</label><input type="text" name="endereco" class="modern-input" value="<?= htmlspecialchars($configGeral['endereco'] ?? '') ?>"></div>
                        </div>
                    </div>

                    <div class="cfg-card">
                        <div class="cfg-card-head"><i class="fa fa-share-nodes"></i><h4>Redes Sociais & WhatsApp</h4></div>
                        <div class="cfg-grid">
                            <div class="cfg-field"><label>WhatsApp (número)</label><input type="text" name="whatsapp_numero" class="modern-input" value="<?= htmlspecialchars($configGeral['whatsapp_numero'] ?? '') ?>" placeholder="5511999998888"><span class="hint">Com DDI e DDD, só números. Gera o link do WhatsApp automaticamente se o campo abaixo ficar vazio.</span></div>
                            <div class="cfg-field"><label>Link WhatsApp (URL)</label><input type="url" name="link_whatsapp" class="modern-input" value="<?= htmlspecialchars($configGeral['link_whatsapp'] ?? '') ?>" placeholder="https://wa.me/55..."></div>
                            <div class="cfg-field"><label>Link Instagram (URL)</label><input type="url" name="link_instagram" class="modern-input" value="<?= htmlspecialchars($configGeral['link_instagram'] ?? '') ?>"></div>
                            <div class="cfg-field"><label>Link Facebook (URL)</label><input type="url" name="link_facebook" class="modern-input" value="<?= htmlspecialchars($configGeral['link_facebook'] ?? '') ?>"></div>
                        </div>
                    </div>

                    <div class="cfg-card">
                        <div class="cfg-card-head"><i class="fa fa-image"></i><h4>Identidade Visual</h4></div>
                        <div class="cfg-grid">
                            <div class="cfg-field">
                                <label>Logotipo Atual</label>
                                <?php if (!empty($configGeral['logo_path'])): ?>
                                    <div style="background: #f8fafc; padding: 12px; border-radius: 10px; border: 1px solid #e2e8f0; display: inline-block; margin-bottom: 8px;"><img src="<?= $configGeral['logo_path'] ?>" style="max-height: 70px;"></div>
                                <?php endif; ?>
                                <input type="file" name="logo" class="modern-input" accept="image/*">
                                <span class="hint">Formatos aceitos: PNG, JPG, WebP (máx. 2MB).</span>
                            </div>
                            <div class="cfg-field">
                                <label>Formato da Logo no Cabeçalho</label>
                                <select name="header_logo_format" class="modern-select">
                                    <option value="auto" <?= ($configGeral['header_logo_format'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Detectar automaticamente</option>
                                    <option value="compacta" <?= ($configGeral['header_logo_format'] ?? '') === 'compacta' ? 'selected' : '' ?>>Logo compacta ou quadrada</option>
                                    <option value="horizontal" <?= ($configGeral['header_logo_format'] ?? '') === 'horizontal' ? 'selected' : '' ?>>Logo horizontal</option>
                                </select>
                                <span class="hint">O modo automático preserva o formato original.</span>
                            </div>
                            <div class="cfg-field full">
                                <label>Slogan Curto</label>
                                <input type="text" name="header_slogan" class="modern-input" maxlength="80" value="<?= htmlspecialchars($configGeral['header_slogan'] ?? '') ?>" placeholder="Ex.: Tradição, estilo e cuidado">
                                <span class="hint">Exibido nas páginas de login, cadastro e recuperação de senha.</span>
                            </div>
                        </div>
                    </div>

                    <div class="cfg-savebar">
                        <span class="dirty-flag"><i class="fa fa-circle-exclamation"></i> Alterações não salvas</span>
                        <button type="submit" class="cfg-btn-save"><i class="fa fa-save"></i> Salvar Informações Gerais</button>
                    </div>
                </form>
            </section>

            <!-- ===== AGENDAMENTO ===== -->
            <section id="config-agendamento" class="cfg-panel <?= $cfgAtivo('config-agendamento') ?>">
                <div class="cfg-panel-head">
                    <h3><i class="fa fa-calendar-check"></i> Regras de Agendamento</h3>
                    <p>Limites de marcação e avisos automáticos por e-mail.</p>
                </div>
                <form method="POST" action="admin.php" data-cfg-form>
                    <input type="hidden" name="action" value="salvar_config_agendamento">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

                    <div class="cfg-card">
                        <div class="cfg-card-head"><i class="fa fa-tasks"></i><h4>Fluxo de Trabalho</h4></div>
                        <div class="cfg-grid">
                            <div class="cfg-field">
                                <label>Antecedência Mínima para Agendar (minutos)</label>
                                <input type="number" name="antecedencia_minima_minutos" class="modern-input" value="<?= $configAgendamento['antecedencia_minima_minutos'] ?? 30 ?>" min="0">
                                <span class="hint">Tempo mínimo antes do horário para permitir marcação.</span>
                            </div>
                            <div class="cfg-field">
                                <label>Antecedência Máxima para Agendar (dias)</label>
                                <input type="number" name="antecedencia_maxima" class="modern-input" value="<?= $configAgendamento['antecedencia_maxima'] ?? 30 ?>" min="1">
                            </div>
                            <div class="cfg-field full">
                                <label>Limite Máximo de Serviços por Agendamento</label>
                                <input type="number" name="max_servicos" class="modern-input" value="<?= $configAgendamento['max_servicos'] ?? 4 ?>" min="1">
                            </div>
                        </div>
                    </div>

                    <div class="cfg-card">
                        <div class="cfg-card-head"><i class="fa fa-bell"></i><h4>Notificações Automáticas</h4></div>
                        <div class="cfg-grid">
                            <label class="cfg-switch-row">
                                <span class="lbl"><strong>Aviso de Recebimento</strong><small>E-mail quando um pedido chega</small></span>
                                <span class="switch"><input type="checkbox" name="notif_confirmacao" value="1" <?= ($configAgendamento['notif_confirmacao'] ?? 0) == 1 ? 'checked' : '' ?>><span class="slider"></span></span>
                            </label>
                            <label class="cfg-switch-row">
                                <span class="lbl"><strong>Aviso de Cancelamento</strong><small>E-mail ao cancelar/recusar</small></span>
                                <span class="switch"><input type="checkbox" name="notif_aprovacao" value="1" <?= ($configAgendamento['notif_aprovacao'] ?? 0) == 1 ? 'checked' : '' ?>><span class="slider"></span></span>
                            </label>
                            <label class="cfg-switch-row full">
                                <span class="lbl"><strong>Lembrete de Horário</strong><small>E-mail lembrando o cliente do atendimento</small></span>
                                <span class="switch"><input type="checkbox" name="notif_lembrete" value="1" <?= ($configAgendamento['notif_lembrete'] ?? 0) == 1 ? 'checked' : '' ?>><span class="slider"></span></span>
                            </label>
                        </div>
                    </div>

                    <div class="cfg-savebar">
                        <span class="dirty-flag"><i class="fa fa-circle-exclamation"></i> Alterações não salvas</span>
                        <button type="submit" class="cfg-btn-save"><i class="fa fa-save"></i> Salvar Regras de Agendamento</button>
                    </div>
                </form>
            </section>

            <!-- ===== PAGAMENTOS ===== -->
            <section id="config-pagamento" class="cfg-panel <?= $cfgAtivo('config-pagamento') ?>">
                <div class="cfg-panel-head">
                    <h3><i class="fa fa-credit-card"></i> Pagamentos</h3>
                    <p>Integração com a Stripe para a Barbearia por Assinatura.</p>
                </div>
                <form method="POST" action="admin.php" data-cfg-form>
                    <input type="hidden" name="action" value="salvar_config_pagamentos">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

                    <div class="cfg-card">
                        <div class="cfg-card-head">
                            <i class="fab fa-stripe" style="color:#635bff; font-size:1.4rem;"></i><h4>Integração Stripe</h4>
                            <div class="head-action">
                                <button type="button" class="btn-outline" onclick="abrirTutorialStripe()" style="background:#f3f0ff; color:#635bff; border-color:#d8b4fe;"><i class="fa fa-book-open"></i> Ver Tutorial</button>
                            </div>
                        </div>
                        <div class="cfg-info"><i class="fa fa-info-circle"></i> O <strong>Stripe</strong> gerencia com segurança as cobranças mensais automáticas via cartão de crédito. Campos secretos ficam ocultos — deixe em branco para manter o valor atual.</div>

                        <div class="cfg-grid">
                            <div class="cfg-field full">
                                <label>Secret Key (Chave Secreta)
                                    <span class="secret-status <?= $temStripeSecret ? 'ok' : 'none' ?>"><i class="fa <?= $temStripeSecret ? 'fa-check' : 'fa-triangle-exclamation' ?>"></i> <?= $temStripeSecret ? 'Configurada' : 'Não configurada' ?></span>
                                </label>
                                <div class="secret-field">
                                    <input type="password" name="stripe_secret_key" class="modern-input" value="" autocomplete="new-password" placeholder="<?= $temStripeSecret ? '•••••••••• (deixe em branco para manter)' : 'sk_live_...' ?>">
                                    <button type="button" class="toggle-secret" title="Mostrar/ocultar"><i class="fa fa-eye"></i></button>
                                </div>
                                <span class="hint">Necessária para criar as sessões de checkout na API da Stripe.</span>
                            </div>
                            <div class="cfg-field full">
                                <label>Webhook Signing Secret
                                    <span class="secret-status <?= $temStripeWebhook ? 'ok' : 'none' ?>"><i class="fa <?= $temStripeWebhook ? 'fa-check' : 'fa-triangle-exclamation' ?>"></i> <?= $temStripeWebhook ? 'Configurado' : 'Não configurado' ?></span>
                                </label>
                                <div class="secret-field">
                                    <input type="password" name="stripe_webhook_secret" class="modern-input" value="" autocomplete="new-password" placeholder="<?= $temStripeWebhook ? '•••••••••• (deixe em branco para manter)' : 'whsec_...' ?>">
                                    <button type="button" class="toggle-secret" title="Mostrar/ocultar"><i class="fa fa-eye"></i></button>
                                </div>
                                <span class="hint">Código <code>whsec_...</code> gerado ao criar o webhook na Stripe (confirma que os eventos são legítimos). Não sabe onde achar? Clique em <strong>Ver Tutorial</strong> &rarr; passo 2.</span>
                            </div>
                            <div class="cfg-field full">
                                <label>Link do Portal do Cliente</label>
                                <input type="url" name="stripe_portal_url" class="modern-input" value="<?= htmlspecialchars($configStripe['portal_url'] ?? '') ?>" placeholder="https://billing.stripe.com/p/login/...">
                                <span class="hint">Gere em <strong>Configurações &gt; Portal do Cliente</strong>. Aparece no painel "Minha Conta" do assinante.</span>
                            </div>
                        </div>
                    </div>

                    <div class="cfg-savebar">
                        <span class="dirty-flag"><i class="fa fa-circle-exclamation"></i> Alterações não salvas</span>
                        <button type="submit" class="cfg-btn-save"><i class="fa fa-save"></i> Salvar Configuração do Stripe</button>
                    </div>
                </form>
            </section>

            <!-- ===== IA ===== -->
            <section id="config-gemini" class="cfg-panel <?= $cfgAtivo('config-gemini') ?>">
                <div class="cfg-panel-head">
                    <h3><i class="fa fa-robot"></i> Inteligência Artificial</h3>
                    <p>Assistente/chatbot do site com reforço opcional por IA (Groq).</p>
                </div>
                <form method="POST" action="admin.php" data-cfg-form>
                    <input type="hidden" name="action" value="salvar_config_chatbot">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <div class="cfg-card">
                        <div class="cfg-card-head">
                            <i class="fa fa-robot" style="color:#f55036;"></i><h4>Assistente / Chatbot (Groq)</h4>
                            <div class="head-action">
                                <button type="button" class="btn-outline" onclick="abrirTutorialGemini()" style="background:#fff1ee; color:#f55036; border-color:#fbb;"><i class="fa fa-book-open"></i> Onde acho minha chave?</button>
                            </div>
                        </div>
                        <div class="cfg-info"><i class="fa fa-info-circle" style="color:#6366f1;"></i> O chatbot funciona <strong>sem IA</strong> (por regras, sem limites). A IA (via <strong>Groq</strong>) é <strong>opcional</strong> e reforça perguntas livres — com <strong>rodízio</strong> entre várias chaves e <strong>fallback para o Gemini</strong> quando a Groq atinge o limite.</div>

                        <div class="cfg-grid">
                            <div class="cfg-field">
                                <label>Exibir chatbot no site</label>
                                <select name="cb_ativo" class="modern-select">
                                    <option value="1" <?= !empty($configChatbot['ativo']) ? 'selected' : '' ?>>🟢 Ativado</option>
                                    <option value="0" <?= empty($configChatbot['ativo']) ? 'selected' : '' ?>>🔴 Desativado</option>
                                </select>
                            </div>
                            <div class="cfg-field">
                                <label>Reforço por IA (perguntas livres)</label>
                                <select name="cb_modo_ia" class="modern-select">
                                    <option value="0" <?= empty($configChatbot['modo_ia']) ? 'selected' : '' ?>>Somente regras (sem IA)</option>
                                    <option value="1" <?= !empty($configChatbot['modo_ia']) ? 'selected' : '' ?>>Usar IA como reforço</option>
                                </select>
                            </div>
                            <div class="cfg-field">
                                <label>Modo agente (IA executa ações)</label>
                                <select name="cb_modo_agente" class="modern-select">
                                    <option value="1" <?= !empty($configChatbot['modo_agente']) ? 'selected' : '' ?>>🤖 Agente completo (agenda, cancela, remarca…)</option>
                                    <option value="0" <?= empty($configChatbot['modo_agente']) ? 'selected' : '' ?>>💬 Só responder (não executa ações)</option>
                                </select>
                                <span class="hint">Requer a IA ligada. No modo agente, o cliente resolve tudo por conversa.</span>
                            </div>
                            <div class="cfg-field">
                                <label>Exigir login de visitantes</label>
                                <select name="cb_exigir_login" class="modern-select">
                                    <option value="0" <?= empty($configChatbot['exigir_login_visitante']) ? 'selected' : '' ?>>Não — qualquer um pode conversar</option>
                                    <option value="1" <?= !empty($configChatbot['exigir_login_visitante']) ? 'selected' : '' ?>>Sim — pedir login antes de conversar</option>
                                </select>
                                <span class="hint">Se ligado, visitantes sem conta são convidados a entrar antes de digitar.</span>
                            </div>
                            <div class="cfg-field full">
                                <label>Modelo da Groq</label>
                                <input type="text" name="cb_groq_modelo" class="modern-input" value="<?= htmlspecialchars($configChatbot['groq_modelo'] ?? 'openai/gpt-oss-20b') ?>" placeholder="openai/gpt-oss-20b">
                            </div>
                            <div class="cfg-field full">
                                <label>Chaves da Groq <span class="hint" style="display:inline;">(uma por linha, para rodízio)</span></label>
                                <textarea name="cb_groq_keys" class="modern-input" rows="3" placeholder="gsk_... (uma por linha)"><?= htmlspecialchars($configChatbot['groq_keys'] ?? '') ?></textarea>
                                <span class="hint">Gere chaves gratuitas em console.groq.com. Adicione várias para não esgotar o limite.</span>
                            </div>
                            <div class="cfg-field">
                                <label>Modelo do Gemini <span class="hint" style="display:inline;">(fallback)</span></label>
                                <input type="text" name="cb_gemini_modelo" class="modern-input" value="<?= htmlspecialchars($configChatbot['gemini_modelo'] ?? 'gemini-flash-lite-latest') ?>" placeholder="gemini-flash-lite-latest">
                                <span class="hint">Grátis: <code>gemini-flash-lite-latest</code> ou <code>gemini-flash-latest</code>. Com plano pago você pode usar um modelo mais forte (ex.: <code>gemini-pro-latest</code>) — basta a chave ter acesso.</span>
                            </div>
                            <div class="cfg-field full">
                                <label>Chaves do Gemini <span class="hint" style="display:inline;">(uma por linha, para rodízio — fallback quando a Groq atinge o limite)</span></label>
                                <textarea name="cb_gemini_keys" class="modern-input" rows="2" placeholder="AIza... (uma por linha, opcional)"><?= htmlspecialchars($configChatbot['gemini_keys'] ?? '') ?></textarea>
                                <span class="hint">Opcional, mas recomendado: quando a Groq limita a taxa (429/TPM), o assistente continua pelo Gemini. Chave gratuita em aistudio.google.com/apikey. Se deixar em branco, reaproveita a chave Gemini já salva no sistema.</span>
                            </div>
                            <div class="cfg-field full">
                                <label>Mensagem inicial do chatbot (opcional)</label>
                                <input type="text" name="cb_saudacao" class="modern-input" value="<?= htmlspecialchars($configChatbot['saudacao'] ?? '') ?>" placeholder="Ex.: Olá! Como posso ajudar você hoje?">
                            </div>
                        </div>
                    </div>

                    <div class="cfg-savebar">
                        <span class="dirty-flag"><i class="fa fa-circle-exclamation"></i> Alterações não salvas</span>
                        <button type="submit" class="cfg-btn-save" style="background:#6366f1;"><i class="fa fa-save"></i> Salvar Assistente/Chatbot</button>
                    </div>
                </form>

                <div class="cfg-card">
                    <div class="cfg-card-head"><i class="fa fa-stethoscope"></i><h4>Diagnóstico das chaves de IA</h4></div>
                    <p style="margin:0 0 12px; color:#475569; font-size:.9rem;">Testa cada chave configurada e mostra o motivo exato de qualquer falha. <strong>Salve as chaves antes de testar.</strong></p>
                    <button type="button" id="btn-testar-ia" class="cfg-test-btn"><i class="fa fa-vial"></i> Testar chaves agora</button>
                    <div id="ia-diag-resultado" style="margin-top:14px;"></div>
                </div>
            </section>

            <!-- ===== E-MAIL ===== -->
            <section id="config-email" class="cfg-panel <?= $cfgAtivo('config-email') ?>">
                <div class="cfg-panel-head">
                    <h3><i class="fa fa-envelope"></i> E-mail (SMTP)</h3>
                    <p>Servidor de envio dos e-mails do sistema — e teste em um clique.</p>
                </div>
                <form method="POST" action="admin.php" id="form-email" data-cfg-form>
                    <input type="hidden" name="action" value="salvar_config_email">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <div class="cfg-card">
                        <div class="cfg-card-head"><i class="fa fa-server"></i><h4>Servidor SMTP</h4></div>
                        <div class="cfg-grid">
                            <div class="cfg-field"><label>Nome do Remetente</label><input type="text" name="nome_remetente" class="modern-input" value="<?= htmlspecialchars($configEmail['nome_remetente'] ?? '') ?>" placeholder="Sua Barbearia"></div>
                            <div class="cfg-field"><label>Servidor SMTP (Host)</label><input type="text" name="host" class="modern-input" value="<?= htmlspecialchars($configEmail['host'] ?? '') ?>" placeholder="ex: smtp.gmail.com"></div>
                            <div class="cfg-field"><label>Usuário SMTP (E-mail)</label><input type="email" name="username" class="modern-input" value="<?= htmlspecialchars($configEmail['username'] ?? '') ?>" placeholder="seu-email@dominio.com"></div>
                            <div class="cfg-field">
                                <label>Senha SMTP (ou Senha de App)
                                    <span class="secret-status <?= $temSenhaEmail ? 'ok' : 'none' ?>"><i class="fa <?= $temSenhaEmail ? 'fa-check' : 'fa-triangle-exclamation' ?>"></i> <?= $temSenhaEmail ? 'Configurada' : 'Não configurada' ?></span>
                                </label>
                                <div class="secret-field">
                                    <input type="password" name="password" class="modern-input" value="" autocomplete="new-password" placeholder="<?= $temSenhaEmail ? '•••••••••• (deixe em branco para manter)' : '••••••••' ?>">
                                    <button type="button" class="toggle-secret" title="Mostrar/ocultar"><i class="fa fa-eye"></i></button>
                                </div>
                            </div>
                            <div class="cfg-field"><label>Porta SMTP</label><input type="number" name="port" class="modern-input" value="<?= htmlspecialchars($configEmail['port'] ?? '587') ?>"></div>
                            <div class="cfg-field">
                                <label>Segurança (Criptografia)</label>
                                <select name="smtp_secure" class="modern-select">
                                    <option value="" <?= empty($configEmail['smtp_secure']) ? 'selected' : '' ?>>Nenhuma</option>
                                    <option value="tls" <?= ($configEmail['smtp_secure'] ?? '') === 'tls' ? 'selected' : '' ?>>TLS</option>
                                    <option value="ssl" <?= ($configEmail['smtp_secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="cfg-card">
                        <div class="cfg-card-head"><i class="fa fa-paper-plane"></i><h4>Enviar E-mail de Teste</h4></div>
                        <div class="cfg-info"><i class="fa fa-info-circle"></i> Envia um e-mail real usando os dados <strong>preenchidos acima</strong> (mesmo antes de salvar). Se a senha estiver em branco, usamos a já salva.</div>
                        <div class="cfg-test-row">
                            <div class="cfg-field">
                                <label>Enviar teste para</label>
                                <input type="email" id="email-teste-destino" class="modern-input" value="<?= htmlspecialchars($configGeral['email_contato'] ?? $configEmail['username'] ?? '') ?>" placeholder="destino@exemplo.com">
                            </div>
                            <button type="button" id="btn-testar-email" class="cfg-test-btn"><i class="fa fa-paper-plane"></i> Enviar teste</button>
                        </div>
                        <div id="email-teste-resultado" class="cfg-test-result"></div>
                    </div>

                    <div class="cfg-savebar">
                        <span class="dirty-flag"><i class="fa fa-circle-exclamation"></i> Alterações não salvas</span>
                        <button type="submit" class="cfg-btn-save"><i class="fa fa-save"></i> Salvar Configurações de E-mail</button>
                    </div>
                </form>
            </section>

            <!-- ===== SEGURANÇA ===== -->
            <section id="config-seguranca" class="cfg-panel <?= $cfgAtivo('config-seguranca') ?>">
                <div class="cfg-panel-head">
                    <h3><i class="fa fa-shield-alt"></i> Segurança & Usuários</h3>
                    <p>Contas do painel administrativo e seus níveis de acesso.</p>
                </div>
                <div class="cfg-card">
                    <div class="cfg-card-head">
                        <i class="fa fa-user-shield"></i><h4>Usuários do Painel Admin</h4>
                        <div class="head-action">
                            <button class="btn-inline-add" data-modal-target="#modal-usuario" style="background:#1e293b; color:white; border:none; padding:10px 15px; border-radius:8px; cursor:pointer;"><i class="fa fa-user-plus"></i> Novo Admin</button>
                        </div>
                    </div>
                    <div class="modern-table-wrapper">
                        <table class="modern-table">
                            <thead><tr><th>Nome de Usuário</th><th>Perfil</th><th style="text-align: right;">Ações</th></tr></thead>
                            <tbody>
                                <?php foreach ($usersArr as $username => $u): ?>
                                    <tr>
                                        <td style="font-weight: 700; color: #1e293b;"><?= htmlspecialchars($username) ?></td>
                                        <td><span style="display:inline-flex; padding:4px 7px; border-radius:5px; color:#475569; background:#f1f5f9; font-size:.72rem; font-weight:700;"><?= htmlspecialchars(obterPerfisAdmin()[$u['role'] ?? 'proprietario']['nome'] ?? 'Proprietário') ?></span></td>
                                        <td style="text-align: right;">
                                            <div class="action-buttons" style="justify-content: flex-end; display: flex; gap: 8px;">
                                                <button style="background:#334155; color:white; border:none; padding:8px 12px; border-radius:6px; cursor:pointer;" data-modal-target="#modal-usuario" data-id="<?= htmlspecialchars($username) ?>" data-role="<?= htmlspecialchars($u['role'] ?? 'proprietario') ?>" data-type="usuario" title="Editar usuário"><i class="fa fa-key"></i></button>
                                                <a href="?action=excluir_usuario&username=<?= urlencode($username) ?>&csrf_token=<?= $csrf_token ?>" style="background:#ef4444; color:white; text-decoration:none; padding:8px 12px; border-radius:6px;" onclick="return confirm('Excluir este usuário administrador?')"><i class="fa fa-trash"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="cfg-card">
                    <div class="cfg-card-head"><i class="fa fa-shield-halved"></i><h4>Níveis de Acesso</h4></div>
                    <div class="role-list">
                        <?php foreach (obterPerfisAdmin() as $roleId => $roleInfo): ?>
                            <article>
                                <span><i class="fa <?= $roleId === 'proprietario' ? 'fa-crown' : ($roleId === 'financeiro' ? 'fa-coins' : ($roleId === 'recepcao' ? 'fa-headset' : 'fa-user-tie')) ?>"></i></span>
                                <div>
                                    <strong><?= htmlspecialchars($roleInfo['nome']) ?></strong>
                                    <p><?= htmlspecialchars($roleInfo['descricao']) ?></p>
                                    <small><?= in_array('*', $roleInfo['tabs'], true) ? 'Todas as áreas' : count($roleInfo['tabs']) . ' áreas autorizadas' ?></small>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <!-- ===== BACKUP & LIMPEZA ===== -->
            <section id="config-dados" class="cfg-panel <?= $cfgAtivo('config-dados') ?>">
                <div class="cfg-panel-head">
                    <h3><i class="fa fa-database"></i> Backup & Limpeza</h3>
                    <p>Cópias de segurança, otimização e remoção de dados.</p>
                </div>

                <div class="cfg-card">
                    <div class="cfg-card-head"><i class="fa fa-server"></i><h4>Estado do Banco de Dados</h4></div>
                    <div class="db-stats-grid">
                        <div class="db-stat"><small>Tamanho do banco</small><strong><?= $dbTamanhoFmt ?></strong></div>
                        <div class="db-stat"><small>Agendamentos</small><strong><?= number_format($cont['agendamentos'], 0, ',', '.') ?></strong></div>
                        <div class="db-stat"><small>Clientes</small><strong><?= number_format($cont['clientes'], 0, ',', '.') ?></strong></div>
                        <div class="db-stat"><small>Avaliações</small><strong><?= number_format($cont['avaliacoes'], 0, ',', '.') ?></strong></div>
                        <div class="db-stat"><small>Último backup</small><strong style="font-size:1rem;"><?= $ultimoBackup ? date('d/m/Y H:i', strtotime($ultimoBackup)) : '—' ?></strong></div>
                    </div>
                    <?php if (!$ultimoBackup): ?>
                        <p style="color:#b45309; font-weight:600; margin:14px 0 0; font-size:.9rem;"><i class="fa fa-triangle-exclamation"></i> Você ainda não fez nenhum backup. Recomendamos baixar um agora.</p>
                    <?php endif; ?>
                </div>

                <div class="cfg-card">
                    <div class="cfg-card-head"><i class="fa fa-download"></i><h4>Backup do Sistema</h4></div>
                    <p style="color:#64748b; margin-bottom:16px; line-height:1.5;">Baixe uma cópia de segurança dos seus dados. Guarde em local seguro (nuvem/pen drive).</p>
                    <div style="display:flex; gap:12px; flex-wrap:wrap;">
                        <a href="?action=backup_dados&csrf_token=<?= $csrf_token ?>" class="cfg-btn-save" style="background:#10b981; margin:0; text-decoration:none;"><i class="fa fa-file-archive"></i> Backup Completo (.zip)</a>
                        <a href="?action=backup_sqlite&csrf_token=<?= $csrf_token ?>" class="cfg-btn-save" style="background:#0ea5e9; margin:0; text-decoration:none;"><i class="fa fa-database"></i> Somente o Banco (.sqlite)</a>
                    </div>
                    <p style="color:#94a3b8; margin:14px 0 0; font-size:.82rem;">O ".zip" inclui todos os arquivos (banco + uploads). O ".sqlite" é mais leve e restaura o banco diretamente.</p>
                </div>

                <div class="cfg-card">
                    <div class="cfg-card-head"><i class="fa fa-broom"></i><h4>Manutenção</h4></div>
                    <p style="color:#64748b; margin-bottom:16px; line-height:1.5;">Compacta o banco de dados, recuperando espaço após exclusões. Seguro e recomendado periodicamente.</p>
                    <a href="?action=otimizar_banco&csrf_token=<?= $csrf_token ?>" class="cfg-btn-save" style="background:#6366f1; margin:0; text-decoration:none;" onclick="return confirm('Otimizar (compactar) o banco de dados agora?');"><i class="fa fa-wand-magic-sparkles"></i> Otimizar Banco (VACUUM)</a>
                </div>

                <div class="cfg-card" style="border-color:#fed7aa;">
                    <div class="cfg-card-head" style="border-bottom-color:#fed7aa;"><i class="fa fa-calendar-minus" style="color:#b45309;"></i><h4 style="color:#b45309;">Limpeza por Período (recomendada)</h4></div>
                    <p style="color:#64748b; margin-bottom:16px; line-height:1.5;">Remove apenas <strong>agendamentos já finalizados</strong> (concluídos/cancelados) anteriores à data escolhida — desafoga o banco sem perder o histórico recente. Preserva clientes, financeiro e tudo mais.</p>
                    <form method="POST" action="admin.php" style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;" onsubmit="return confirm('Remover agendamentos finalizados anteriores à data selecionada?');">
                        <input type="hidden" name="action" value="limpar_por_periodo">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <label style="color:#475569; font-weight:600;">Apagar finalizados antes de:</label>
                        <input type="date" name="data_limite" value="<?= date('Y-m-d', strtotime('-1 year')) ?>" max="<?= date('Y-m-d', strtotime('-1 day')) ?>" class="modern-input" style="max-width:190px;" required>
                        <button type="submit" class="cfg-btn-save" style="background:#f59e0b; margin:0;"><i class="fa fa-eraser"></i> Limpar período</button>
                    </form>
                </div>

                <div class="cfg-card" style="border-color:#fca5a5; background:#fef2f2;">
                    <div class="cfg-card-head" style="border-bottom-color:#fecaca;"><i class="fa fa-exclamation-triangle" style="color:#991b1b;"></i><h4 style="color:#991b1b;">Limpeza de Banco de Dados</h4></div>
                    <p style="color:#991b1b; font-weight:600; margin-bottom:15px;">Zona de Perigo: esta ação é irreversível. Faça um backup acima antes de continuar.</p>

                    <form method="POST" action="admin.php" onsubmit="return confirm('Tem certeza ABSOLUTA? Os dados marcados serão apagados permanentemente e não poderão ser desfeitos!');">
                        <input type="hidden" name="action" value="limpar_dados">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <?php
                        $limparItens = [
                            'agendamentos' => 'Todos os Agendamentos',
                            'clientes'     => 'Todos os Clientes & Assinaturas',
                            'barbeiros'    => 'Todos os Barbeiros & Horários',
                            'servicos'     => 'Serviços, Combos e Categorias',
                            'avaliacoes'   => 'Todas as Avaliações',
                            'produtos'     => 'Produtos em Estoque',
                            'financeiro'   => 'Financeiro (Despesas & Comissões)',
                            'marketing'    => 'Marketing (Cupons & Vouchers)',
                            'notificacoes' => 'Notificações de Clientes',
                            'kardex'       => 'Histórico de Movimentações (Kardex)',
                        ];
                        ?>
                        <div class="cfg-grid" style="margin-bottom:22px;">
                            <?php foreach ($limparItens as $val => $label):
                                $qtdItem = (int)($cont[$val] ?? 0);
                            ?>
                                <label class="danger-checkbox <?= $qtdItem === 0 ? 'is-vazio' : '' ?>">
                                    <input type="checkbox" name="limpar[]" value="<?= $val ?>" style="width:20px; height:20px; accent-color:#ef4444;" <?= $qtdItem === 0 ? 'disabled' : '' ?>>
                                    <span style="color:#7f1d1d; font-weight:600;"><?= $label ?></span>
                                    <span class="limpar-count"><?= number_format($qtdItem, 0, ',', '.') ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div style="margin-bottom:20px; padding:15px; background:white; border-radius:8px; border:1px dashed #fca5a5;">
                            <label style="color:#991b1b; font-weight:800; font-size:1rem;">Para confirmar, digite <span style="text-decoration:underline;">EXCLUIR</span> no campo abaixo:</label>
                            <input type="text" name="confirmacao_exclusao" class="modern-input" required placeholder="Digite EXCLUIR para habilitar o botão" style="border-color:#fca5a5; max-width:350px; text-transform:uppercase; margin-top:8px;" oninput="document.getElementById('btn-limpar-dados').disabled = (this.value.trim().toUpperCase() !== 'EXCLUIR');">
                        </div>

                        <button type="submit" id="btn-limpar-dados" class="cfg-btn-save" style="background:#ef4444; margin:0;" disabled><i class="fa fa-trash-alt"></i> Limpar Dados Selecionados Permanentemente</button>
                    </form>
                </div>
            </section>

        </div>
    </div>
</div>

<div id="modal-tutorial-stripe" class="modal-overlay" style="display: none; align-items: center; justify-content: center; z-index: 9999; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(15,23,42,0.8);">
    <div class="modal-content" style="background: white; border-radius: 16px; max-width: 700px; width: 90%; max-height: 90vh; overflow-y: auto; position: relative; padding: 35px;">
        <button onclick="fecharTutorialStripe()" style="background: #f1f5f9; border: none; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; position: absolute; top: 15px; right: 15px; cursor: pointer; color: #64748b; font-size: 1.2rem;">&times;</button>
        <div>
            <h3 style="color: #1e293b; margin-top: 0; display: flex; align-items: center; gap: 10px; font-size: 1.4rem;"><i class="fab fa-stripe" style="color: #635bff; font-size: 2rem;"></i> Configurando a Stripe</h3>
            <p style="color: #475569; font-size: 1rem;">Siga os 3 passos simples para conectar as cobranças automáticas da sua barbearia.</p>
            <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;">
            <div style="display: flex; flex-direction: column; gap: 20px;">
                <div style="background: #f8fafc; padding: 20px; border-radius: 12px; border-left: 4px solid #635bff;">
                    <h4 style="margin: 0 0 10px; color: #0f172a;">1. Obter sua Chave Secreta</h4>
                    <ul style="margin: 0; padding-left: 20px; color: #475569; font-size: 0.95rem; line-height: 1.6;">
                        <li>Acesse o Dashboard da Stripe: <a href="https://dashboard.stripe.com/apikeys" target="_blank">Minhas Chaves de API</a>.</li>
                        <li>Certifique-se de que o <strong>Modo de Teste está DESATIVADO</strong> (canto superior direito).</li>
                        <li>Copie o código da <strong>Chave secreta (Secret key)</strong> (geralmente começa com <code>sk_live_...</code>).</li>
                        <li>Cole-a no campo correspondente nas configurações da barbearia.</li>
                    </ul>
                </div>
                <div style="background: #fffbeb; padding: 20px; border-radius: 12px; border-left: 4px solid #f59e0b;">
                    <h4 style="margin: 0 0 10px; color: #92400e;">2. Configurar Renovação Automática (Webhook)</h4>
                    <p style="margin: 0 0 10px; color: #b45309; font-size: 0.9rem;">Essencial para o sistema saber quando a fatura foi paga e renovar os 30 dias do cliente.</p>
                    <ul style="margin: 0; padding-left: 20px; color: #92400e; font-size: 0.95rem; line-height: 1.6;">
                        <li>No Dashboard da Stripe, vá até <a href="https://dashboard.stripe.com/webhooks" target="_blank" style="color:#d97706;">Desenvolvedores > Webhooks</a> e clique em <strong>Adicionar endpoint</strong>.</li>
                        <li>Em "URL do endpoint", cole exatamente este endereço:</li>
                    </ul>
                    <div style="background: white; padding: 15px; border-radius: 8px; border: 1px dashed #fcd34d; margin-top: 15px; font-family: monospace; color: #000; word-break: break-all; text-align: center; font-weight: bold; font-size: 1rem;"><?= $webhook_url ?></div>
                    <ul style="margin: 15px 0 0; padding-left: 20px; color: #92400e; font-size: 0.95rem; line-height: 1.6;">
                        <li>Clique em <strong>Selecionar eventos</strong> e marque <strong>as 4 caixas</strong> abaixo (todas são obrigatórias):</li>
                        <li>Em Checkout: <code>checkout.session.completed</code> <span style="color:#b45309;">— ativa a assinatura após o pagamento</span></li>
                        <li>Em Invoice: <code>invoice.paid</code> <span style="color:#b45309;">— renova automaticamente todo mês</span></li>
                        <li>Em Customer &rarr; Subscription: <code>customer.subscription.updated</code> <span style="color:#b45309;">— registra o <strong>cancelamento agendado</strong> e a reativação feitos pelo cliente</span></li>
                        <li>Em Customer &rarr; Subscription: <code>customer.subscription.deleted</code> <span style="color:#b45309;">— encerra a assinatura quando o período termina</span></li>
                        <li style="list-style:none; margin-top:8px; background:#fff; border:1px dashed #fcd34d; border-radius:8px; padding:10px 12px; color:#b45309;"><i class="fa fa-triangle-exclamation"></i> Sem os dois eventos de <code>subscription</code> o status <strong>não muda para "Cancelamento agendado"</strong> quando o cliente cancela pelo Stripe.</li>
                        <li>Clique em <strong>Adicionar eventos</strong> e depois em <strong>Adicionar endpoint</strong>.</li>
                    </ul>
                    <p style="margin: 18px 0 10px; color: #92400e; font-size: 0.95rem; font-weight: 800;">É daqui que sai o "Webhook Signing Secret":</p>
                    <ul style="margin: 0; padding-left: 20px; color: #92400e; font-size: 0.95rem; line-height: 1.6;">
                        <li>Assim que o endpoint é criado, a Stripe abre a página dele. Procure o bloco <strong>"Segredo da assinatura"</strong> (em inglês, <em>Signing secret</em>).</li>
                        <li>Clique em <strong>Revelar</strong> e copie o código — ele sempre começa com <code>whsec_...</code>.</li>
                        <li>Cole esse código no campo <strong>"Webhook Signing Secret"</strong> aqui do painel e clique em <strong>Salvar Configuração do Stripe</strong>.</li>
                        <li style="list-style:none; margin-top:8px; background:#fff; border:1px dashed #fcd34d; border-radius:8px; padding:10px 12px; color:#b45309;"><i class="fa fa-lightbulb"></i> Perdeu a página? Volte em <strong>Webhooks</strong>, clique no endpoint que você criou e o "Signing secret" estará lá para revelar de novo.</li>
                    </ul>
                </div>
                <div style="background: #f0fdf4; padding: 20px; border-radius: 12px; border-left: 4px solid #10b981;">
                    <h4 style="margin: 0 0 10px; color: #064e3b;">3. Portal do Cliente (Cancelamento)</h4>
                    <ul style="margin: 0; padding-left: 20px; color: #065f46; font-size: 0.95rem; line-height: 1.6;">
                        <li>Para que o cliente consiga trocar o cartão ou cancelar a assinatura, a Stripe tem uma tela pronta.</li>
                        <li>Vá até <a href="https://dashboard.stripe.com/settings/billing/portal" target="_blank" style="color: #10b981;">Configurações > Portal do Cliente</a>.</li>
                        <li>Clique em <strong>Ativar portal</strong>, permita cancelamentos/trocas de cartão, copie o link gerado e cole-o no painel.</li>
                    </ul>
                </div>
            </div>
            <div style="text-align: center; margin-top: 30px;">
                <button onclick="fecharTutorialStripe()" class="cfg-btn-save" style="margin:0; padding: 14px 40px; border-radius: 50px; background: #635bff;"><i class="fa fa-check"></i> Entendi, fechar tutorial</button>
            </div>
        </div>
    </div>
</div>

<div id="modal-tutorial-gemini" class="modal-overlay" style="display: none; align-items: center; justify-content: center; z-index: 9999; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(15,23,42,0.8);">
    <div class="modal-content" style="background: white; border-radius: 16px; max-width: 700px; width: 90%; max-height: 90vh; overflow-y: auto; position: relative; padding: 35px;">
        <button onclick="fecharTutorialGemini()" style="background: #f1f5f9; border: none; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; position: absolute; top: 15px; right: 15px; cursor: pointer; color: #64748b; font-size: 1.2rem;">&times;</button>
        <div>
            <h3 style="color: #1e293b; margin-top: 0; display: flex; align-items: center; gap: 10px; font-size: 1.4rem;"><i class="fa fa-robot" style="color: #f55036; font-size: 2rem;"></i> Obtendo a Chave da Groq</h3>
            <p style="color: #475569; font-size: 1rem;">Siga os passos abaixo para gerar sua API Key gratuita da Groq.</p>
            <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;">
            <div style="display: flex; flex-direction: column; gap: 20px;">
                <div style="background: #f8fafc; padding: 20px; border-radius: 12px; border-left: 4px solid #f55036;">
                    <h4 style="margin: 0 0 10px; color: #0f172a;">1. Acesse o Console da Groq</h4>
                    <ul style="margin: 0; padding-left: 20px; color: #475569; font-size: 0.95rem; line-height: 1.6;">
                        <li>Abra o site: <a href="https://console.groq.com/keys" target="_blank" style="color: #f55036; font-weight: bold;">console.groq.com/keys</a>.</li>
                        <li>Crie sua conta ou faça login (pode usar Google/GitHub).</li>
                    </ul>
                </div>
                <div style="background: #fffbeb; padding: 20px; border-radius: 12px; border-left: 4px solid #f59e0b;">
                    <h4 style="margin: 0 0 10px; color: #92400e;">2. Crie uma API Key</h4>
                    <ul style="margin: 0; padding-left: 20px; color: #92400e; font-size: 0.95rem; line-height: 1.6;">
                        <li>Clique em <strong>"Create API Key"</strong>.</li>
                        <li>Dê um nome (ex.: "Barbearia") e confirme.</li>
                        <li>Copie a chave <strong>na hora</strong> — ela só é exibida uma vez.</li>
                    </ul>
                </div>
                <div style="background: #f0fdf4; padding: 20px; border-radius: 12px; border-left: 4px solid #10b981;">
                    <h4 style="margin: 0 0 10px; color: #064e3b;">3. Copie e Cole no Sistema</h4>
                    <ul style="margin: 0; padding-left: 20px; color: #065f46; font-size: 0.95rem; line-height: 1.6;">
                        <li>A chave começa com <code style="background: #dcfce7; padding: 2px 5px; border-radius: 4px;">gsk_...</code>.</li>
                        <li>Cole no campo <strong>"Chaves da Groq"</strong> nesta página (uma por linha, se tiver várias).</li>
                    </ul>
                </div>
            </div>
            <div style="text-align: center; margin-top: 30px;">
                <button onclick="fecharTutorialGemini()" class="cfg-btn-save" style="margin:0; padding: 14px 40px; border-radius: 50px; background: #f55036;"><i class="fa fa-check"></i> Entendi, fechar tutorial</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // ===== Navegação sidebar =====
    var navItems = document.querySelectorAll('#cfg-sidebar .cfg-nav-item');
    var panels = document.querySelectorAll('.cfg-panel');

    function ativar(targetId) {
        navItems.forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-config') === targetId); });
        panels.forEach(function (p) { p.classList.toggle('active', p.id === targetId); });
        if (window.innerWidth < 900) {
            var alvo = document.getElementById(targetId);
            if (alvo) alvo.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    navItems.forEach(function (btn) {
        btn.addEventListener('click', function () {
            // Guarda de alterações não salvas ao trocar de aba.
            var painelAtivo = document.querySelector('.cfg-panel.active');
            if (painelAtivo && painelAtivo.querySelector('form[data-cfg-form][data-dirty="1"]')) {
                if (!confirm('Você tem alterações não salvas nesta aba. Deseja sair mesmo assim?')) return;
                // Descarta o estado "sujo" ao confirmar a saída.
                painelAtivo.querySelectorAll('form[data-cfg-form]').forEach(limparDirty);
            }
            ativar(btn.getAttribute('data-config'));
        });
    });

    // Deep-link via ?subtab= já é resolvido no servidor (o painel correto já vem
    // com a classe "active"), evitando o "piscar" em "Informações Gerais" antes
    // de trocar. Aqui só garantimos que a barra lateral role até o item ativo.
    (function () {
        var ativo = document.querySelector('#cfg-sidebar .cfg-nav-item.active');
        if (ativo && ativo.scrollIntoView) ativo.scrollIntoView({ block: 'nearest' });
    })();

    // ===== Alterações não salvas =====
    var algoSujo = false;
    function marcarDirty(form) {
        form.setAttribute('data-dirty', '1');
        var bar = form.querySelector('.cfg-savebar');
        if (bar) bar.classList.add('is-dirty');
        algoSujo = true;
    }
    function limparDirty(form) {
        form.removeAttribute('data-dirty');
        var bar = form.querySelector('.cfg-savebar');
        if (bar) bar.classList.remove('is-dirty');
    }
    document.querySelectorAll('form[data-cfg-form]').forEach(function (form) {
        form.addEventListener('input', function () { marcarDirty(form); });
        form.addEventListener('change', function () { marcarDirty(form); });
        form.addEventListener('submit', function () { algoSujo = false; limparDirty(form); });
    });
    window.addEventListener('beforeunload', function (e) {
        if (algoSujo) { e.preventDefault(); e.returnValue = ''; }
    });

    // ===== Mostrar/ocultar segredos =====
    document.querySelectorAll('.toggle-secret').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = btn.parentElement.querySelector('input');
            var icon = btn.querySelector('i');
            if (!input) return;
            if (input.type === 'password') { input.type = 'text'; icon.className = 'fa fa-eye-slash'; }
            else { input.type = 'password'; icon.className = 'fa fa-eye'; }
        });
    });

    // ===== Teste de e-mail =====
    var btnEmail = document.getElementById('btn-testar-email');
    if (btnEmail) {
        btnEmail.addEventListener('click', function () {
            var form = document.getElementById('form-email');
            var box = document.getElementById('email-teste-resultado');
            var destino = (document.getElementById('email-teste-destino') || {}).value || '';
            var val = function (n) { var el = form.querySelector('[name="' + n + '"]'); return el ? el.value : ''; };
            var original = btnEmail.innerHTML;
            btnEmail.disabled = true; btnEmail.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Enviando...';
            box.innerHTML = '';
            fetch('ajax_config.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
                body: JSON.stringify({
                    action: 'testar_email', destino: destino,
                    host: val('host'), username: val('username'), password: val('password'),
                    port: val('port'), secure: val('smtp_secure'), nome_remetente: val('nome_remetente')
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                btnEmail.disabled = false; btnEmail.innerHTML = original;
                if (d.success) {
                    box.innerHTML = '<div class="ok"><i class="fa fa-circle-check"></i> ' + (d.mensagem || 'E-mail enviado.') + '</div>';
                } else {
                    var extra = d.detalhe ? '<br><small>Detalhe SMTP: <code>' + String(d.detalhe).replace(/</g, '&lt;') + '</code></small>' : '';
                    box.innerHTML = '<div class="err"><i class="fa fa-circle-xmark"></i> ' + (d.error || 'Falha no envio.') + extra + '</div>';
                }
            })
            .catch(function () {
                btnEmail.disabled = false; btnEmail.innerHTML = original;
                box.innerHTML = '<div class="err"><i class="fa fa-circle-xmark"></i> Falha de conexão ao testar.</div>';
            });
        });
    }

    // ===== Teste de chaves de IA =====
    var btnIA = document.getElementById('btn-testar-ia');
    if (btnIA) {
        var boxIA = document.getElementById('ia-diag-resultado');
        btnIA.addEventListener('click', function () {
            btnIA.disabled = true;
            var original = btnIA.innerHTML;
            btnIA.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Testando...';
            boxIA.innerHTML = '';
            fetch('ajax_gemini.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
                body: JSON.stringify({ action: 'testar_ia' })
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                btnIA.disabled = false; btnIA.innerHTML = original;
                if (!d.success) { boxIA.innerHTML = '<div style="color:#dc2626;">Erro: ' + (d.error || 'falha') + '</div>'; return; }
                if (!d.total) { boxIA.innerHTML = '<div style="color:#d97706;">Nenhuma chave configurada. Adicione chaves acima e salve.</div>'; return; }
                var html = '<table style="width:100%; border-collapse:collapse; font-size:.86rem;">';
                html += '<tr style="background:#f1f5f9; text-align:left;"><th style="padding:8px;">Provedor</th><th style="padding:8px;">Chave</th><th style="padding:8px;">Modelo</th><th style="padding:8px;">Status</th></tr>';
                d.resultados.forEach(function (x) {
                    var cor = x.ok ? '#16a34a' : '#dc2626';
                    var icon = x.ok ? '✅' : '❌';
                    html += '<tr style="border-bottom:1px solid #e2e8f0;">' +
                        '<td style="padding:8px; text-transform:capitalize;">' + x.provedor + '</td>' +
                        '<td style="padding:8px; font-family:monospace;">' + x.chave + '</td>' +
                        '<td style="padding:8px; font-family:monospace; font-size:.8rem;">' + x.modelo + '</td>' +
                        '<td style="padding:8px; color:' + cor + '; font-weight:600;">' + icon + ' ' + x.detalhe + '</td></tr>';
                });
                html += '</table>';
                boxIA.innerHTML = html;
            })
            .catch(function () { btnIA.disabled = false; btnIA.innerHTML = original; boxIA.innerHTML = '<div style="color:#dc2626;">Falha de conexão ao testar.</div>'; });
        });
    }
});

function abrirTutorialStripe() { document.getElementById('modal-tutorial-stripe').style.display = 'flex'; }
function fecharTutorialStripe() { document.getElementById('modal-tutorial-stripe').style.display = 'none'; }
function abrirTutorialGemini() { document.getElementById('modal-tutorial-gemini').style.display = 'flex'; }
function fecharTutorialGemini() { document.getElementById('modal-tutorial-gemini').style.display = 'none'; }
</script>
