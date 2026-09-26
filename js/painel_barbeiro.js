document.addEventListener('DOMContentLoaded', function () {
    const modals = document.querySelectorAll('.modal-overlay');
    const openModalButtons = document.querySelectorAll('[data-modal-target]');
    const closeModalButtons = document.querySelectorAll('.modal-close');
    
    function openModal(modal) { if (modal) modal.style.display = 'flex'; }
    function closeModal(modal) { if (modal) { modal.style.display = 'none'; const form = modal.querySelector('form'); if(form) form.reset(); } }
    
    openModalButtons.forEach(button => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            const modal = document.querySelector(button.getAttribute('data-modal-target'));
            if (!modal) return;
            openModal(modal);
            
            if(modal.id === 'modal-add-produto') {
                modal.querySelector('#produto_agendamento_id_barbeiro').value = button.dataset.id;
            }
            
            // INTEGRAÇÃO COM O MODAL RICO DE DETALHES DO CLIENTE
            if(modal.id === 'modal-cliente-detalhes') {
                if (typeof window.renderDetalhesCliente === 'function') {
                    window.renderDetalhesCliente(button.dataset.cid, modal);
                    
                    // Modifica o formulário para enviar pro barbeiro_actions invés do admin
                    setTimeout(() => {
                        const anotacaoForm = modal.querySelector('form[action="admin.php"]');
                        if (anotacaoForm) {
                            anotacaoForm.action = 'barbeiro_actions.php';
                            const actionInput = anotacaoForm.querySelector('input[name="action"]');
                            if(actionInput) actionInput.value = 'salvar_nota_cliente';
                        }
                    }, 50);
                }
            }
            
            if(modal.id === 'modal-ia-whatsapp') {
                document.getElementById('ia-nome-cliente').innerText = button.dataset.nome;
                document.getElementById('ia-telefone-cliente').value = button.dataset.telefone;
                document.getElementById('ia-resultado-box').style.display = 'none';
                document.getElementById('ia-motivo').value = "Estou com um atraso de 10 a 15 minutos, pedir desculpas.";
                document.getElementById('ia-motivo-custom').style.display = 'none';
                document.getElementById('ia-motivo-custom').value = '';
            }

            if(modal.id === 'modal-comanda') {
                window.abrirComanda(button.dataset.id, modal);
            }

            if(modal.id === 'modal-reagendar') {
                window.abrirReagendar(button, modal);
            }

            if(modal.id === 'modal-agendamento-manual') {
                var bmForm = document.getElementById('bm-form');
                if (bmForm) bmForm.reset();
                document.querySelectorAll('#bm_servicos_container .bm-servico').forEach(function (c) { c.classList.remove('selected'); });
                ['bm_servicos', 'bm_horario', 'bm_cliente_id', 'bm_email'].forEach(function (id) {
                    var el = document.getElementById(id); if (el) el.value = '';
                });
                var bmTimes = document.getElementById('bm_horarios');
                if (bmTimes) { bmTimes.className = 'bm-times bm-times-hint'; bmTimes.textContent = 'Selecione os serviços e a data para ver os horários.'; }
                var bmData = document.getElementById('bm_data');
                if (bmData && button.dataset.date) bmData.value = button.dataset.date;
            }
        });
    });

    // ===================== COMANDA DIGITAL =====================
    const brl = function (v) { return 'R$ ' + (Number(v) || 0).toFixed(2).replace('.', ','); };

    window.abrirComanda = function (agendamentoId, modal) {
        const loading = modal.querySelector('#comanda-loading');
        const form = modal.querySelector('#form-comanda');
        loading.style.display = 'block';
        form.style.display = 'none';
        modal.querySelector('#comanda-agendamento-id').value = agendamentoId;
        modal.querySelector('#comanda-gorjeta').value = '0';

        fetch('barbeiro_actions.php?action=comanda_dados&id=' + encodeURIComponent(agendamentoId))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    loading.innerHTML = '<i class="fa fa-triangle-exclamation"></i> ' + ((data && data.message) || 'Erro ao carregar a comanda.');
                    return;
                }

                modal.querySelector('#comanda-cliente').textContent = data.cliente || '';
                modal.querySelector('#comanda-hora').textContent = data.hora ? ('· ' + data.hora) : '';

                // Itens já lançados
                let html = '';
                (data.servicos || []).forEach(function (s) {
                    if (s.coberto) {
                        html += '<div class="comanda-item comanda-coberto"><span><i class="fa fa-crown" style="color:#10b981;"></i> ' + escapeHtml(s.nome) + ' <small style="color:#10b981;font-weight:600;">(incluído no plano)</small></span><span><s style="color:#94a3b8;">' + brl(s.valor) + '</s> <b style="color:#10b981;">Grátis</b></span></div>';
                    } else {
                        html += '<div class="comanda-item"><span><i class="fa fa-scissors"></i> ' + escapeHtml(s.nome) + '</span><span>' + brl(s.valor) + '</span></div>';
                    }
                });
                (data.produtos || []).forEach(function (p) {
                    html += '<div class="comanda-item"><span><i class="fa fa-box"></i> ' + escapeHtml(p.nome) + '</span><span>' + brl(p.valor) + '</span></div>';
                });
                if (Number(data.desconto) > 0) {
                    const rotuloDesc = data.plano_nome ? ('Assinatura · ' + data.plano_nome) : 'Desconto';
                    html += '<div class="comanda-item comanda-desc"><span><i class="fa fa-tag"></i> ' + escapeHtml(rotuloDesc) + '</span><span>- ' + brl(data.desconto) + '</span></div>';
                }
                if (!html) html = '<div class="comanda-item"><span>Nenhum item lançado.</span><span></span></div>';
                modal.querySelector('#comanda-itens').innerHTML = html;

                // Serviços extras disponíveis (os cobertos pelo plano entram como grátis)
                let chips = '';
                (data.servicos_disponiveis || []).forEach(function (s) {
                    if (s.coberto) {
                        chips += '<label class="comanda-chip comanda-chip-coberto"><input type="checkbox" name="servicos_extras[]" value="' + escapeHtml(s.id) + '" data-valor="0"> <i class="fa fa-crown" style="color:#10b981;"></i> ' + escapeHtml(s.nome) + ' <b style="color:#10b981;">Grátis (plano)</b></label>';
                    } else {
                        chips += '<label class="comanda-chip"><input type="checkbox" name="servicos_extras[]" value="' + escapeHtml(s.id) + '" data-valor="' + s.valor + '"> ' + escapeHtml(s.nome) + ' <b>+' + brl(s.valor) + '</b></label>';
                    }
                });
                const wrap = modal.querySelector('#comanda-extras-wrap');
                if (chips) {
                    modal.querySelector('#comanda-extras-chips').innerHTML = chips;
                    wrap.style.display = 'block';
                } else {
                    wrap.style.display = 'none';
                }

                // Base para o total
                modal._comandaBase = (Number(data.subtotal_servicos) || 0) + (Number(data.subtotal_produtos) || 0) - (Number(data.desconto) || 0);

                function recalc() {
                    let total = modal._comandaBase;
                    modal.querySelectorAll('#comanda-extras-chips input:checked').forEach(function (c) {
                        total += Number(c.dataset.valor) || 0;
                    });
                    total += Number(modal.querySelector('#comanda-gorjeta').value) || 0;
                    if (total < 0) total = 0;
                    modal.querySelector('#comanda-total').textContent = brl(total);
                }

                modal.querySelectorAll('#comanda-extras-chips input').forEach(function (c) { c.addEventListener('change', recalc); });
                modal.querySelector('#comanda-gorjeta').addEventListener('input', recalc);
                recalc();

                loading.style.display = 'none';
                form.style.display = 'block';
            })
            .catch(function () {
                loading.innerHTML = '<i class="fa fa-triangle-exclamation"></i> Falha de conexão ao carregar a comanda.';
            });
    };

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // ===================== AGENDAMENTO MANUAL (modal no painel) =====================
    (function initAgendamentoManual() {
        var form = document.getElementById('bm-form');
        if (!form) return;

        var barbeiroId = form.dataset.barbeiroId || '';
        var clienteInput = document.getElementById('bm_cliente_input');
        var clienteList = document.getElementById('bm_cliente_list');
        var clienteId = document.getElementById('bm_cliente_id');
        var nomeInput = document.getElementById('bm_nome');
        var telInput = document.getElementById('bm_telefone');
        var emailInput = document.getElementById('bm_email');
        var chips = Array.from(document.querySelectorAll('#bm_servicos_container .bm-servico'));
        var servicosHidden = document.getElementById('bm_servicos');
        var dataInput = document.getElementById('bm_data');
        var horariosBox = document.getElementById('bm_horarios');
        var horarioHidden = document.getElementById('bm_horario');

        if (dataInput) dataInput.min = new Date().toISOString().slice(0, 10);

        // Busca de cliente
        if (clienteInput && clienteList) {
            var opts = Array.from(clienteList.querySelectorAll('li'));
            function filtra() {
                var q = clienteInput.value.trim().toLocaleLowerCase('pt-BR');
                var vis = 0;
                opts.forEach(function (li, i) {
                    var txt = ((li.dataset.nome || '') + ' ' + (li.dataset.telefone || '')).toLocaleLowerCase('pt-BR');
                    var show = i === 0 || q === '' || txt.indexOf(q) !== -1;
                    li.style.display = show ? '' : 'none';
                    if (show) vis++;
                });
                clienteList.style.display = vis ? 'block' : 'none';
            }
            clienteInput.addEventListener('focus', filtra);
            clienteInput.addEventListener('input', filtra);
            opts.forEach(function (li) {
                li.addEventListener('click', function () {
                    var idSel = li.dataset.id || '';
                    clienteId.value = idSel;
                    nomeInput.value = li.dataset.nome || '';
                    telInput.value = li.dataset.telefone || '';
                    clienteInput.value = idSel ? (li.dataset.nome || '') : '';
                    clienteList.style.display = 'none';
                });
            });
            document.addEventListener('click', function (e) {
                if (!e.target.closest('.custom-dropdown-container')) clienteList.style.display = 'none';
            });
        }

        function servicosSelecionados() {
            return chips.filter(function (c) { return c.classList.contains('selected'); }).map(function (c) { return c.dataset.id; });
        }

        function carregarHorarios() {
            var servs = servicosSelecionados();
            if (servicosHidden) servicosHidden.value = servs.join(',');
            if (!barbeiroId || !dataInput.value || !servs.length) {
                horariosBox.className = 'bm-times bm-times-hint';
                horariosBox.textContent = 'Selecione os serviços e a data para ver os horários.';
                if (horarioHidden) horarioHidden.value = '';
                return;
            }
            horarioHidden.value = '';
            horariosBox.className = 'bm-times bm-times-hint';
            horariosBox.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Carregando horários...';
            var params = new URLSearchParams({
                barbeiro_id: barbeiroId, data: dataInput.value,
                itens_selecionados: servs.join(','), mode: 'admin_manual_completo', admin_mode: '1'
            });
            fetch('get_horarios.php?' + params.toString(), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (times) {
                    if (!Array.isArray(times) || !times.length) {
                        horariosBox.className = 'bm-times bm-times-hint';
                        horariosBox.textContent = 'Nenhum horário para este dia (sem expediente, folga ou férias).';
                        return;
                    }
                    horariosBox.className = 'bm-times';
                    horariosBox.innerHTML = '';
                    var grid = document.createElement('div');
                    grid.className = 'bm-times-grid';
                    var livres = 0;
                    times.forEach(function (t) {
                        var b = document.createElement('button');
                        b.type = 'button';
                        b.textContent = t.horario;
                        if (t.ocupado) {
                            b.className = 'horario-btn ocupado';
                            b.disabled = true;
                            b.title = 'Horário indisponível';
                        } else {
                            livres++;
                            b.className = 'horario-btn disponivel';
                            b.addEventListener('click', function () {
                                grid.querySelectorAll('.horario-btn').forEach(function (x) { x.classList.remove('selecionado'); });
                                b.classList.add('selecionado');
                                horarioHidden.value = t.horario;
                            });
                        }
                        grid.appendChild(b);
                    });
                    horariosBox.appendChild(grid);
                    var legend = document.createElement('div');
                    legend.className = 'bm-times-legend';
                    legend.innerHTML = '<span><i class="dot dot-livre"></i> Disponível</span><span><i class="dot dot-ocupado"></i> Ocupado</span>';
                    horariosBox.appendChild(legend);
                    if (!livres) {
                        var w = document.createElement('div');
                        w.className = 'bm-times-hint';
                        w.style.marginTop = '8px';
                        w.innerHTML = '<i class="fa fa-triangle-exclamation"></i> Todos os horários deste dia estão ocupados.';
                        horariosBox.appendChild(w);
                    }
                })
                .catch(function () {
                    horariosBox.className = 'bm-times bm-times-hint';
                    horariosBox.textContent = 'Não foi possível carregar os horários.';
                });
        }

        chips.forEach(function (c) {
            c.addEventListener('click', function () { c.classList.toggle('selected'); carregarHorarios(); });
        });
        if (dataInput) dataInput.addEventListener('change', carregarHorarios);

        form.addEventListener('submit', function (e) {
            var msg = '';
            if (!nomeInput.value.trim()) msg = 'Informe o nome do cliente.';
            else if (!telInput.value.trim()) msg = 'Informe o telefone.';
            else if (!servicosHidden.value) msg = 'Selecione ao menos um serviço.';
            else if (!dataInput.value) msg = 'Escolha a data.';
            else if (!horarioHidden.value) msg = 'Escolha um horário disponível.';
            if (msg) {
                e.preventDefault();
                if (window.Swal) Swal.fire({ icon: 'warning', title: 'Revise o agendamento', text: msg });
                else window.alert(msg);
            }
        });
    })();

    // ===================== REAGENDAR (modal no painel) =====================
    window.abrirReagendar = function (button, modal) {
        modal.querySelector('#rg_cliente').textContent = button.dataset.nome || '—';
        modal.querySelector('#rg_data_atual').textContent = button.dataset.dataFmt || '—';
        modal.querySelector('#rg_servicos_nomes').textContent = button.dataset.servicosNomes || '—';
        modal.querySelector('#rg_agendamento_id').value = button.dataset.id || '';
        modal.querySelector('#rg_servicos_ids').value = button.dataset.servicos || '';
        modal.querySelector('#rg_data').value = '';
        modal.querySelector('#rg_horario').value = '';
        var box = modal.querySelector('#rg_horarios');
        box.className = 'bm-times bm-times-hint';
        box.textContent = 'Selecione a nova data para ver os horários.';
    };

    (function initReagendar() {
        var rgForm = document.getElementById('rg-form');
        if (!rgForm) return;
        var barbeiroId = rgForm.dataset.barbeiroId || '';
        var dataInput = document.getElementById('rg_data');
        var box = document.getElementById('rg_horarios');
        var horarioHidden = document.getElementById('rg_horario');
        var servicosIdsInput = document.getElementById('rg_servicos_ids');

        if (dataInput) dataInput.min = new Date().toISOString().slice(0, 10);

        function carregar() {
            var servs = servicosIdsInput.value || '';
            if (!barbeiroId || !dataInput.value || !servs) return;
            horarioHidden.value = '';
            box.className = 'bm-times bm-times-hint';
            box.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Carregando horários...';
            var params = new URLSearchParams({
                barbeiro_id: barbeiroId, data: dataInput.value,
                itens_selecionados: servs, mode: 'admin_manual_completo', admin_mode: '1'
            });
            fetch('get_horarios.php?' + params.toString(), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (times) {
                    if (!Array.isArray(times) || !times.length) {
                        box.className = 'bm-times bm-times-hint';
                        box.textContent = 'Nenhum horário para este dia (sem expediente, folga ou férias).';
                        return;
                    }
                    box.className = 'bm-times';
                    box.innerHTML = '';
                    var grid = document.createElement('div');
                    grid.className = 'bm-times-grid';
                    var livres = 0;
                    times.forEach(function (t) {
                        var b = document.createElement('button');
                        b.type = 'button';
                        b.textContent = t.horario;
                        if (t.ocupado) {
                            b.className = 'horario-btn ocupado'; b.disabled = true; b.title = 'Horário indisponível';
                        } else {
                            livres++;
                            b.className = 'horario-btn disponivel';
                            b.addEventListener('click', function () {
                                grid.querySelectorAll('.horario-btn').forEach(function (x) { x.classList.remove('selecionado'); });
                                b.classList.add('selecionado');
                                horarioHidden.value = t.horario;
                            });
                        }
                        grid.appendChild(b);
                    });
                    box.appendChild(grid);
                    var legend = document.createElement('div');
                    legend.className = 'bm-times-legend';
                    legend.innerHTML = '<span><i class="dot dot-livre"></i> Disponível</span><span><i class="dot dot-ocupado"></i> Ocupado</span>';
                    box.appendChild(legend);
                    if (!livres) {
                        var w = document.createElement('div');
                        w.className = 'bm-times-hint'; w.style.marginTop = '8px';
                        w.innerHTML = '<i class="fa fa-triangle-exclamation"></i> Todos os horários deste dia estão ocupados.';
                        box.appendChild(w);
                    }
                })
                .catch(function () {
                    box.className = 'bm-times bm-times-hint';
                    box.textContent = 'Não foi possível carregar os horários.';
                });
        }

        if (dataInput) dataInput.addEventListener('change', carregar);

        rgForm.addEventListener('submit', function (e) {
            if (!horarioHidden.value) {
                e.preventDefault();
                if (window.Swal) Swal.fire({ icon: 'warning', title: 'Escolha um horário', text: 'Selecione o novo horário antes de salvar.' });
                else window.alert('Selecione o novo horário.');
            }
        });
    })();
    
    closeModalButtons.forEach(button => button.addEventListener('click', () => closeModal(button.closest('.modal-overlay'))));
    // Fechamento por clique no fundo desativado de propósito: evita perder o que
    // está sendo preenchido no modal (ex.: comanda) por um clique acidental. Fecha só pelo botão X.
    
    const selectMotivo = document.getElementById('ia-motivo');
    if (selectMotivo) {
        selectMotivo.addEventListener('change', function() {
            document.getElementById('ia-motivo-custom').style.display = this.value === 'Outro assunto (personalizado)' ? 'block' : 'none';
        });
    }

    const btnGerarIA = document.getElementById('btn-gerar-ia');
    if (btnGerarIA) {
        btnGerarIA.addEventListener('click', function() {
            const nomeCliente = document.getElementById('ia-nome-cliente').innerText;
            let motivo = document.getElementById('ia-motivo').value;
            if (motivo === 'Outro assunto (personalizado)') {
                motivo = document.getElementById('ia-motivo-custom').value;
                if (!motivo) { alert('Por favor, digite o assunto personalizado.'); return; }
            }

            const originalHtml = this.innerHTML;
            this.innerHTML = '<i class="fa fa-circle-notch fa-spin"></i> Processando...';
            this.disabled = true;

            fetch('ajax_gemini.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'mensagem_whatsapp_barbeiro', nome_cliente: nomeCliente, nome_barbeiro: nome_barbeiro_sessao, motivo: motivo })
            })
            .then(res => res.json())
            .then(data => {
                this.innerHTML = originalHtml; this.disabled = false;
                if (data.success) {
                    document.getElementById('ia-resultado-box').style.display = 'block';
                    document.getElementById('ia-texto-gerado').value = data.resposta;
                    const tel = document.getElementById('ia-telefone-cliente').value;
                    const textoUrl = encodeURIComponent(data.resposta);
                    document.getElementById('btn-enviar-whatsapp-ia').href = `https://wa.me/${tel}?text=${textoUrl}`;
                } else { alert('Erro no Assistente IA: ' + data.error); }
            })
            .catch(err => { this.innerHTML = originalHtml; this.disabled = false; alert('Erro de conexão com o Assistente IA.'); });
        });
    }
    
    const textAreaIA = document.getElementById('ia-texto-gerado');
    if (textAreaIA) {
        textAreaIA.addEventListener('input', function() {
            const tel = document.getElementById('ia-telefone-cliente').value;
            const textoUrl = encodeURIComponent(this.value);
            document.getElementById('btn-enviar-whatsapp-ia').href = `https://wa.me/${tel}?text=${textoUrl}`;
        });
    }

    const processButtons = document.querySelectorAll('.btn-process');
    processButtons.forEach(btn => {
        btn.addEventListener('click', function(e) {
            if (this.hasAttribute('onclick') && this.getAttribute('onclick').includes('confirm')) {
                setTimeout(() => { this.classList.add('loading'); this.innerHTML = '<i class="fa fa-circle-notch fa-spin"></i>'; }, 10);
            } else { this.classList.add('loading'); this.innerHTML = '<i class="fa fa-circle-notch fa-spin"></i>'; }
        });
    });

    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(e) {
            if(!this.classList.contains('modern-filter-form') && !this.classList.contains('search-wrapper')) {
                const btnSubmit = this.querySelector('button[type="submit"]');
                if (btnSubmit && !btnSubmit.disabled) {
                    const currentWidth = btnSubmit.offsetWidth;
                    btnSubmit.style.width = currentWidth + 'px';
                    btnSubmit.innerHTML = '<i class="fa fa-circle-notch fa-spin"></i> Processando...';
                    btnSubmit.style.opacity = '0.8'; btnSubmit.style.pointerEvents = 'none';
                }
            }
        });
    });

    const searchInput = document.getElementById('search-historico');
    const tabelaHistorico = document.getElementById('tabela-historico');
    if (searchInput && tabelaHistorico) {
        searchInput.addEventListener('input', function() {
            const termo = this.value.toLowerCase();
            const linhas = tabelaHistorico.querySelectorAll('tbody .t-row');
            linhas.forEach(linha => {
                const nomeCliente = linha.querySelector('.client-name-text').textContent.toLowerCase();
                linha.style.display = nomeCliente.includes(termo) ? '' : 'none';
            });
        });
    }
    
    // Notificação e Som: agora tratados pelo motor compartilhado
    // js/notif_agendamentos.js (cartões ricos + som, responsivo). O polling
    // antigo (que apontava para um #notification-bar inexistente nesta página)
    // foi removido para evitar erro e requisições duplicadas.
});
