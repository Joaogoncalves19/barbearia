/* js/notif_agendamentos.js
   Motor compartilhado das notificações de novos agendamentos (admin e barbeiro).
   Configuração via window.NA_CONFIG:
     { isAdmin: bool, barbeiroId: string|'', baseCount: int,
       endpoint: 'check_new_appointments.php', pollMs: 15000 } */
(function () {
    'use strict';

    var cfg = window.NA_CONFIG || {};
    var endpoint = cfg.endpoint || 'check_new_appointments.php';
    var pollMs = cfg.pollMs || 15000;
    var barbeiroId = cfg.barbeiroId || '';
    var isAdmin = !!cfg.isAdmin;

    var stack = document.getElementById('novo-agendamento-stack');
    if (!stack) { return; }

    var audio = document.getElementById('audio-notificacao');
    var knownCount = typeof cfg.baseCount === 'number' ? cfg.baseCount : null;
    var shownIds = {};          // ids já exibidos nesta sessão de página
    var MAX_VISIVEL = 4;        // cartões simultâneos no desktop
    var AUTO_HIDE_MS = 15000;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function fmtData(iso) {
        if (!iso) { return ''; }
        var p = String(iso).split('-');
        return p.length === 3 ? (p[2] + '/' + p[1] + '/' + p[0]) : iso;
    }

    function statusClasse(status) {
        var s = String(status || '').toLowerCase();
        return 'na-st-' + s.replace(/[^a-z]/g, '');
    }

    function linkVer(item) {
        if (isAdmin) {
            var d = item.data ? ('&filtro_data=' + encodeURIComponent(item.data)) : '';
            return 'admin.php?tab=agendamentos' + d;
        }
        return 'barbeiro.php?tab=agenda';
    }

    function removerCartao(card) {
        if (!card || card.__removendo) { return; }
        card.__removendo = true;
        card.classList.remove('na-show');
        card.classList.add('na-hide');
        setTimeout(function () { if (card && card.parentNode) { card.parentNode.removeChild(card); } }, 400);
    }

    function tocarSom() {
        if (audio && typeof audio.play === 'function') {
            try {
                audio.currentTime = 0;
                var pr = audio.play();
                if (pr && pr.catch) { pr.catch(function () {}); }
            } catch (e) { /* navegador pode bloquear até interação */ }
        }
    }

    function montarCartao(item, extra) {
        var card = document.createElement('div');
        card.className = 'na-card';

        var linhas = '';
        linhas += '<div class="na-card-linha"><i class="fa fa-scissors"></i><span>' + esc(item.servicos || 'Serviço') + '</span></div>';

        var quando = fmtData(item.data);
        if (item.hora) { quando += (quando ? ' • ' : '') + esc(item.hora); }
        if (quando) {
            linhas += '<div class="na-card-linha"><i class="fa fa-calendar-day"></i><span>' + quando + '</span></div>';
        }
        if (isAdmin && item.barbeiro) {
            linhas += '<div class="na-card-linha"><i class="fa fa-user-tie"></i><span>' + esc(item.barbeiro) + '</span></div>';
        }

        var badge = '<span class="na-badge ' + statusClasse(item.status) + '">' + esc(item.status_label || 'Agendado') + '</span>';
        var ver = '<a class="na-card-ver" href="' + esc(linkVer(item)) + '"><i class="fa fa-arrow-right"></i> Ver</a>';
        var extraHtml = (extra && extra > 0)
            ? '<div class="na-card-extra"><i class="fa fa-layer-group"></i> +' + extra + ' outro(s) novo(s) agendamento(s)</div>'
            : '';

        card.innerHTML =
            '<div class="na-card-icon"><i class="fa fa-calendar-plus"></i></div>' +
            '<div class="na-card-body">' +
                '<p class="na-card-title">Novo agendamento</p>' +
                '<h4 class="na-card-cliente">' + esc(item.cliente || 'Cliente') + '</h4>' +
                linhas +
                '<div class="na-card-foot">' + badge + ver + '</div>' +
            '</div>' +
            extraHtml +
            '<button type="button" class="na-card-close" aria-label="Fechar"><i class="fa fa-times"></i></button>';

        card.querySelector('.na-card-close').addEventListener('click', function () { removerCartao(card); });

        // Insere no topo da pilha e limita a quantidade visível.
        stack.insertBefore(card, stack.firstChild);
        while (stack.children.length > MAX_VISIVEL) {
            removerCartao(stack.lastChild);
        }

        requestAnimationFrame(function () { card.classList.add('na-show'); });
        setTimeout(function () { removerCartao(card); }, AUTO_HIDE_MS);
    }

    function processar(data) {
        if (!data || typeof data.appointment_count === 'undefined') { return; }
        var count = data.appointment_count;

        // Primeira resposta apenas sincroniza a linha de base.
        if (knownCount === null) { knownCount = count; return; }

        if (count > knownCount) {
            var delta = count - knownCount;
            var recentes = Array.isArray(data.recent) ? data.recent : [];

            // Novos ainda não mostrados (recent vem do mais novo para o mais antigo).
            var novos = [];
            for (var i = 0; i < recentes.length && novos.length < delta; i++) {
                var it = recentes[i];
                if (it && it.id && !shownIds[it.id]) { novos.push(it); }
            }

            if (novos.length > 0) {
                tocarSom();
                // Excedente que não coube na lista de detalhes (ex.: chegaram vários).
                var excedente = delta - novos.length;
                novos.forEach(function (it, idx) {
                    shownIds[it.id] = true;
                    montarCartao(it, idx === 0 ? excedente : 0);
                });
            }
            knownCount = count;
        } else if (count < knownCount) {
            // Cancelamentos/exclusões reduziram o total: reacompanha sem alarme.
            knownCount = count;
        }
    }

    function verificar() {
        var url = endpoint + (barbeiroId ? ('?barbeiro_id=' + encodeURIComponent(barbeiroId)) : '');
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(processar)
            .catch(function () { /* silencioso: rede instável não deve poluir o console */ });
    }

    // Sincroniza a base assim que a página carrega e inicia o polling.
    verificar();
    setInterval(verificar, pollMs);
})();
