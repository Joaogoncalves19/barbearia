document.addEventListener('updateResumoNeeded', function() { 
    if (typeof updateResumo === 'function') { updateResumo(); } 
});

document.addEventListener('DOMContentLoaded', function() {
    if (typeof todosBarbeirosData !== 'undefined') { barbeirosData = todosBarbeirosData; }
    
    // Tratamento de aderência a plano (Checkbox)
    document.getElementById('aderir_plano')?.addEventListener('change', function() {
        document.getElementById('selecao-plano-container').style.display = this.checked ? 'block' : 'none';
        document.getElementById('plano_escolhido_id').required = this.checked;
        if (!this.checked) {
            document.getElementById('plano_escolhido_id').value = "";
            renderPlanoDetalhes('');
        }
        document.dispatchEvent(new Event('updateResumoNeeded'));
    });

    document.getElementById('plano_escolhido_id')?.addEventListener('change', function() {
        renderPlanoDetalhes(this.value);
        document.dispatchEvent(new Event('updateResumoNeeded'));
    });

    // Monta o cartão com validade, ciclo de cobrança e serviços ilimitados do plano.
    function renderPlanoDetalhes(planoId) {
        const box = document.getElementById('plano-detalhes-box');
        if (!box) return;

        const planos = (typeof planosData !== 'undefined') ? planosData : {};
        const servicos = (typeof servicosData !== 'undefined') ? servicosData : {};
        const plano = planoId ? planos[planoId] : null;

        if (!plano) { box.style.display = 'none'; return; }

        const formatBRL = (v) => 'R$ ' + (parseFloat(v) || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        document.getElementById('plano-det-nome').textContent = plano.nome || 'Plano de Assinatura';
        document.getElementById('plano-det-valor').textContent = formatBRL(plano.valor);

        const ul = document.getElementById('plano-det-servicos');
        const wrap = document.getElementById('plano-det-servicos-wrap');
        ul.innerHTML = '';
        const ids = (plano.servicos_ids || '').split(',').map(s => s.trim()).filter(Boolean);
        if (ids.length === 0) {
            wrap.style.display = 'none';
        } else {
            wrap.style.display = 'block';
            ids.forEach(id => {
                const sv = servicos[id];
                if (!sv) return;
                const li = document.createElement('li');
                const preco = parseFloat(sv.valor) > 0 ? `<span class="plano-det-preco">${formatBRL(sv.valor)}</span>` : '';
                li.innerHTML = `<i class="fa fa-check-circle"></i> <span>${sv.nome}</span> ${preco}`;
                ul.appendChild(li);
            });
        }

        const hoje = new Date();
        const fim = new Date(hoje.getTime() + 30 * 24 * 60 * 60 * 1000);
        const dfmt = (d) => d.toLocaleDateString('pt-BR');
        document.getElementById('plano-det-validade').innerHTML =
            `<i class="fa fa-circle-info" style="color:#94a3b8;"></i> Ao confirmar hoje, seu ciclo vale de <strong>${dfmt(hoje)}</strong> até <strong>${dfmt(fim)}</strong>, com renovação automática enquanto você mantiver a assinatura.`;

        box.style.display = 'block';
    }

    // Pré-seleções vindas do backend
    if (typeof barbeiroPreselecionado !== 'undefined' && barbeiroPreselecionado) { 
        const barbeiroOption = document.querySelector(`.custom-option[data-value="${barbeiroPreselecionado}"]`); 
        if (barbeiroOption) { setTimeout(() => { barbeiroOption.click(); }, 100); } 
    }
    if (typeof servicosPreselecionados !== 'undefined' && servicosPreselecionados.length > 0 && servicosPreselecionados[0] !== '') { 
        setTimeout(() => { 
            servicosPreselecionados.forEach(servicoId => { 
                const servicoBtn = document.querySelector(`.servico-btn[data-id="${servicoId}"]`); 
                if (servicoBtn && !servicoBtn.classList.contains('selected')) { servicoBtn.click(); } 
            }); 
        }, 200); 
    }

    // ==========================================
    // LÓGICA DE UPSELL (OFERTA DE SERVIÇOS ADICIONAIS)
    // ==========================================
    const servicosContainer = document.getElementById('servicos-container');
    
    function checkAndShowUpsell() {
        const upsellContainer = document.getElementById('upsell-container');
        const upsellItems = document.getElementById('upsell-items');
        
        const inputServicos = document.getElementById('servicos')?.value || '';
        const inputCombos = document.getElementById('combos_selecionados')?.value || '';
        
        const selectedServicos = inputServicos ? inputServicos.split(',') : [];
        const selectedCombos = inputCombos ? inputCombos.split(',') : [];
        
        if (selectedServicos.length === 0 && selectedCombos.length === 0) {
            if(upsellContainer) upsellContainer.style.display = 'none';
            return;
        }
        
        let servicosInclusos = [...selectedServicos];
        if (typeof combosData !== 'undefined') {
            selectedCombos.forEach(cId => {
                const c = combosData[cId.trim()];
                if (c && c.servicos_ids) {
                    const idsNoCombo = c.servicos_ids.split(',').map(s => s.trim());
                    servicosInclusos = [...new Set([...servicosInclusos, ...idsNoCombo])];
                }
            });
        }
        
        let availableUpsells = [];
        if (typeof Object.entries === 'function' && typeof servicosData !== 'undefined') {
            for (const [id, s] of Object.entries(servicosData)) {
                const slots = parseInt(s.slots) || 1;
                if (!servicosInclusos.includes(id) && slots <= 1) {
                    availableUpsells.push({ id: id, nome: s.nome, valor: s.valor, slots: slots });
                }
            }
        }
        
        availableUpsells = availableUpsells.sort(() => 0.5 - Math.random()).slice(0, 3);
        
        if (availableUpsells.length > 0 && upsellItems && upsellContainer) {
            upsellItems.innerHTML = '';
            availableUpsells.forEach(s => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'servico-btn upsell-btn';
                btn.innerHTML = `
                    <span>${s.nome} <br><small style="font-weight: normal; color: #b45309;">+ R$ ${s.valor}</small></span>
                    <span class="duracao"><i class="fa fa-plus-circle"></i> Adicionar (${s.slots * 30} min)</span>
                `;
                
                btn.onclick = function() {
                    const originalBtn = document.querySelector(`.servico-btn:not(.combo-btn):not(.upsell-btn)[data-id="${s.id}"]`);
                    if (originalBtn) {
                        originalBtn.click();
                        btn.innerHTML = `<span>${s.nome} <br><small style="color: #15803d;"><strong>Adicionado!</strong></small></span><span class="duracao" style="color:#15803d!important;"><i class="fa fa-check"></i></span>`;
                        btn.style.background = '#dcfce7';
                        btn.style.borderColor = '#22c55e';
                        btn.style.pointerEvents = 'none';
                        setTimeout(() => { checkAndShowUpsell(); }, 1500);
                    }
                };
                upsellItems.appendChild(btn);
            });
            upsellContainer.style.display = 'block';
        } else if (upsellContainer) {
            upsellContainer.style.display = 'none';
        }
    }

    if (servicosContainer) {
        servicosContainer.addEventListener('click', function(e) {
            const btn = e.target.closest('.servico-btn');
            if (btn && !btn.classList.contains('upsell-btn')) {
                setTimeout(checkAndShowUpsell, 150);
            }
        });
    }

    // ==========================================
    // PROTEÇÃO CONTRA DUPLO CLIQUE E LOADING DE CONFIRMAÇÃO
    // ==========================================
    const formAgendamento = document.getElementById('form-agendamento');
    
    if (formAgendamento) {
        formAgendamento.addEventListener('submit', function(e) {
            if (e.defaultPrevented) return;

            const submitBtn = this.querySelector('button[type="submit"]');
            
            // Se o botão já estiver desativado, impede o envio (evita duplo clique)
            if (submitBtn && submitBtn.disabled) {
                e.preventDefault();
                return;
            }

            // Exibe a tela de carregamento que já existe no seu HTML
            const overlay = document.getElementById('wizard-loading');
            const msgEl = document.getElementById('loading-msg');
            
            if (overlay && msgEl) {
                msgEl.innerText = 'Confirmando o seu agendamento, por favor aguarde...';
                overlay.classList.add('active'); // Mostra a tela cheia de carregamento
            }
            
            // Desabilita o botão e muda o texto, com um leve delay para não interromper o disparo do formulário
            if (submitBtn) {
                setTimeout(() => {
                    submitBtn.disabled = true;
                    submitBtn.style.opacity = '0.7';
                    submitBtn.style.cursor = 'not-allowed';
                    submitBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> A processar...';
                }, 50);
            }
        });
    }
});
