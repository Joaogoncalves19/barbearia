(function () {
    'use strict';

    function initPerformanceChart() {
        var canvas = document.getElementById('gestao-performance-chart');
        if (!canvas || typeof Chart === 'undefined' || !Array.isArray(window.gestaoChartData)) {
            return;
        }

        var styles = getComputedStyle(document.documentElement);
        var themeColor = styles.getPropertyValue('--secondary-color').trim() || '#2563eb';
        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: window.gestaoChartData.map(function (item) { return item.label; }),
                datasets: [
                    {
                        label: 'Faturamento',
                        data: window.gestaoChartData.map(function (item) { return item.faturamento; }),
                        backgroundColor: themeColor,
                        borderRadius: 5,
                        yAxisID: 'y'
                    },
                    {
                        type: 'line',
                        label: 'Atendimentos',
                        data: window.gestaoChartData.map(function (item) { return item.atendimentos; }),
                        borderColor: '#10b981',
                        backgroundColor: '#10b981',
                        pointRadius: 3,
                        pointHoverRadius: 4,
                        tension: 0.35,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#172033',
                        padding: 10,
                        cornerRadius: 6,
                        callbacks: {
                            label: function (context) {
                                if (context.datasetIndex === 0) {
                                    return ' Faturamento: R$ ' + Number(context.raw).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
                                }
                                return ' Atendimentos: ' + context.raw;
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#64748b', font: { size: 10 } } },
                    y: { beginAtZero: true, grid: { color: '#eef2f7' }, ticks: { color: '#64748b', font: { size: 10 } } },
                    y1: { beginAtZero: true, position: 'right', grid: { display: false }, ticks: { stepSize: 1, color: '#64748b', font: { size: 10 } } }
                }
            }
        });
    }

    // ---------------------------------------------------------------------
    // Busca global (Ctrl+K) — command palette do topo do painel.
    // ---------------------------------------------------------------------
    function initGlobalSearch() {
        var overlay = document.getElementById('admin-search-overlay');
        var openButton = document.getElementById('admin-global-search-open');
        var closeButton = document.getElementById('admin-global-search-close');
        var input = document.getElementById('admin-global-search-input');
        var results = document.getElementById('admin-global-search-results');
        var filtersBox = document.getElementById('admin-global-search-filters');
        var countBox = document.getElementById('admin-global-search-count');
        var data = Array.isArray(window.adminGlobalSearchData) ? window.adminGlobalSearchData : [];

        if (!overlay || !openButton || !input || !results) {
            return;
        }

        var LIMITE = 30;
        var CHAVE_RECENTES = 'admin_busca_recentes';
        var ORDEM_TIPOS = ['Ir para', 'Cliente', 'Agendamento', 'Profissional', 'Serviço', 'Combo', 'Produto', 'Plano'];
        var atalhosNav = data.filter(function (item) { return item.tipo === 'Ir para'; });
        var selectedIndex = -1;
        var filtroTipo = '';
        var debounceId = null;
        var ultimoFoco = null;

        // Diacríticos combinantes (U+0300–U+036F) gerados pelo normalize('NFD').
        // Montado por código para o arquivo não depender de caracteres invisíveis.
        var ACENTOS = new RegExp('[' + String.fromCharCode(768) + '-' + String.fromCharCode(879) + ']', 'g');

        function normalize(value) {
            return String(value || '').normalize('NFD').replace(ACENTOS, '').toLowerCase();
        }

        // Normaliza mantendo o mapa de índices para a string original: sem
        // isso o realce erraria a posição em nomes acentuados (NFD muda o
        // comprimento do texto).
        function normalizarAlinhado(origem) {
            var texto = '';
            var mapa = [];
            for (var i = 0; i < origem.length; i++) {
                var pedaco = origem[i].normalize('NFD').replace(ACENTOS, '').toLowerCase();
                for (var k = 0; k < pedaco.length; k++) mapa.push(i);
                texto += pedaco;
            }
            mapa.push(origem.length);
            return { texto: texto, mapa: mapa };
        }

        // Índice pré-calculado: normalizar 1x no load em vez de a cada tecla.
        var indice = data.map(function (item) {
            return {
                item: item,
                titulo: normalize(item.titulo),
                alvo: normalize([item.titulo, item.subtitulo, item.tipo, item.extra || ''].join(' ')),
                digitos: String(item.titulo + ' ' + (item.subtitulo || '') + ' ' + (item.extra || '')).replace(/\D+/g, '')
            };
        });

        function lerRecentes() {
            try {
                var bruto = JSON.parse(localStorage.getItem(CHAVE_RECENTES) || '[]');
                return Array.isArray(bruto) ? bruto.slice(0, 5) : [];
            } catch (e) {
                return [];
            }
        }

        function guardarRecente(item) {
            try {
                var lista = lerRecentes().filter(function (r) { return r.url !== item.url; });
                lista.unshift({ tipo: item.tipo, titulo: item.titulo, subtitulo: item.subtitulo, url: item.url, icone: item.icone });
                localStorage.setItem(CHAVE_RECENTES, JSON.stringify(lista.slice(0, 5)));
            } catch (e) {
                /* localStorage indisponível: recentes é um extra, segue sem. */
            }
        }

        function openSearch() {
            ultimoFoco = document.activeElement;
            overlay.classList.add('is-open');
            overlay.setAttribute('aria-hidden', 'false');
            document.body.classList.add('admin-search-lock');
            renderFiltros();
            input.focus();
            input.select();
            renderResults(input.value);
        }

        function closeSearch() {
            overlay.classList.remove('is-open');
            overlay.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('admin-search-lock');
            input.value = '';
            filtroTipo = '';
            selectedIndex = -1;
            input.removeAttribute('aria-activedescendant');
            // O chip ativo também volta para "Tudo", senão a próxima abertura
            // mostraria um filtro marcado que já não está sendo aplicado.
            if (filtersBox) {
                filtersBox.querySelectorAll('.admin-search-chip').forEach(function (chip, i) {
                    chip.classList.toggle('is-active', i === 0);
                });
            }
            if (ultimoFoco && typeof ultimoFoco.focus === 'function') ultimoFoco.focus();
        }

        // Chips de tipo: só aparecem os tipos que existem no índice.
        function renderFiltros() {
            if (!filtersBox || filtersBox.childElementCount) return;
            var tipos = [];
            data.forEach(function (item) {
                if (item.tipo && tipos.indexOf(item.tipo) === -1) tipos.push(item.tipo);
            });
            tipos.sort(function (a, b) {
                var ia = ORDEM_TIPOS.indexOf(a); var ib = ORDEM_TIPOS.indexOf(b);
                return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib);
            });
            ['Tudo'].concat(tipos).forEach(function (tipo) {
                var chip = document.createElement('button');
                chip.type = 'button';
                chip.className = 'admin-search-chip' + (tipo === 'Tudo' ? ' is-active' : '');
                chip.textContent = tipo;
                chip.dataset.tipo = tipo === 'Tudo' ? '' : tipo;
                chip.addEventListener('click', function () {
                    filtroTipo = chip.dataset.tipo;
                    filtersBox.querySelectorAll('.admin-search-chip').forEach(function (outro) {
                        outro.classList.toggle('is-active', outro === chip);
                    });
                    renderResults(input.value);
                    input.focus();
                });
                filtersBox.appendChild(chip);
            });
        }

        function renderEmpty(icon, text) {
            results.replaceChildren();
            var empty = document.createElement('div');
            empty.className = 'admin-search-empty';
            var iconElement = document.createElement('i');
            iconElement.className = 'fa ' + icon;
            var label = document.createElement('span');
            label.textContent = text;
            empty.append(iconElement, label);
            results.appendChild(empty);
        }

        // Destaca cada termo digitado dentro do texto, sem quebrar acentos:
        // a comparação é feita na versão normalizada, o recorte na original.
        function comDestaque(texto, termos) {
            var alvo = document.createElement('span');
            var origem = String(texto || '');
            if (!termos.length) {
                alvo.textContent = origem;
                return alvo;
            }
            var alinhado = normalizarAlinhado(origem);
            var normalizado = alinhado.texto;
            var marcas = [];
            termos.forEach(function (termo) {
                if (!termo) return;
                var de = normalizado.indexOf(termo);
                while (de !== -1) {
                    marcas.push([alinhado.mapa[de], alinhado.mapa[de + termo.length]]);
                    de = normalizado.indexOf(termo, de + termo.length);
                }
            });
            if (!marcas.length) {
                alvo.textContent = origem;
                return alvo;
            }
            marcas.sort(function (a, b) { return a[0] - b[0]; });
            var cursor = 0;
            marcas.forEach(function (marca) {
                if (marca[0] < cursor) return;
                if (marca[0] > cursor) alvo.appendChild(document.createTextNode(origem.slice(cursor, marca[0])));
                var mark = document.createElement('mark');
                mark.textContent = origem.slice(marca[0], marca[1]);
                alvo.appendChild(mark);
                cursor = marca[1];
            });
            if (cursor < origem.length) alvo.appendChild(document.createTextNode(origem.slice(cursor)));
            return alvo;
        }

        function criarResultado(item, termos, indiceGlobal) {
            var link = document.createElement('a');
            link.className = 'admin-search-result';
            link.href = item.url;
            link.setAttribute('role', 'option');
            link.id = 'admin-search-opt-' + indiceGlobal;

            var icon = document.createElement('i');
            icon.className = 'fa ' + (item.icone || 'fa-circle-dot');

            var content = document.createElement('span');
            var title = document.createElement('strong');
            title.appendChild(comDestaque(item.titulo, termos));
            var subtitle = document.createElement('small');
            subtitle.appendChild(comDestaque(item.subtitulo, termos));
            content.append(title, subtitle);

            var type = document.createElement('em');
            type.textContent = item.tipo;
            link.append(icon, content, type);
            link.addEventListener('click', function () { guardarRecente(item); });
            return link;
        }

        function renderAgrupado(itens, termos, limite) {
            results.replaceChildren();
            var porTipo = {};
            itens.forEach(function (item) {
                (porTipo[item.tipo] = porTipo[item.tipo] || []).push(item);
            });
            var tipos = Object.keys(porTipo).sort(function (a, b) {
                var ia = ORDEM_TIPOS.indexOf(a); var ib = ORDEM_TIPOS.indexOf(b);
                return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib);
            });
            var total = 0;
            tipos.forEach(function (tipo) {
                if (limite && total >= limite) return;
                var head = document.createElement('div');
                head.className = 'admin-search-group';
                head.textContent = tipo + ' · ' + porTipo[tipo].length;
                results.appendChild(head);
                porTipo[tipo].forEach(function (item) {
                    if (limite && total >= limite) return;
                    results.appendChild(criarResultado(item, termos, total));
                    total++;
                });
            });
            return total;
        }

        function atualizarContador(exibidos, encontrados) {
            if (!countBox) return;
            if (!encontrados) {
                countBox.textContent = ' ';
            } else if (exibidos < encontrados) {
                countBox.textContent = 'Mostrando ' + exibidos + ' de ' + encontrados + ' resultados';
            } else {
                countBox.textContent = encontrados + (encontrados === 1 ? ' resultado' : ' resultados');
            }
        }

        function selecionar(indiceNovo) {
            var links = results.querySelectorAll('.admin-search-result');
            if (!links.length) return;
            selectedIndex = Math.max(0, Math.min(links.length - 1, indiceNovo));
            links.forEach(function (link, i) { link.classList.toggle('is-selected', i === selectedIndex); });
            var ativo = links[selectedIndex];
            if (ativo) {
                ativo.scrollIntoView({ block: 'nearest' });
                input.setAttribute('aria-activedescendant', ativo.id);
            }
        }

        // Estado inicial: últimos acessos + atalhos de navegação.
        function renderInicial() {
            results.replaceChildren();
            var recentes = lerRecentes();
            var total = 0;
            if (recentes.length) {
                var headRec = document.createElement('div');
                headRec.className = 'admin-search-group';
                headRec.textContent = 'Últimos acessos';
                results.appendChild(headRec);
                recentes.forEach(function (item) {
                    results.appendChild(criarResultado(item, [], total));
                    total++;
                });
            }
            if (atalhosNav.length) {
                var headNav = document.createElement('div');
                headNav.className = 'admin-search-group';
                headNav.textContent = 'Ir para';
                results.appendChild(headNav);
                atalhosNav.forEach(function (item) {
                    results.appendChild(criarResultado(item, [], total));
                    total++;
                });
            }
            if (!total) {
                renderEmpty('fa-keyboard', 'Digite pelo menos dois caracteres para pesquisar.');
            }
            atualizarContador(0, 0);
            selecionar(0);
        }

        function renderResults(term) {
            selectedIndex = -1;
            input.removeAttribute('aria-activedescendant');
            var bruto = normalize(term).trim();

            if (bruto.length < 2 && !filtroTipo) {
                renderInicial();
                return;
            }

            // Cada palavra é um filtro independente ("joao 12/03" casa os dois).
            var termos = bruto.split(/\s+/).filter(Boolean);
            var digitos = bruto.replace(/\D+/g, '');

            var pontuados = [];
            indice.forEach(function (entrada) {
                var item = entrada.item;
                if (filtroTipo && item.tipo !== filtroTipo) return;
                if (termos.length) {
                    var casaTudo = termos.every(function (t) {
                        return entrada.alvo.indexOf(t) !== -1 ||
                            (digitos.length >= 3 && entrada.digitos.indexOf(digitos) !== -1);
                    });
                    if (!casaTudo) return;
                }
                // Prioriza início do título > palavra no título > qualquer campo.
                var primeiro = termos[0] || '';
                var score = 3;
                if (primeiro && entrada.titulo.indexOf(primeiro) === 0) score = 0;
                else if (primeiro && entrada.titulo.indexOf(' ' + primeiro) !== -1) score = 1;
                else if (primeiro && entrada.titulo.indexOf(primeiro) !== -1) score = 2;
                var pesoTipo = ORDEM_TIPOS.indexOf(item.tipo);
                pontuados.push({ item: item, score: score, peso: pesoTipo === -1 ? 99 : pesoTipo });
            });

            if (!pontuados.length) {
                renderEmpty('fa-search', 'Nenhum resultado para “' + term.trim() + '”.');
                atualizarContador(0, 0);
                return;
            }

            pontuados.sort(function (a, b) {
                return a.score - b.score || a.peso - b.peso;
            });
            var exibidos = renderAgrupado(pontuados.map(function (p) { return p.item; }), termos, LIMITE);
            atualizarContador(exibidos, pontuados.length);
            selecionar(0);
        }

        openButton.addEventListener('click', openSearch);
        if (closeButton) closeButton.addEventListener('click', closeSearch);
        input.addEventListener('input', function () {
            // Debounce curto: com milhares de itens indexados evita repintar
            // a lista inteira a cada tecla.
            window.clearTimeout(debounceId);
            debounceId = window.setTimeout(function () { renderResults(input.value); }, 90);
        });
        input.addEventListener('keydown', function (event) {
            var links = results.querySelectorAll('.admin-search-result');
            if (event.key === 'Escape') { closeSearch(); return; }
            if (!links.length) return;
            if (event.key === 'ArrowDown') { event.preventDefault(); selecionar(selectedIndex + 1); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); selecionar(selectedIndex - 1); }
            else if (event.key === 'Home') { event.preventDefault(); selecionar(0); }
            else if (event.key === 'End') { event.preventDefault(); selecionar(links.length - 1); }
            else if (event.key === 'Enter') {
                event.preventDefault();
                var alvo = links[selectedIndex >= 0 ? selectedIndex : 0];
                if (alvo) alvo.click();
            }
        });
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) closeSearch();
        });
        document.addEventListener('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                overlay.classList.contains('is-open') ? closeSearch() : openSearch();
            } else if (event.key === 'Escape' && overlay.classList.contains('is-open')) {
                closeSearch();
            }
        });
    }

    // ---------------------------------------------------------------------
    // Central de alertas operacionais (sino do topo).
    // ---------------------------------------------------------------------
    function initAlertCenter() {
        var trigger = document.getElementById('admin-alert-trigger');
        var popover = document.getElementById('admin-alert-popover');
        var lista = document.getElementById('admin-alert-list');
        var resumoBox = document.getElementById('admin-alert-summary');
        var badge = document.getElementById('admin-alert-badge');
        var carimbo = document.getElementById('admin-alert-updated');
        var botaoRefresh = document.getElementById('admin-alert-refresh');
        if (!trigger || !popover || !lista) return;

        var config = window.adminAlertasConfig || {};
        var endpoint = config.endpoint || 'admin_alertas.php';
        var intervalo = config.intervaloMs || 90000;
        var alertas = Array.isArray(window.adminAlertasIniciais) ? window.adminAlertasIniciais : [];
        var atualizadoEm = Date.now();
        var carregando = false;
        var pendentesAnteriores = null;

        var ROTULOS = {
            danger: ['crítico', 'críticos'],
            warning: ['atenção', 'atenções'],
            info: ['informativo', 'informativos']
        };

        function resumir(itens) {
            var r = { danger: 0, warning: 0, info: 0, success: 0 };
            itens.forEach(function (a) {
                var t = a.tipo in r ? a.tipo : 'info';
                r[t]++;
            });
            r.pendentes = r.danger + r.warning;
            return r;
        }

        function tempoRelativo(ms) {
            var seg = Math.round((Date.now() - ms) / 1000);
            if (seg < 60) return 'atualizado agora';
            var min = Math.round(seg / 60);
            if (min < 60) return 'atualizado há ' + min + ' min';
            var h = Math.round(min / 60);
            return 'atualizado há ' + h + 'h';
        }

        function renderResumo(resumo) {
            if (!resumoBox) return;
            resumoBox.replaceChildren();
            var algum = false;
            ['danger', 'warning', 'info'].forEach(function (tipo) {
                if (!resumo[tipo]) return;
                algum = true;
                var chip = document.createElement('span');
                chip.className = 'admin-alert-chip is-' + tipo;
                chip.textContent = resumo[tipo] + ' ' + ROTULOS[tipo][resumo[tipo] > 1 ? 1 : 0];
                resumoBox.appendChild(chip);
            });
            resumoBox.classList.toggle('is-hidden', !algum);
        }

        function renderLista() {
            lista.replaceChildren();
            alertas.forEach(function (alerta) {
                var link = document.createElement('a');
                link.className = 'admin-alert-popover-item is-' + (alerta.tipo || 'info');
                link.href = alerta.url || '#';

                var icone = document.createElement('i');
                icone.className = 'fa ' + (alerta.icone || 'fa-circle-info');

                var corpo = document.createElement('span');
                corpo.className = 'admin-alert-item-body';
                var titulo = document.createElement('strong');
                titulo.textContent = alerta.titulo || '';
                corpo.appendChild(titulo);
                if (alerta.descricao) {
                    var desc = document.createElement('small');
                    desc.textContent = alerta.descricao;
                    corpo.appendChild(desc);
                }
                if (alerta.acao) {
                    var acao = document.createElement('span');
                    acao.className = 'admin-alert-item-action';
                    acao.textContent = alerta.acao;
                    corpo.appendChild(acao);
                }

                var seta = document.createElement('i');
                seta.className = 'fa fa-chevron-right admin-alert-popover-go';

                link.append(icone, corpo, seta);
                lista.appendChild(link);
            });
        }

        function render() {
            var resumo = resumir(alertas);
            renderResumo(resumo);
            renderLista();
            if (badge) {
                badge.textContent = resumo.pendentes;
                badge.classList.toggle('is-clear', resumo.pendentes === 0);
                // Pulsa só quando surge pendência nova, para o admin notar.
                if (pendentesAnteriores !== null && resumo.pendentes > pendentesAnteriores) {
                    badge.classList.remove('is-pulse');
                    void badge.offsetWidth;
                    badge.classList.add('is-pulse');
                }
            }
            pendentesAnteriores = resumo.pendentes;
            if (carimbo) carimbo.textContent = tempoRelativo(atualizadoEm);
            trigger.title = resumo.pendentes === 0
                ? 'Alertas operacionais · nada pendente'
                : 'Alertas operacionais · ' + resumo.pendentes + ' pendente' + (resumo.pendentes > 1 ? 's' : '');
        }

        function atualizar(forcado) {
            if (carregando) return;
            if (!forcado && document.hidden) return;
            carregando = true;
            popover.classList.add('is-loading');
            fetch(endpoint, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (resposta) { return resposta.ok ? resposta.json() : null; })
                .then(function (dados) {
                    if (!dados || !dados.ok || !Array.isArray(dados.alertas)) return;
                    alertas = dados.alertas;
                    atualizadoEm = Date.now();
                    render();
                })
                .catch(function () {
                    /* Falha de rede: mantém os alertas já exibidos. */
                })
                .finally(function () {
                    carregando = false;
                    popover.classList.remove('is-loading');
                });
        }

        function abrir() {
            popover.classList.add('is-open');
            trigger.setAttribute('aria-expanded', 'true');
            if (carimbo) carimbo.textContent = tempoRelativo(atualizadoEm);
            // Se a lista já está velha, revalida ao abrir.
            if (Date.now() - atualizadoEm > intervalo) atualizar(true);
        }

        function fechar(devolverFoco) {
            popover.classList.remove('is-open');
            trigger.setAttribute('aria-expanded', 'false');
            if (devolverFoco) trigger.focus();
        }

        render();

        trigger.addEventListener('click', function (event) {
            event.stopPropagation();
            popover.classList.contains('is-open') ? fechar(false) : abrir();
        });
        if (botaoRefresh) {
            botaoRefresh.addEventListener('click', function (event) {
                event.stopPropagation();
                atualizar(true);
            });
        }
        popover.addEventListener('keydown', function (event) {
            var itens = Array.prototype.slice.call(lista.querySelectorAll('.admin-alert-popover-item'));
            if (event.key === 'Escape') { fechar(true); return; }
            if (!itens.length || (event.key !== 'ArrowDown' && event.key !== 'ArrowUp')) return;
            event.preventDefault();
            var atual = itens.indexOf(document.activeElement);
            var proximo = event.key === 'ArrowDown' ? atual + 1 : atual - 1;
            if (proximo < 0) proximo = itens.length - 1;
            if (proximo >= itens.length) proximo = 0;
            itens[proximo].focus();
        });
        trigger.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' && popover.classList.contains('is-open')) {
                var primeiro = lista.querySelector('.admin-alert-popover-item');
                if (primeiro) { event.preventDefault(); primeiro.focus(); }
            }
        });
        document.addEventListener('click', function (event) {
            if (!popover.contains(event.target) && !trigger.contains(event.target)) {
                fechar(false);
            }
        });

        window.setInterval(function () { atualizar(false); }, intervalo);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && Date.now() - atualizadoEm > intervalo) atualizar(true);
        });
        // Mantém o "atualizado há X" coerente sem depender de novo fetch.
        window.setInterval(function () {
            if (carimbo) carimbo.textContent = tempoRelativo(atualizadoEm);
        }, 30000);
    }

    function initScheduleDragLegacy() {
        var board = document.getElementById('gestao-schedule-board');
        var form = document.getElementById('gestao-quick-reschedule-form');
        var appointmentInput = document.getElementById('gestao-drag-appointment');
        var dateInput = document.getElementById('gestao-drag-date');
        if (!board || !form || !appointmentInput || !dateInput) return;

        var draggedId = '';
        board.querySelectorAll('.schedule-appointment.is-draggable').forEach(function (card) {
            card.addEventListener('dragstart', function () {
                draggedId = card.getAttribute('data-appointment-id') || '';
                card.classList.add('is-dragging');
            });
            card.addEventListener('dragend', function () {
                card.classList.remove('is-dragging');
                board.querySelectorAll('.schedule-day').forEach(function (day) { day.classList.remove('is-drop-target'); });
            });
        });

        board.querySelectorAll('.schedule-day').forEach(function (day) {
            day.addEventListener('dragover', function (event) {
                if (!draggedId) return;
                event.preventDefault();
                day.classList.add('is-drop-target');
            });
            day.addEventListener('dragleave', function () {
                day.classList.remove('is-drop-target');
            });
            day.addEventListener('drop', function (event) {
                event.preventDefault();
                day.classList.remove('is-drop-target');
                var newDate = day.getAttribute('data-schedule-date');
                if (!draggedId || !newDate) return;

                function submitMove() {
                    appointmentInput.value = draggedId;
                    dateInput.value = newDate;
                    form.submit();
                }

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: 'Mover agendamento?',
                        text: 'O horário será mantido e somente o dia será alterado.',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Mover',
                        cancelButtonText: 'Cancelar',
                        reverseButtons: true
                    }).then(function (result) {
                        if (result.isConfirmed) submitMove();
                    });
                } else if (window.confirm('Mover este agendamento para o novo dia?')) {
                    submitMove();
                }
            });
        });
    }

    function initScheduleDrag() {
        var board = document.getElementById('gestao-schedule-board');
        var form = document.getElementById('gestao-quick-reschedule-form');
        var appointmentInput = document.getElementById('gestao-drag-appointment');
        var dateInput = document.getElementById('gestao-drag-date');
        if (!board || !form || !appointmentInput || !dateInput) return;

        var draggedId = '';
        var draggedCard = null;

        function clearDropTargets() {
            board.querySelectorAll('.schedule-day').forEach(function (day) {
                day.classList.remove('is-drop-target');
            });
        }

        function localToday() {
            var today = new Date();
            var month = String(today.getMonth() + 1).padStart(2, '0');
            var day = String(today.getDate()).padStart(2, '0');
            return today.getFullYear() + '-' + month + '-' + day;
        }

        function submitMove(appointmentId, newDate) {
            appointmentInput.value = appointmentId;
            dateInput.value = newDate;
            form.submit();
        }

        function requestMove(appointmentId, newDate, originalDate) {
            if (!appointmentId || !newDate || newDate === originalDate) return;
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Mover agendamento?',
                    text: 'O horário será mantido e somente o dia será alterado.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Mover',
                    cancelButtonText: 'Cancelar',
                    reverseButtons: true
                }).then(function (result) {
                    if (result.isConfirmed) submitMove(appointmentId, newDate);
                });
            } else if (window.confirm('Mover este agendamento para o novo dia?')) {
                submitMove(appointmentId, newDate);
            }
        }

        function openDatePicker(card) {
            var appointmentId = card.getAttribute('data-appointment-id') || '';
            var originalDate = card.getAttribute('data-original-date') || '';
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Escolha o novo dia',
                    input: 'date',
                    inputValue: originalDate,
                    inputAttributes: { min: localToday() },
                    showCancelButton: true,
                    confirmButtonText: 'Mover',
                    cancelButtonText: 'Cancelar',
                    reverseButtons: true,
                    inputValidator: function (value) {
                        if (!value) return 'Selecione uma data.';
                        if (value === originalDate) return 'Escolha um dia diferente.';
                        return null;
                    }
                }).then(function (result) {
                    if (result.isConfirmed && result.value) {
                        submitMove(appointmentId, result.value);
                    }
                });
                return;
            }

            var selectedDate = window.prompt('Informe a nova data no formato AAAA-MM-DD:', originalDate);
            if (selectedDate) requestMove(appointmentId, selectedDate, originalDate);
        }

        board.querySelectorAll('.schedule-appointment.is-draggable').forEach(function (card) {
            card.addEventListener('dragstart', function (event) {
                draggedId = card.getAttribute('data-appointment-id') || '';
                draggedCard = card;
                if (event.dataTransfer) {
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', draggedId);
                }
                card.classList.add('is-dragging');
            });

            card.addEventListener('dragend', function () {
                card.classList.remove('is-dragging');
                draggedId = '';
                draggedCard = null;
                clearDropTargets();
            });

            var moveButton = card.querySelector('.schedule-move-action');
            if (moveButton) {
                moveButton.addEventListener('click', function (event) {
                    event.stopPropagation();
                    openDatePicker(card);
                });
            }

            var dragHandle = card.querySelector('.schedule-drag-handle');
            if (dragHandle) {
                dragHandle.addEventListener('pointerdown', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    var startX = event.clientX;
                    var startY = event.clientY;
                    var ghost = null;
                    var targetDay = null;
                    var moved = false;
                    dragHandle.setPointerCapture(event.pointerId);

                    function onPointerMove(moveEvent) {
                        var distance = Math.abs(moveEvent.clientX - startX) + Math.abs(moveEvent.clientY - startY);
                        if (!moved && distance < 7) return;
                        moved = true;
                        if (!ghost) {
                            ghost = card.cloneNode(true);
                            ghost.classList.add('schedule-drag-ghost');
                            ghost.removeAttribute('draggable');
                            document.body.appendChild(ghost);
                            card.classList.add('is-dragging');
                        }
                        ghost.style.left = moveEvent.clientX + 'px';
                        ghost.style.top = moveEvent.clientY + 'px';
                        clearDropTargets();
                        var element = document.elementFromPoint(moveEvent.clientX, moveEvent.clientY);
                        targetDay = element ? element.closest('.schedule-day') : null;
                        if (targetDay) targetDay.classList.add('is-drop-target');
                    }

                    function finishPointerDrag() {
                        dragHandle.removeEventListener('pointermove', onPointerMove);
                        dragHandle.removeEventListener('pointerup', finishPointerDrag);
                        dragHandle.removeEventListener('pointercancel', finishPointerDrag);
                        if (ghost) ghost.remove();
                        card.classList.remove('is-dragging');
                        clearDropTargets();
                        if (moved && targetDay) {
                            requestMove(
                                card.getAttribute('data-appointment-id') || '',
                                targetDay.getAttribute('data-schedule-date') || '',
                                card.getAttribute('data-original-date') || ''
                            );
                        }
                    }

                    dragHandle.addEventListener('pointermove', onPointerMove);
                    dragHandle.addEventListener('pointerup', finishPointerDrag);
                    dragHandle.addEventListener('pointercancel', finishPointerDrag);
                });
            }
        });

        board.querySelectorAll('.schedule-day').forEach(function (day) {
            day.addEventListener('dragover', function (event) {
                if (!draggedId) return;
                event.preventDefault();
                if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
                clearDropTargets();
                day.classList.add('is-drop-target');
            });
            day.addEventListener('dragleave', function () {
                day.classList.remove('is-drop-target');
            });
            day.addEventListener('drop', function (event) {
                event.preventDefault();
                clearDropTargets();
                requestMove(
                    draggedId,
                    day.getAttribute('data-schedule-date') || '',
                    draggedCard ? (draggedCard.getAttribute('data-original-date') || '') : ''
                );
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initPerformanceChart();
        initGlobalSearch();
        initAlertCenter();
        initScheduleDrag();
    });
})();
