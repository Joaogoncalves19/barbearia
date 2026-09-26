<?php
// chatbot_widget.php — inclua este arquivo antes de </body> nas páginas públicas.
// Renderiza o botão flutuante + painel do assistente. Autocontido (CSS/JS próprios).
if (!function_exists('carregarConfigChatbot')) return;
$__cbCfg = carregarConfigChatbot();
if (empty($__cbCfg['ativo'])) return;
$__cbNome = htmlspecialchars((carregarConfigGeral()['nome_barbearia'] ?? 'Assistente'));
$__cbTheme = function_exists('_lerConfigSQLite') ? _lerConfigSQLite('theme_config', []) : [];
$__cbAccent = $__cbTheme['secondary_color'] ?? ($__cbTheme['primary_color'] ?? '#6366f1');
if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', (string)$__cbAccent)) $__cbAccent = '#6366f1';
?>
<style>
    :root {
        --cb-accent: <?= htmlspecialchars($__cbAccent) ?>;
        --cb-accent-grad: linear-gradient(135deg, <?= htmlspecialchars($__cbAccent) ?>, color-mix(in srgb, <?= htmlspecialchars($__cbAccent) ?> 65%, #000 12%));
        --cb-soft: color-mix(in srgb, var(--cb-accent) 12%, #fff);
        --cb-border: color-mix(in srgb, var(--cb-accent) 38%, #fff);
        --cb-ink: color-mix(in srgb, var(--cb-accent) 78%, #000);
    }
    /* ---- Botão flutuante ---- */
    .cb-launcher { position: fixed; right: 22px; bottom: 22px; z-index: 99998; width: 62px; height: 62px; border-radius: 50%; border: none; cursor: pointer; background: var(--cb-accent-grad); color: #fff; font-size: 1.55rem; box-shadow: 0 10px 25px -6px color-mix(in srgb, var(--cb-accent) 60%, transparent); display: flex; align-items: center; justify-content: center; transition: transform .2s; }
    .cb-launcher:hover { transform: scale(1.07); }
    .cb-launcher::after { content: ''; position: absolute; inset: 0; border-radius: 50%; border: 2px solid var(--cb-accent); opacity: .55; animation: cbPulse 2.4s ease-out infinite; }
    @keyframes cbPulse { 0% { transform: scale(1); opacity: .55; } 70% { transform: scale(1.45); opacity: 0; } 100% { opacity: 0; } }
    .cb-launcher.cb-hidepulse::after { display: none; }
    .cb-badge { position: fixed; right: 18px; bottom: 66px; z-index: 99998; min-width: 20px; height: 20px; padding: 0 5px; border-radius: 12px; background: #ef4444; color: #fff; font: 700 .7rem/20px system-ui, sans-serif; text-align: center; box-shadow: 0 3px 8px rgba(0,0,0,.25); display: none; }
    /* ---- Balão-teaser ---- */
    .cb-teaser { position: fixed; right: 92px; bottom: 30px; z-index: 99998; max-width: 230px; background: #fff; color: #1e293b; padding: 11px 32px 11px 14px; border-radius: 14px; border-bottom-right-radius: 4px; box-shadow: 0 12px 30px -10px rgba(15,23,42,.4); font: 500 .84rem/1.4 system-ui, sans-serif; display: none; animation: cbUp .3s ease-out; }
    .cb-teaser.cb-show { display: block; }
    .cb-teaser b { color: var(--cb-ink); }
    .cb-teaser-x { position: absolute; top: 4px; right: 6px; border: none; background: transparent; color: #94a3b8; cursor: pointer; font-size: .95rem; line-height: 1; }
    /* ---- Painel ---- */
    .cb-panel { position: fixed; right: 22px; bottom: 92px; z-index: 99999; width: 372px; max-width: calc(100vw - 24px); height: 540px; max-height: calc(100vh - 120px); background: #fff; border-radius: 18px; box-shadow: 0 24px 60px -18px rgba(15,23,42,.5); display: none; flex-direction: column; overflow: hidden; font-family: 'Inter', system-ui, sans-serif; }
    .cb-panel.cb-open { display: flex; animation: cbUp .22s ease-out; }
    @keyframes cbUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
    .cb-panel.cb-fullscreen { right: 0; left: 0; top: 0; bottom: 0; width: 100%; max-width: 100%; height: 100%; max-height: 100%; border-radius: 0; }
    .cb-panel.cb-fullscreen .cb-body { padding: 20px max(16px, calc((100vw - 760px) / 2)); }
    .cb-panel.cb-fullscreen .cb-options,
    .cb-panel.cb-fullscreen .cb-inputbar { padding-left: max(14px, calc((100vw - 760px) / 2)); padding-right: max(14px, calc((100vw - 760px) / 2)); }
    .cb-panel.cb-fullscreen .cb-bubble { max-width: 680px; }
    /* ---- Cabeçalho ---- */
    .cb-header { background: var(--cb-accent-grad); color: #fff; padding: 14px 16px; display: flex; align-items: center; justify-content: space-between; }
    .cb-headL { display: flex; align-items: center; gap: 11px; min-width: 0; }
    .cb-headav { width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,.22); display: flex; align-items: center; justify-content: center; font-size: 1.15rem; flex-shrink: 0; }
    .cb-header h4 { margin: 0; font-size: 1rem; font-weight: 700; display: flex; align-items: center; gap: 6px; }
    .cb-header small { opacity: .9; font-size: .72rem; }
    .cb-dot { width: 8px; height: 8px; border-radius: 50%; background: #34d399; box-shadow: 0 0 0 2px rgba(52,211,153,.35); display: inline-block; }
    .cb-header-actions { display: flex; align-items: center; gap: 7px; }
    .cb-iconbtn { background: rgba(255,255,255,.2); border: none; color: #fff; width: 30px; height: 30px; border-radius: 50%; cursor: pointer; font-size: .9rem; display: flex; align-items: center; justify-content: center; transition: background .15s; }
    .cb-iconbtn:hover { background: rgba(255,255,255,.35); }
    /* ---- Corpo / mensagens ---- */
    .cb-body { flex: 1; overflow-y: auto; padding: 16px; background: #f8fafc; display: flex; flex-direction: column; gap: 12px; }
    .cb-row { display: flex; gap: 8px; align-items: flex-end; }
    .cb-row.user { flex-direction: row-reverse; }
    .cb-av { width: 30px; height: 30px; border-radius: 50%; background: var(--cb-accent-grad); color: #fff; display: flex; align-items: center; justify-content: center; font-size: .82rem; flex-shrink: 0; }
    .cb-bubble { display: flex; flex-direction: column; max-width: 80%; }
    .cb-row.user .cb-bubble { align-items: flex-end; }
    .cb-msg { padding: 10px 13px; border-radius: 14px; font-size: .88rem; line-height: 1.5; word-wrap: break-word; }
    .cb-msg.bot { background: #fff; color: #1e293b; border: 1px solid #e2e8f0; border-bottom-left-radius: 4px; }
    .cb-msg.user { background: var(--cb-accent); color: #fff; border-bottom-right-radius: 4px; }
    .cb-time { font-size: .62rem; color: #94a3b8; margin: 3px 6px 0; }
    .cb-slot { display: inline-block; background: var(--cb-soft); color: var(--cb-ink); border: 1px solid var(--cb-border); border-radius: 6px; padding: 2px 8px; margin: 2px; font-weight: 700; font-size: .82rem; }
    .cb-options { display: flex; flex-wrap: wrap; gap: 6px; padding: 0 16px 4px; background: #f8fafc; }
    .cb-opt { background: #fff; border: 1px solid var(--cb-border); color: var(--cb-ink); border-radius: 20px; padding: 7px 13px; font-size: .82rem; font-weight: 600; cursor: pointer; transition: .15s; text-decoration: none; display: inline-block; }
    .cb-opt:hover { background: var(--cb-soft); }
    .cb-inputbar { display: flex; gap: 8px; padding: 12px 14px; border-top: 1px solid #e2e8f0; background: #fff; }
    .cb-inputbar input { flex: 1; border: 1px solid #d8e0ea; border-radius: 22px; padding: 10px 15px; outline: none; font-size: .9rem; }
    .cb-inputbar input:focus { border-color: var(--cb-accent); }
    .cb-send { background: var(--cb-accent); border: none; color: #fff; width: 42px; height: 42px; border-radius: 50%; cursor: pointer; font-size: 1rem; flex-shrink: 0; }
    .cb-typing { display: inline-flex; gap: 4px; align-items: center; background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; border-bottom-left-radius: 4px; padding: 11px 13px; width: fit-content; }
    .cb-typing span { width: 7px; height: 7px; border-radius: 50%; background: #cbd5e1; animation: cbBlink 1.2s infinite; }
    .cb-typing span:nth-child(2) { animation-delay: .2s; }
    .cb-typing span:nth-child(3) { animation-delay: .4s; }
    @keyframes cbBlink { 0%, 60%, 100% { opacity: .3; transform: translateY(0); } 30% { opacity: 1; transform: translateY(-3px); } }
    @media (max-width: 480px) { .cb-panel { right: 8px; left: 8px; width: auto; bottom: 84px; } .cb-teaser { display: none !important; } }
</style>

<button class="cb-launcher" id="cb-launcher" aria-label="Abrir assistente"><i class="fas fa-robot"></i></button>
<span class="cb-badge" id="cb-badge">1</span>
<div class="cb-teaser" id="cb-teaser">
    <button class="cb-teaser-x" id="cb-teaser-x" aria-label="Fechar">&times;</button>
    Olá! 👋 Sou o assistente da <b><?= $__cbNome ?></b>. Posso agendar, tirar dúvidas e muito mais.
</div>

<div class="cb-panel" id="cb-panel" role="dialog" aria-label="Assistente virtual">
    <div class="cb-header">
        <div class="cb-headL">
            <div class="cb-headav"><i class="fas fa-robot"></i></div>
            <div>
                <h4>Assistente <span class="cb-dot" title="Online"></span></h4>
                <small><?= $__cbNome ?></small>
            </div>
        </div>
        <div class="cb-header-actions">
            <button class="cb-iconbtn" id="cb-clear" aria-label="Limpar conversa" title="Limpar conversa"><i class="fas fa-trash-can"></i></button>
            <button class="cb-iconbtn" id="cb-expand" aria-label="Tela cheia" title="Tela cheia"><i class="fas fa-expand"></i></button>
            <button class="cb-iconbtn" id="cb-close" aria-label="Fechar" title="Fechar">&times;</button>
        </div>
    </div>
    <div class="cb-body" id="cb-body"></div>
    <div class="cb-options" id="cb-options"></div>
    <form class="cb-inputbar" id="cb-form" autocomplete="off">
        <input type="text" id="cb-input" placeholder="Digite sua mensagem..." maxlength="500">
        <button type="submit" class="cb-send" aria-label="Enviar"><i class="fas fa-paper-plane"></i></button>
    </form>
</div>

<script>
(function () {
    // Endpoint resolvido no cliente contra a URL atual (funciona com URLs sem
    // extensão, subpastas e HTTPS). 'assistente.php' (sem "chat", que o InfinityFree bloqueia com 403).
    var CB_ENDPOINT;
    try { CB_ENDPOINT = new URL('assistente.php', document.baseURI).href; }
    catch (e) { CB_ENDPOINT = 'assistente.php'; }

    var launcher = document.getElementById('cb-launcher');
    var badge = document.getElementById('cb-badge');
    var teaser = document.getElementById('cb-teaser');
    var panel = document.getElementById('cb-panel');
    var body = document.getElementById('cb-body');
    var optsBox = document.getElementById('cb-options');
    var form = document.getElementById('cb-form');
    var input = document.getElementById('cb-input');
    var expandBtn = document.getElementById('cb-expand');
    var clearBtn = document.getElementById('cb-clear');

    var iniciado = false;
    var awaitField = null, awaitPayload = null;
    var history = [];       // memória enviada à IA
    var msgs = [];          // mensagens renderizadas (para persistir)
    var lastOptions = [];   // últimos botões (para restaurar)
    var CONV_KEY = 'cb-conv-v1', CONV_TTL = 2 * 60 * 60 * 1000; // 2h

    function stripHtml(html) {
        var tmp = document.createElement('div'); tmp.innerHTML = String(html || '');
        return (tmp.textContent || tmp.innerText || '').trim();
    }
    function recordTurn(role, content) {
        content = String(content || '').trim(); if (!content) return;
        history.push({ role: role, content: content.slice(0, 600) });
        if (history.length > 20) history = history.slice(-20);
    }
    function agora() { var d = new Date(); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
    function scrollBottom() { body.scrollTop = body.scrollHeight; }

    // ---- Persistência da conversa (localStorage) ----
    function persist() {
        try { localStorage.setItem(CONV_KEY, JSON.stringify({ t: Date.now(), msgs: msgs.slice(-40), history: history, options: lastOptions, fs: panel.classList.contains('cb-fullscreen') })); } catch (e) {}
    }
    function restore() {
        try {
            var raw = localStorage.getItem(CONV_KEY); if (!raw) return false;
            var d = JSON.parse(raw);
            if (!d || !d.msgs || !d.msgs.length || (Date.now() - (d.t || 0)) > CONV_TTL) return false;
            msgs = d.msgs; history = d.history || []; lastOptions = d.options || [];
            d.msgs.forEach(function (m) { renderMsg(m.html, m.who, m.time, false); });
            renderOptions(lastOptions, false);
            return true;
        } catch (e) { return false; }
    }
    function limpar() {
        try { localStorage.removeItem(CONV_KEY); } catch (e) {}
        msgs = []; history = []; lastOptions = []; awaitField = null; awaitPayload = null;
        body.innerHTML = ''; optsBox.innerHTML = '';
        send('menu', {}, '', '', { record: true });
    }

    // ---- Tela cheia ----
    function lerFsPref() { try { return localStorage.getItem('cb-fullscreen') === '1'; } catch (e) { return false; } }
    function salvarFsPref(on) { try { localStorage.setItem('cb-fullscreen', on ? '1' : '0'); } catch (e) {} }
    function aplicarFs(on) {
        panel.classList.toggle('cb-fullscreen', !!on);
        expandBtn.innerHTML = on ? '<i class="fas fa-compress"></i>' : '<i class="fas fa-expand"></i>';
        expandBtn.setAttribute('title', on ? 'Restaurar' : 'Tela cheia');
        expandBtn.setAttribute('aria-label', on ? 'Restaurar' : 'Tela cheia');
    }
    aplicarFs(lerFsPref());
    expandBtn.addEventListener('click', function () { var on = !panel.classList.contains('cb-fullscreen'); aplicarFs(on); salvarFsPref(on); scrollBottom(); });
    clearBtn.addEventListener('click', function () { limpar(); });

    function isPwd(f) { return f === 'login_senha' || f === 'reg_senha'; }
    function setInputMode(field) {
        input.type = isPwd(field) ? 'password' : 'text';
        input.placeholder = isPwd(field) ? 'Digite sua senha...' : 'Digite sua mensagem...';
    }

    // Renderiza uma mensagem (com avatar do bot e horário). store=false ao restaurar.
    function renderMsg(html, who, time, store) {
        var row = document.createElement('div'); row.className = 'cb-row ' + who;
        if (who === 'bot') {
            var av = document.createElement('div'); av.className = 'cb-av'; av.innerHTML = '<i class="fas fa-robot"></i>';
            row.appendChild(av);
        }
        var bubble = document.createElement('div'); bubble.className = 'cb-bubble';
        var msg = document.createElement('div'); msg.className = 'cb-msg ' + who;
        if (who === 'user') { msg.textContent = html; } else { msg.innerHTML = html; }
        var t = document.createElement('div'); t.className = 'cb-time'; t.textContent = time || agora();
        bubble.appendChild(msg); bubble.appendChild(t); row.appendChild(bubble);
        body.appendChild(row); scrollBottom();
        if (store !== false) { msgs.push({ who: who, html: (who === 'user' ? html : String(html)), time: time || agora() }); persist(); }
    }
    function addMsg(html, who) { renderMsg(html, who, agora(), true); }

    function typing(on) {
        var ex = document.getElementById('cb-typing-row');
        if (on && !ex) {
            var row = document.createElement('div'); row.className = 'cb-row bot'; row.id = 'cb-typing-row';
            row.innerHTML = '<div class="cb-av"><i class="fas fa-robot"></i></div><div class="cb-typing"><span></span><span></span><span></span></div>';
            body.appendChild(row); scrollBottom();
        } else if (!on && ex) { ex.remove(); }
    }
    function renderOptions(options, store) {
        optsBox.innerHTML = '';
        (options || []).forEach(function (o) {
            var el;
            if (o.url) { el = document.createElement('a'); el.href = o.url; el.target = '_self'; el.className = 'cb-opt'; }
            else {
                el = document.createElement('button'); el.type = 'button'; el.className = 'cb-opt';
                el.addEventListener('click', function () { awaitField = null; awaitPayload = null; setInputMode(null); addMsg(o.label, 'user'); send(o.intent, o.payload || {}, '', '', { userText: o.label }); });
            }
            el.textContent = o.label; optsBox.appendChild(el);
        });
        if (store !== false) { lastOptions = options || []; persist(); }
    }

    function send(intent, payload, message, campo, opts) {
        opts = opts || {};
        var record = opts.record !== false;
        typing(true); renderOptions([]);
        var cbBody = new URLSearchParams();
        cbBody.set('intent', intent || '');
        cbBody.set('message', message || '');
        cbBody.set('campo', campo || '');
        cbBody.set('payload', JSON.stringify(payload || {}));
        cbBody.set('history', JSON.stringify(history)); // turnos anteriores; o atual vai em 'message'
        if (record && opts.userText) recordTurn('user', opts.userText);
        fetch(CB_ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            credentials: 'same-origin',
            body: cbBody.toString()
        })
        .then(function (r) {
            return r.text().then(function (t) {
                if (!r.ok) throw new Error('HTTP ' + r.status + ': ' + t.slice(0, 300));
                try { return JSON.parse(t); } catch (e) { throw new Error('Resposta inválida: ' + t.slice(0, 300)); }
            });
        })
        .then(function (data) {
            typing(false);
            addMsg(data.reply || '...', 'bot');
            if (record) recordTurn('assistant', stripHtml(data.reply || ''));
            renderOptions(data.options);
            awaitField = data.await || null;
            awaitPayload = data.payload || null;
            setInputMode(awaitField);
            if (!panel.classList.contains('cb-open')) { badge.textContent = '1'; badge.style.display = 'block'; }
        })
        .catch(function (err) {
            typing(false);
            if (window.console && console.error) console.error('[chatbot]', CB_ENDPOINT, err);
            addMsg('Ops, tive um problema de conexão. Tente novamente. 🙏', 'bot');
            renderOptions([{ label: '↩️ Menu', intent: 'menu' }]);
        });
    }

    // ---- Teaser + badge ----
    function esconderTeaser() { teaser.classList.remove('cb-show'); try { sessionStorage.setItem('cb-teased', '1'); } catch (e) {} }
    document.getElementById('cb-teaser-x').addEventListener('click', function (e) { e.stopPropagation(); esconderTeaser(); });
    teaser.addEventListener('click', function () { esconderTeaser(); abrir(); });
    var jaTeased = false; try { jaTeased = sessionStorage.getItem('cb-teased') === '1'; } catch (e) {}
    if (!jaTeased) { setTimeout(function () { if (!panel.classList.contains('cb-open')) teaser.classList.add('cb-show'); }, 1500); }

    function abrir() {
        panel.classList.add('cb-open');
        launcher.classList.add('cb-hidepulse');
        badge.style.display = 'none';
        esconderTeaser();
        if (!iniciado) {
            iniciado = true;
            if (!restore()) { send('menu', {}, '', '', { record: true }); }
        }
        setTimeout(function () { input.focus(); }, 200);
    }
    function fechar() { panel.classList.remove('cb-open'); }
    launcher.addEventListener('click', function () { panel.classList.contains('cb-open') ? fechar() : abrir(); });
    document.getElementById('cb-close').addEventListener('click', fechar);

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var txt = input.value.trim(); if (!txt) return;
        input.value = '';
        if (awaitField) {
            var campo = awaitField, pl = awaitPayload || {}, pwd = isPwd(campo);
            awaitField = null; awaitPayload = null; setInputMode(null);
            addMsg(pwd ? '••••••' : txt, 'user');
            send('book_coletar', pl, txt, campo, { record: false }); // fluxo sensível: não grava memória
        } else {
            addMsg(txt, 'user');
            send('', {}, txt, '', { userText: txt });
        }
    });
})();
</script>
