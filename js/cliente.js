document.addEventListener('DOMContentLoaded', function() {
    
    // --- 1. LÓGICA DAS ABAS (TABS) ---
    const tabs = document.querySelectorAll('.nav-tab');
    const tabContents = document.querySelectorAll('.tabcontent');

    function activateAccountTab(tab, shouldScroll = false) {
        if (!tab) return;

        tabs.forEach(t => {
            t.classList.remove('active');
            t.setAttribute('aria-selected', 'false');
        });
        tabContents.forEach(c => c.classList.remove('active'));

        tab.classList.add('active');
        tab.setAttribute('aria-selected', 'true');

        const targetId = tab.dataset.tab;
        const targetContent = document.getElementById(targetId);
        if (targetContent) {
            targetContent.classList.add('active');
            if (shouldScroll) {
                targetContent.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', () => activateAccountTab(tab));
    });

    document.querySelectorAll('[data-tab-shortcut]').forEach(shortcut => {
        shortcut.addEventListener('click', () => {
            const targetTab = document.querySelector(`.nav-tab[data-tab="${shortcut.dataset.tabShortcut}"]`);
            activateAccountTab(targetTab, true);
        });
    });

    // --- 2. LÓGICA DE MODAIS ---
    const modals = document.querySelectorAll('.modal-overlay');
    const openModalButtons = document.querySelectorAll('[data-modal-target]');
    const closeModalButtons = document.querySelectorAll('.modal-close');

    function openModal(modal) {
        if (modal) {
            modal.style.display = 'flex';
            requestAnimationFrame(() => modal.classList.add('active'));
        }
    }

    function closeModal(modal) {
        if (modal) {
            modal.classList.remove('active');
            setTimeout(() => {
                if (!modal.classList.contains('active')) modal.style.display = 'none';
            }, 160);
        }
    }

    openModalButtons.forEach(button => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            const modal = document.querySelector(button.dataset.modalTarget);
            if (!modal) return;
            
            openModal(modal);

            // Preenche dados do modal específico se necessário
            if (button.dataset.modalTarget === '#modal-detalhes-agendamento') {
                const agId = button.dataset.agendamentoId;
                preencherDetalhesAgendamento(agId);
            }
            if (button.dataset.modalTarget === '#modal-reagendamento') {
                const agId = button.dataset.agendamentoId;
                preencherModalReagendamento(agId);
            }
            if (button.dataset.modalTarget === '#modal-avaliacao') {
                document.getElementById('avaliacao_agendamento_id').value = button.dataset.agendamentoId;
                document.getElementById('avaliacao_barbeiro_id').value = button.dataset.barbeiroId;
            }
        });
    });

    closeModalButtons.forEach(button => {
        button.addEventListener('click', () => {
            const modal = button.closest('.modal-overlay');
            closeModal(modal);
        });
    });

    // Fechamento por clique no fundo desativado de propósito: evita perder o que
    // está sendo preenchido no modal por um clique acidental. Fecha só pelo botão X.


    // --- 3. LÓGICA DE DETALHES DO AGENDAMENTO (PREMIUM COM ASSINATURA) ---
    function preencherDetalhesAgendamento(id) {
        const data = agendamentosDataById[id];
        const contentDiv = document.getElementById('detalhes-content');
        if (!data || !contentDiv) return;

        // Recupera nomes e valores
        let htmlServicos = '';
        let total = 0;
        
        if (data.servicos_ids) {
            data.servicos_ids.split(',').forEach(sid => {
                sid = sid.trim();
                let servicoNome = '';
                let servicoValor = 0;
                let isCombo = false;

                if (servicosData[sid]) {
                    servicoNome = servicosData[sid].nome;
                    servicoValor = parseFloat(servicosData[sid].valor);
                } else if (combosData[sid]) {
                    servicoNome = combosData[sid].nome;
                    servicoValor = parseFloat(combosData[sid].valor);
                    isCombo = true;
                }

                if(servicoNome) {
                    htmlServicos += `
                        <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 1rem; color: var(--text-main); font-weight: 500;">
                            <span>${isCombo ? '<i class="fa fa-layer-group" style="color:#94a3b8; margin-right:5px; font-size:0.9rem;"></i>' : '<i class="fa fa-cut" style="color:#94a3b8; margin-right:5px; font-size:0.9rem;"></i>'} ${servicoNome}</span>
                            <span>R$ ${servicoValor.toFixed(2).replace('.', ',')}</span>
                        </div>
                    `;
                    total += servicoValor;
                }
            });
        }
        
        const tipoDesconto = data.tipo_desconto || '';
        const isAssinatura = (tipoDesconto === 'plano' || tipoDesconto === 'assinatura_vip' || tipoDesconto === 'adesao_plano');
        const isAdesao = (tipoDesconto === 'adesao_plano' && data.plano_provisorio);

        // Renderizar a adesão do plano caso exista
        if (isAdesao && typeof planosData !== 'undefined' && planosData[data.plano_provisorio]) {
            const pValor = parseFloat(planosData[data.plano_provisorio].valor || 0);
            const pNome = planosData[data.plano_provisorio].nome;
            total += pValor;
            htmlServicos += `
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 1rem; color: #8b5cf6; font-weight: 700;">
                    <span><i class="fa fa-crown" style="margin-right:5px; font-size:0.9rem;"></i> Assinatura: ${pNome}</span>
                    <span>R$ ${pValor.toFixed(2).replace('.', ',')}</span>
                </div>
            `;
        }
        
        let htmlProdutos = '';
        if(data.produtos_vendidos) {
            try {
                const prods = JSON.parse(data.produtos_vendidos);
                if(prods.length > 0) {
                    htmlProdutos = `<div style="margin-top:15px; padding-top:15px; border-top: 1px dashed var(--border-soft);">
                        <strong style="display:block; margin-bottom: 10px; color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase;">Produtos Adquiridos:</strong>`;
                    prods.forEach(p => {
                        const pValor = parseFloat(p.valor || 0);
                        htmlProdutos += `
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.95rem; color: var(--text-main);">
                                <span><i class="fa fa-box" style="color:#94a3b8; margin-right:5px; font-size:0.9rem;"></i> ${p.nome}</span>
                                <span>R$ ${pValor.toFixed(2).replace('.', ',')}</span>
                            </div>
                        `;
                        total += pValor;
                    });
                    htmlProdutos += '</div>';
                }
            } catch(e){}
        }

        const desconto = parseFloat(data.desconto_aplicado || 0);
        let final = total - desconto;
        if(final < 0) final = 0;
        
        let labelDesconto = "Desconto Aplicado";
        if (isAdesao) labelDesconto = "Desconto (Plano Aplicado)";
        else if (tipoDesconto === 'plano' || tipoDesconto === 'assinatura_vip') labelDesconto = "Desconto (Barbearia por assinatura)";
        
        // Formata data e hora
        const partesData = data.data.split('-');
        const dataFormatada = `${partesData[2]}/${partesData[1]}/${partesData[0]}`; // DD/MM/YYYY

        // Configuração de Status
        let statusObj = { texto: 'Desconhecido', cor: '#64748b', bg: '#f1f5f9', borda: '#cbd5e1' };
        if (data.status === 'concluido') statusObj = { texto: 'Concluído', cor: '#166534', bg: '#dcfce7', borda: '#bbf7d0' };
        else if (data.status === 'pendente' || data.status === 'aguardando') statusObj = { texto: 'Pendente', cor: 'var(--app-accent-strong)', bg: 'var(--app-accent-soft)', borda: 'var(--app-accent-border)' };
        else if (data.status === 'aprovado') statusObj = { texto: 'Confirmado', cor: '#075985', bg: '#e0f2fe', borda: '#bae6fd' };
        else if (data.status.includes('cancelado')) statusObj = { texto: 'Cancelado', cor: '#991b1b', bg: '#fee2e2', borda: '#fecaca' };

        // Dados do Barbeiro
        const barbeiroInfo = barbeirosData[data.barbeiro_id] || { nome: 'Profissional Indisponível', foto: 'uploads/default-profile.jpg' };
        const barbeiroFoto = barbeiroInfo.foto || 'uploads/default-profile.jpg';

        // Tag de Assinatura
        let htmlAssinatura = '';
        if (isAssinatura) {
            htmlAssinatura = `
                <div style="background: linear-gradient(135deg, var(--app-accent-soft), #ffffff); padding: 12px 15px; border-radius: 12px; border: 1px solid var(--app-accent-border); margin-bottom: 20px; display: flex; align-items: center; gap: 10px; box-shadow: 0 4px 12px color-mix(in srgb, var(--app-accent), transparent 86%);">
                    <div style="background: white; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                        <i class="fa fa-crown" style="color: var(--app-accent); font-size: 0.9rem;"></i>
                    </div>
                    <div>
                        <strong style="color: var(--app-accent-strong); font-size: 0.95rem; display: block;">Barbearia por assinatura Ativa</strong>
                        <span style="color: var(--app-accent-strong); font-size: 0.85rem;">Serviço coberto pelo seu plano.</span>
                    </div>
                </div>
            `;
        }

        // Montagem do HTML Premium
        contentDiv.innerHTML = `
            <button class="modal-close" style="background: #f1f5f9; border: none; width: 36px; height: 36px; border-radius: 50%; position: absolute; top: 20px; right: 20px; cursor: pointer; color: #64748b; font-size: 1.2rem; display: flex; align-items: center; justify-content: center; transition: all 0.3s;"><i class="fa fa-times"></i></button>
            
            <div style="text-align: left; margin-bottom: 25px; padding-right: 40px;">
                <h3 style="margin: 0 0 10px; font-size: 1.5rem; font-weight: 800; color: var(--text-main); letter-spacing: -0.5px;">Resumo do Agendamento</h3>
                <span style="background: ${statusObj.bg}; color: ${statusObj.cor}; border: 1px solid ${statusObj.borda}; padding: 4px 12px; border-radius: 8px; font-size: 0.8rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; display: inline-block;">
                    ${statusObj.texto}
                </span>
            </div>

            ${htmlAssinatura}

            <div style="background: #f8fafc; border: 1px solid var(--border-soft); border-radius: 16px; padding: 20px; margin-bottom: 20px;">
                <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #e2e8f0;">
                    <img src="${barbeiroFoto}" style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover; border: 2px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                    <div>
                        <span style="display: block; font-size: 0.8rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Com</span>
                        <strong style="font-size: 1.1rem; color: var(--text-main); font-weight: 800;">${barbeiroInfo.nome}</strong>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div>
                        <span style="display: block; font-size: 0.8rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Data</span>
                        <strong style="color: var(--text-main); font-size: 1.05rem;"><i class="fa fa-calendar-day" style="color: var(--secondary-color, var(--app-accent)); margin-right: 5px;"></i> ${dataFormatada}</strong>
                    </div>
                    <div>
                        <span style="display: block; font-size: 0.8rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Horário</span>
                        <strong style="color: var(--text-main); font-size: 1.05rem;"><i class="fa fa-clock" style="color: var(--secondary-color, var(--app-accent)); margin-right: 5px;"></i> ${data.hora}</strong>
                    </div>
                </div>
            </div>

            <div style="margin-bottom: 25px;">
                <strong style="display:block; margin-bottom: 15px; color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase;">Serviços Agendados:</strong>
                <div style="margin-bottom: 15px;">
                    ${htmlServicos}
                </div>
                ${htmlProdutos}
            </div>
            
            <div style="background: #f8fafc; border-radius: 16px; padding: 20px; border: 1px solid var(--border-soft);">
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.95rem; color: var(--text-muted);">
                    <span>Subtotal</span>
                    <span>R$ ${total.toFixed(2).replace('.', ',')}</span>
                </div>
                ${desconto > 0 ? `
                <div style="display: flex; justify-content: space-between; margin-bottom: 15px; font-size: 0.95rem; color: #10b981; font-weight: 600;">
                    <span><i class="fa fa-tags"></i> ${labelDesconto}</span>
                    <span>- R$ ${desconto.toFixed(2).replace('.', ',')}</span>
                </div>` : ''}
                
                <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #e2e8f0; padding-top: 15px; margin-top: ${desconto > 0 ? '0' : '15px'};">
                    <strong style="font-size: 1.1rem; color: var(--text-main);">Valor Final</strong>
                    <strong style="font-size: 1.4rem; color: ${isAssinatura && final === 0 ? '#10b981' : 'var(--text-main)'}; font-weight: 900;">R$ ${final.toFixed(2).replace('.', ',')}</strong>
                </div>
            </div>
            
            ${(data.status === 'aprovado' || data.status === 'pendente') ? `
            <div style="margin-top: 25px;">
                <a href="cliente.php?action=cancelar&id=${data.id}&csrf_token=${encodeURIComponent(typeof minhaContaCsrfToken !== 'undefined' ? minhaContaCsrfToken : '')}" class="btn" style="width: 100%; display: flex; justify-content: center; background: white; color: #ef4444; border: 1px solid #fca5a5; padding: 14px; border-radius: 12px; font-weight: 700; text-decoration: none; transition: all 0.3s;" onclick="return confirm('Tem certeza que deseja cancelar este agendamento?');" onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background='white'">
                    <i class="fa fa-times-circle" style="margin-right: 8px;"></i> Cancelar Agendamento
                </a>
            </div>` : ''}
        `;
        
        // Reatacha evento de fechar no botão dinâmico
        const closeBtn = contentDiv.querySelector('.modal-close');
        if(closeBtn) closeBtn.addEventListener('click', () => closeModal(document.getElementById('modal-detalhes-agendamento')));
    }


    // --- 4. LÓGICA DE REAGENDAMENTO ---
    function preencherModalReagendamento(id) {
        const data = agendamentosDataById[id];
        if(!data) return;

        document.getElementById('reagendar_agendamento_id').value = id;
        document.getElementById('reagendar_barbeiro_id').value = data.barbeiro_id;
        document.getElementById('reagendar_servicos_ids').value = data.servicos_ids;
        
        // Limpa horários anteriores
        document.getElementById('reagendar_horarios-container').innerHTML = '<p style="grid-column: 1/-1; text-align:center; color:var(--text-muted); font-weight:500;">Selecione uma data para ver os horários.</p>';
        document.getElementById('reagendar_data').value = '';
    }

    const reagendarDataInput = document.getElementById('reagendar_data');
    if(reagendarDataInput) {
        reagendarDataInput.addEventListener('change', function() {
            const data = this.value;
            const barbeiroId = document.getElementById('reagendar_barbeiro_id').value;
            const servicosIds = document.getElementById('reagendar_servicos_ids').value;
            
            if(data && barbeiroId) {
                buscarHorariosDisponiveis(barbeiroId, data, servicosIds);
            }
        });
    }

    function buscarHorariosDisponiveis(barbeiroId, data, servicosIds) {
        const container = document.getElementById('reagendar_horarios-container');
        const inputHorario = document.getElementById('reagendar_horario');
        const erroMsg = document.getElementById('reagendar_erro_horario');
        
        container.innerHTML = '<div style="grid-column: 1/-1; text-align: center; color: var(--app-accent);"><i class="fa fa-spinner fa-spin" style="font-size: 1.5rem;"></i></div>';
        inputHorario.value = '';
        if(erroMsg) erroMsg.textContent = '';

        fetch(`get_horarios.php?barbeiro_id=${barbeiroId}&data=${data}&itens_selecionados=${servicosIds}`)
            .then(response => response.json())
            .then(horarios => {
                container.innerHTML = '';

                if (horarios.error) {
                    container.innerHTML = `<p class="erro" style="grid-column: 1/-1; color: #ef4444; background: #fef2f2; padding: 10px; border-radius: 8px;">${horarios.error}</p>`;
                    return;
                }
                
                if (!horarios || horarios.length === 0) {
                    container.innerHTML = '<p class="erro" style="grid-column: 1/-1; color: var(--app-accent-strong); background: var(--app-accent-soft); padding: 10px; border-radius: 8px; text-align:center;">Nenhum horário livre para esta data.</p>';
                    return;
                }

                horarios.forEach(h => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'disponivel'; 
                    btn.style.cssText = 'padding: 10px 5px; border: 1px solid #cbd5e1; background: #f8fafc; border-radius: 10px; cursor: pointer; font-weight: 600; color: #1e293b; transition: all 0.2s;';
                    btn.textContent = h;
                    
                    btn.onmouseover = () => { if(!btn.classList.contains('selecionado')) btn.style.background = '#e2e8f0'; };
                    btn.onmouseout = () => { if(!btn.classList.contains('selecionado')) btn.style.background = '#f8fafc'; };

                    btn.onclick = (e) => {
                        e.preventDefault();
                        container.querySelectorAll('.selecionado').forEach(b => {
                            b.classList.remove('selecionado');
                            b.style.background = '#f8fafc';
                            b.style.color = '#1e293b';
                            b.style.borderColor = '#cbd5e1';
                        });
                        btn.classList.add('selecionado');
                        btn.style.background = 'var(--app-accent)';
                        btn.style.color = 'white';
                        btn.style.borderColor = 'var(--app-accent)';
                        
                        inputHorario.value = h;
                        if(erroMsg) erroMsg.textContent = '';
                    };
                    
                    container.appendChild(btn);
                });
            })
            .catch(err => {
                console.error(err);
                container.innerHTML = '<p class="erro" style="grid-column: 1/-1; color: #ef4444; text-align:center;">Erro ao buscar horários.</p>';
            });
    }

    // --- 5. LÓGICA DE INDICAÇÃO (COPIAR) ---
    const btnCopiar = document.getElementById('btn-copiar-codigo');
    if(btnCopiar) {
        btnCopiar.addEventListener('click', () => {
            const codigo = btnCopiar.dataset.codigo;
            navigator.clipboard.writeText(codigo).then(() => {
                const originalHtml = btnCopiar.innerHTML;
                btnCopiar.innerHTML = '<span style="letter-spacing: normal;">Copiado!</span> <div style="background: #dcfce7; width: 45px; height: 45px; border-radius: 12px; display: flex; align-items: center; justify-content: center;"><i class="fa fa-check" style="color: #166534;"></i></div>';
                btnCopiar.style.borderColor = '#10b981';
                btnCopiar.style.color = '#166534';
                setTimeout(() => {
                    btnCopiar.innerHTML = originalHtml;
                    btnCopiar.style.borderColor = '';
                    btnCopiar.style.color = '';
                }, 2000);
            });
        });
    }

    // --- 6. CRONÔMETRO (COUNTDOWN) ---
    const countdownEl = document.getElementById('countdown');
    if(countdownEl) {
        const targetDate = new Date(countdownEl.dataset.datetime).getTime();
        
        const updateTimer = () => {
            const now = new Date().getTime();
            const distance = targetDate - now;

            if (distance < 0) {
                countdownEl.innerHTML = "AGORA!";
                return;
            }

            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));

            if(days > 0) {
                countdownEl.innerHTML = `${days}d ${hours}h ${minutes}m`;
            } else {
                countdownEl.innerHTML = `${String(hours).padStart(2,'0')}h ${String(minutes).padStart(2,'0')}m`;
            }
        };

        setInterval(updateTimer, 60000);
        updateTimer();
    }

});
