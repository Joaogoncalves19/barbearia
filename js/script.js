document.addEventListener('DOMContentLoaded', function () {
    // --- Elementos do DOM ---
    const form = document.getElementById('form-agendamento');
    if (!form) return;

    const barbeiroSelectContainer = document.querySelector('.custom-select-container');
    const barbeiroSelected = document.querySelector('.custom-select-selected');
    const barbeiroOptionsContainer = document.querySelector('.custom-select-items');
    const barbeiroInput = document.getElementById('barbeiro');
    
    const servicosContainer = document.getElementById('servicos-container');
    const servicosInput = document.getElementById('servicos'); 
    const combosInput = document.getElementById('combos_selecionados'); 
    
    const dataInput = document.getElementById('data');
    const horariosContainer = document.getElementById('horarios-container');
    const horarioInput = document.getElementById('horario');
    const horarioBarbeiroInput = document.getElementById('horario_barbeiro_id');
    const diasDisponiveisInfo = document.getElementById('dias-disponiveis-info');
    const telefoneInput = document.getElementById('telefone');

    const modal = document.getElementById('modal-barbeiro-detalhes');
    const modalContent = document.getElementById('barbeiro-detalhes-content');
    const closeModalBtn = modal ? modal.querySelector('.modal-close') : null;

    // ==========================================
    // MODAL DE PERFIL DO BARBEIRO
    // Extraído para função + delegação no documento, pois os cards de barbeiro
    // do wizard (.barber-card-selectable) não usam mais o container antigo
    // (.custom-select-items). Assim o botão "Perfil" funciona no layout atual.
    // ==========================================
    // Seguranca (S-02): comentario de avaliacao e texto livre de cliente; escapar
    // antes de montar HTML, senao um comentario com <img onerror> executava aqui.
    function escHtmlPerfil(valor) {
        return String(valor ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function abrirModalPerfilBarbeiro(barbeiroId) {
        const barbeiro = typeof barbeirosData !== 'undefined' ? barbeirosData.find(b => String(b.id) === String(barbeiroId)) : null;
        if (!barbeiro || !modalContent) return;

        let avaliacoesHtml = '<h4 style="margin: 0 0 15px; color: #475569; font-size: 1.1rem;"><i class="fa fa-star" style="color: #f59e0b;"></i> Avaliações Recentes</h4>';
        const avaliacoesDoBarbeiro = (typeof avaliacoesData !== 'undefined') ? avaliacoesData.filter(a => String(a.barbeiro_id) === String(barbeiroId)).slice(0, 5) : [];

        if (avaliacoesDoBarbeiro.length > 0) {
            avaliacoesHtml += '<ul class="lista-avaliacoes" style="list-style: none; padding: 0; margin: 0; max-height: 250px; overflow-y: auto;">';
            avaliacoesDoBarbeiro.forEach(av => {
                const rating = '★'.repeat(av.rating) + '☆'.repeat(5 - av.rating);
                avaliacoesHtml += `
                    <li style="margin-bottom: 15px; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px;">
                        <span class="estrelas-modal" style="color: #f59e0b; font-size: 1.15rem; letter-spacing: 2px;">${rating}</span>
                        <p style="margin: 8px 0 0; font-size: 0.95rem; color: #334155; font-style: italic; line-height: 1.5;">"${escHtmlPerfil(av.comment || 'O cliente deixou uma ótima nota, mas sem comentários.')}"</p>
                    </li>`;
            });
            avaliacoesHtml += '</ul>';
        } else {
            avaliacoesHtml += `
                <div style="text-align: center; padding: 25px 0; background: #f8fafc; border-radius: 12px; border: 1px dashed #cbd5e1;">
                    <i class="fa fa-comment-slash" style="font-size: 2rem; color: #cbd5e1; margin-bottom: 10px;"></i>
                    <p style="margin:0; color: #64748b; font-weight: 500;">Nenhuma avaliação registrada ainda.</p>
                </div>`;
        }

        modalContent.innerHTML = `
            <div class="modal-header-barbeiro" style="display: flex; align-items: center; gap: 20px; margin-bottom: 25px; border-bottom: 2px solid #f1f5f9; padding-bottom: 25px;">
                <div style="position: relative;">
                    <img src="${escHtmlPerfil(barbeiro.foto)}" alt="${escHtmlPerfil(barbeiro.nome)}" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 3px solid #f1f5f9; box-shadow: 0 4px 10px rgba(0,0,0,0.05);">
                    <div style="position: absolute; bottom: 0; right: 0; background: #10b981; width: 18px; height: 18px; border-radius: 50%; border: 3px solid #fff;" title="Ativo"></div>
                </div>
                <div>
                    <h3 style="margin: 0 0 8px; color: #1e293b; font-size: 1.4rem; font-weight: 800;">${escHtmlPerfil(barbeiro.nome)}</h3>
                    <span style="font-size: 0.8rem; font-weight: 700; color: #fff; background: var(--app-accent, #f59e0b); padding: 5px 12px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.5px;">Profissional</span>
                </div>
            </div>
            ${avaliacoesHtml}
        `;

        if (modal) {
            modal.style.display = 'flex';
            // O timeout permite que o display flex seja processado antes da classe active disparar a opacidade
            setTimeout(() => { modal.classList.add('active'); }, 10);
        }
    }

    // Delegação global: funciona tanto nos cards novos quanto em qualquer layout.
    document.addEventListener('click', function (e) {
        const detBtn = e.target.closest('.btn-detalhes-barbeiro');
        if (detBtn) {
            e.preventDefault();
            e.stopPropagation();
            abrirModalPerfilBarbeiro(detBtn.dataset.barbeiroId);
        }
    });

    let diasDisponiveisCache = {};
    let descontoAplicadoPreview = null;
    let valorTotalEstimadoAtual = 0;

    function formatCurrency(value) {
        return Number(value || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    function getCsrfToken() {
        return document.querySelector('input[name="csrf_token"]')?.value || '';
    }


    function updateFormState() {
        const selectedBarbeiroId = barbeiroInput.value;
        const selectedItems = getSelectedItemsInfo(); 

        updateBarberAvailability(selectedItems.itemIds); 
        updateServiceAvailability(selectedBarbeiroId); 
        getHorariosDisponiveis();
        updateResumo();
    }

    document.addEventListener('click', async function(e) {
        const favoriteBtn = e.target.closest('.btn-favorite-barber');
        if (favoriteBtn) {
            e.preventDefault();
            e.stopPropagation();

            if (typeof isClienteLogado === 'undefined' || !isClienteLogado) {
                mostrarErroWizard('Entre na sua conta para favoritar barbeiros.');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'toggle_favorite_barber');
            formData.append('csrf_token', getCsrfToken());
            formData.append('barbeiro_id', favoriteBtn.dataset.barbeiroId || '');

            favoriteBtn.disabled = true;
            try {
                const response = await fetch('agendamento.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (!result.success) {
                    mostrarErroWizard(result.message || 'Nao foi possivel atualizar favorito.');
                    return;
                }

                document.querySelectorAll(`.btn-favorite-barber[data-barbeiro-id="${favoriteBtn.dataset.barbeiroId}"]`).forEach(btn => {
                    btn.classList.toggle('active', result.favorite);
                    btn.innerHTML = `<i class="${result.favorite ? 'fa' : 'far'} fa-star"></i>`;
                    btn.title = result.favorite ? 'Remover dos favoritos' : 'Favoritar barbeiro';
                    btn.setAttribute('aria-label', btn.title);
                });

                document.querySelectorAll(`.barber-card-selectable[data-value="${favoriteBtn.dataset.barbeiroId}"]`).forEach(card => {
                    card.classList.toggle('is-favorite', result.favorite);
                    let badge = card.querySelector('.favorite-badge');
                    if (result.favorite && !badge) {
                        badge = document.createElement('span');
                        badge.className = 'favorite-badge';
                        badge.innerHTML = '<i class="fa fa-star"></i> Favorito';
                        card.querySelector('.barber-rating')?.insertAdjacentElement('afterend', badge);
                    } else if (!result.favorite && badge) {
                        badge.remove();
                    }
                });
            } catch (error) {
                mostrarErroWizard('Nao foi possivel atualizar favorito agora.');
            } finally {
                favoriteBtn.disabled = false;
            }
        }
    });

    function getSelectedItemsInfo() {
        const selectedItemIds = []; 
        const selectedComboIds = []; 
        let allUniqueServiceIds = []; 
        let totalSlots = 0;

        if (servicosContainer) {
            servicosContainer.querySelectorAll('.servico-btn:not(.combo-btn).selected').forEach(btn => {
                const id = btn.dataset.id;
                selectedItemIds.push(id);
                allUniqueServiceIds.push(id);
                totalSlots += parseInt(btn.dataset.slots || 1); 
            });

            servicosContainer.querySelectorAll('.servico-btn.combo-btn.selected').forEach(btn => {
                const id = btn.dataset.id; 
                selectedItemIds.push(id); 
                selectedComboIds.push(id); 
                allUniqueServiceIds.push(id); 
                totalSlots += parseInt(btn.dataset.slots || 1); 
            });
        }

        if(servicosInput) servicosInput.value = allUniqueServiceIds.join(',');
        if(combosInput) combosInput.value = selectedComboIds.join(',');

        return { itemIds: selectedItemIds, totalSlots: totalSlots };
    }

    function updateBarberAvailability(itemIds) {
        const cardOptions = document.querySelectorAll('.barber-card-selectable[data-value]');
        if (cardOptions.length) {
            cardOptions.forEach(card => {
                const barbeiroId = card.dataset.value;
                if (barbeiroId === 'qualquer') return;

                const barbeiro = typeof barbeirosData !== 'undefined' ? barbeirosData.find(b => b.id === barbeiroId) : null;
                let disabled = false;

                if (!barbeiro) {
                    disabled = true;
                } else if (itemIds.length > 0) {
                    const especialidades = barbeiro.servicos_ids ? barbeiro.servicos_ids.split(',') : [];
                    disabled = especialidades.length === 0 || itemIds.some(itemId => !especialidades.includes(itemId));
                }

                card.classList.toggle('disabled', disabled);
                card.setAttribute('aria-disabled', disabled ? 'true' : 'false');
                card.title = disabled ? 'Este profissional nao realiza todos os servicos selecionados.' : '';
            });

            const selectedCard = document.querySelector(`.barber-card-selectable[data-value="${barbeiroInput.value}"]`);
            if (selectedCard && selectedCard.classList.contains('disabled')) {
                selectedCard.classList.remove('selected');
                barbeiroInput.value = '';
                barbeiroInput.dispatchEvent(new Event('change'));
            }
        }

        if (!barbeiroOptionsContainer) return;
        const barbeiroOpcoes = barbeiroOptionsContainer.querySelectorAll('.custom-option-wrapper');
        barbeiroOpcoes.forEach(wrapper => {
            const option = wrapper.querySelector('.custom-option');
            const barbeiroId = option.dataset.value;
            if (barbeiroId === 'qualquer') return; 
            const barbeiro = typeof barbeirosData !== 'undefined' ? barbeirosData.find(b => b.id === barbeiroId) : null;
            if (!barbeiro) { wrapper.classList.add('disabled'); return; }
            if (itemIds.length === 0) { wrapper.classList.remove('disabled'); return; }
            const especialidades = barbeiro.servicos_ids ? barbeiro.servicos_ids.split(',') : [];
            if (especialidades.length === 0) { wrapper.classList.add('disabled'); return; }
            let podeRealizarTodos = true;
            for (const itemId of itemIds) {
                if (!especialidades.includes(itemId)) { podeRealizarTodos = false; break; }
            }
            if (podeRealizarTodos) { wrapper.classList.remove('disabled'); } else { wrapper.classList.add('disabled'); }
        });
        const selectedBarbeiroId = barbeiroInput.value;
        if (selectedBarbeiroId && selectedBarbeiroId !== 'qualquer') {
            const selectedWrapper = barbeiroOptionsContainer.querySelector(`.custom-option[data-value="${selectedBarbeiroId}"]`)?.closest('.custom-option-wrapper');
            if (selectedWrapper && selectedWrapper.classList.contains('disabled')) {
                barbeiroSelected.innerHTML = 'Selecione um barbeiro';
                barbeiroInput.value = '';
            }
        }
    }

    function updateServiceAvailability(selectedBarbeiroId) {
        if (!servicosContainer) return;
        let especialidades = []; 
        if (selectedBarbeiroId && selectedBarbeiroId !== 'qualquer') {
            const barbeiro = typeof barbeirosData !== 'undefined' ? barbeirosData.find(b => b.id === selectedBarbeiroId) : null;
            if (barbeiro && barbeiro.servicos_ids) { especialidades = barbeiro.servicos_ids.split(','); }
        } else {
            servicosContainer.querySelectorAll('.servico-btn.disabled').forEach(btn => { btn.classList.remove('disabled'); });
            return;
        }
        if (especialidades.length === 0) {
             servicosContainer.querySelectorAll('.servico-btn').forEach(btn => { btn.classList.add('disabled'); });
             // Não deixa nenhum item preso em "selected" + "disabled" (senão o
             // usuário não consegue nem enxergar nem desmarcar a escolha).
             servicosContainer.querySelectorAll('.servico-btn.selected').forEach(btn => { btn.classList.remove('selected'); });
             return;
        }
        servicosContainer.querySelectorAll('.servico-btn:not(.combo-btn)').forEach(btn => {
            if (especialidades.includes(btn.dataset.id)) { btn.classList.remove('disabled'); } else { btn.classList.add('disabled'); }
        });
        servicosContainer.querySelectorAll('.servico-btn.combo-btn').forEach(btn => {
            if (especialidades.includes(btn.dataset.id)) { btn.classList.remove('disabled'); } else { btn.classList.add('disabled'); }
        });
        servicosContainer.querySelectorAll('.servico-btn.selected.disabled').forEach(btn => { btn.classList.remove('selected'); });
    }

    if (barbeiroSelectContainer) {
        barbeiroSelected.addEventListener('click', () => { barbeiroOptionsContainer.classList.toggle('show'); });
        
        barbeiroOptionsContainer.addEventListener('click', (e) => {
            const detalhesBtn = e.target.closest('.btn-detalhes-barbeiro');
            const option = e.target.closest('.custom-option');
            
            // ==========================================
            // LÓGICA DO MODAL DE PERFIL DO BARBEIRO
            // ==========================================
            if (detalhesBtn) {
                e.stopPropagation();
                abrirModalPerfilBarbeiro(detalhesBtn.dataset.barbeiroId);
                return;
            }

            // ==========================================
            // LÓGICA DE SELEÇÃO DO BARBEIRO
            // ==========================================
            if (option) {
                const wrapper = option.closest('.custom-option-wrapper');
                if (!wrapper || (wrapper && !wrapper.classList.contains('disabled'))) {
                    barbeiroSelected.innerHTML = option.innerHTML;
                    barbeiroInput.value = option.dataset.value;
                    barbeiroOptionsContainer.classList.remove('show');
                    updateFormState(); 
                }
            }
        });
        
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.custom-select-container')) { barbeiroOptionsContainer.classList.remove('show'); }
        });
    }

    if (servicosContainer) {
        servicosContainer.addEventListener('click', function (e) {
            const target = e.target.closest('.servico-btn');
            if (!target) return;
            // Um item já selecionado SEMPRE pode ser desmarcado, mesmo que tenha
            // ficado "disabled" (ex.: profissional trocado que não realiza este
            // serviço). Só bloqueamos a SELEÇÃO de itens desabilitados.
            if (target.classList.contains('disabled') && !target.classList.contains('selected')) return;
            const maxServicos = (typeof agendamentoConfig !== 'undefined') ? (agendamentoConfig.max_servicos || 4) : 4;
            const { totalSlots } = getSelectedItemsInfo();
            if (!target.classList.contains('selected')) {
                let slotsDoItemNovo = parseInt(target.dataset.slots || 1);
                if ((totalSlots + slotsDoItemNovo) > maxServicos) {
                     alert(`Você pode selecionar no máximo ${maxServicos} slots de serviço.`);
                     return;
                }
            }
            target.classList.toggle('selected');
            const isCombo = target.classList.contains('combo-btn');
            const idDoTarget = target.dataset.id;
            const servicosIdsDoTarget = target.dataset.servicosIds ? target.dataset.servicosIds.split(',').map(s => s.trim()) : [idDoTarget];
            if (target.classList.contains('selected')) {
                if (isCombo) {
                    servicosContainer.querySelectorAll('.servico-btn:not(.combo-btn).selected').forEach(btn => {
                        if (servicosIdsDoTarget.includes(btn.dataset.id)) btn.classList.remove('selected');
                    });
                } else {
                    servicosContainer.querySelectorAll('.servico-btn.combo-btn.selected').forEach(btn => {
                        const idsDoComboBtn = btn.dataset.servicosIds ? btn.dataset.servicosIds.split(',').map(s => s.trim()) : [];
                        if (idsDoComboBtn.includes(idDoTarget)) btn.classList.remove('selected');
                    });
                }
            }
            updateFormState(); 
        });
    }

    if (dataInput) {
        flatpickr(dataInput, {
            locale: "pt", dateFormat: "Y-m-d", minDate: "today",
            maxDate: new Date().fp_incr((typeof agendamentoConfig !== 'undefined') ? (agendamentoConfig.antecedencia_maxima || 30) : 30),
            disableMobile: true,
            onMonthChange: function(selectedDates, dateStr, instance) { buscarDiasDisponiveis(instance); },
            onOpen: function(selectedDates, dateStr, instance) { buscarDiasDisponiveis(instance); },
            onChange: function(selectedDates, dateStr, instance) { horarioInput.value = ''; horarioBarbeiroInput.value = ''; updateFormState(); }
        });
    }

    async function buscarDiasDisponiveis(instance) {
        const barbeiroId = barbeiroInput.value;
        const { itemIds, totalSlots } = getSelectedItemsInfo();
        let numServicosParaCalculo = totalSlots === 0 ? 1 : totalSlots; 
        
        if (totalSlots === 0) { 
            if(diasDisponiveisInfo) diasDisponiveisInfo.innerHTML = '<span style="color: #f59e0b;"><i class="fa fa-info-circle"></i> Selecione um serviço primeiro.</span>'; 
        } else { 
            if(diasDisponiveisInfo) diasDisponiveisInfo.innerHTML = '<span style="color: #64748b;"><i class="fa fa-spinner fa-spin"></i> Buscando dias...</span>'; 
        }
        
        const mes = instance.currentMonth + 1;
        const ano = instance.currentYear;
        const cacheKey = `${barbeiroId}-${numServicosParaCalculo}-${itemIds.join('-')}-${mes}-${ano}`;
        instance.calendarContainer.querySelectorAll('.available').forEach(d => d.classList.remove('available'));
        
        let dias = [];
        if (diasDisponiveisCache[cacheKey]) { dias = diasDisponiveisCache[cacheKey]; } else {
            try {
                const response = await fetch(`get_horarios.php?mode=dias_disponiveis&barbeiro_id=${barbeiroId}&num_servicos_total=${numServicosParaCalculo}&mes=${mes}&ano=${ano}&itens_selecionados=${itemIds.join(',')}`);
                const data = await response.json();
                dias = data.dias || [];
                diasDisponiveisCache[cacheKey] = dias;
            } catch (error) { 
                if(diasDisponiveisInfo) diasDisponiveisInfo.innerHTML = '<span style="color: #ef4444;"><i class="fa fa-exclamation-triangle"></i> Erro ao carregar.</span>'; 
                return; 
            }
        }
        
        if (dias.length === 0) { 
            if(diasDisponiveisInfo) diasDisponiveisInfo.innerHTML = '<span style="color: #ef4444;"><i class="fa fa-calendar-times"></i> Nenhum dia disponível.</span>'; 
            return; 
        }
        
        if (dias.length > 0 && diasDisponiveisInfo) { 
            if (totalSlots === 0) {
                diasDisponiveisInfo.innerHTML = '<span style="color: #f59e0b;"><i class="fa fa-info-circle"></i> Selecione serviços para ver as datas.</span>';
            } else {
                diasDisponiveisInfo.innerHTML = '<span style="color: #10b981;"><i class="fa fa-check-circle"></i> Datas carregadas!</span>';
                setTimeout(() => { if(diasDisponiveisInfo) diasDisponiveisInfo.innerHTML = ''; }, 2500);
            }
        } 
        
        dias.forEach(dia => {
            const diaElem = instance.calendarContainer.querySelector(`.flatpickr-day[aria-label$=" ${dia}, ${ano}"]`);
            if (diaElem) diaElem.classList.add('available');
        });
    }

    async function getHorariosDisponiveis() {
        const barbeiroId = barbeiroInput.value;
        const data = dataInput.value;
        const { itemIds, totalSlots } = getSelectedItemsInfo();
        let numServicosParaCalculo = totalSlots === 0 ? 1 : totalSlots;
        
        horariosContainer.innerHTML = '';
        horarioInput.value = '';
        horarioBarbeiroInput.value = '';
        
        if (!barbeiroId || !data) { 
            horariosContainer.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; padding: 20px; background: #ffffff; border: 1px dashed #cbd5e1; border-radius: 10px; color: #94a3b8;">Selecione um barbeiro e uma data para ver os horários.</div>'; 
            return; 
        }
        
        if (totalSlots === 0 && barbeiroId !== 'qualquer') {
             const barbeiro = typeof barbeirosData !== 'undefined' ? barbeirosData.find(b => b.id === barbeiroId) : null;
             if (barbeiro && barbeiro.servicos_ids) { 
                 horariosContainer.innerHTML = '<p class="erro" style="text-align: left; margin: 0; color: #f59e0b;"><i class="fa fa-info-circle"></i> Selecione os serviços desejados.</p>'; 
                 return; 
             }
        }
        
        horariosContainer.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; color: #64748b;"><i class="fa fa-spinner fa-spin"></i> Carregando horários...</div>';
        
        try {
            const response = await fetch(`get_horarios.php?barbeiro_id=${barbeiroId}&data=${data}&num_servicos_total=${numServicosParaCalculo}&itens_selecionados=${itemIds.join(',')}`);
            if (!response.ok) throw new Error('Erro na rede');
            const horarios = await response.json();
            
            if (horarios.error) { horariosContainer.innerHTML = `<p class="erro" style="text-align: left; margin: 0; color: #ef4444;"><i class="fa fa-times-circle"></i> ${horarios.error}</p>`; return; }
            
            horariosContainer.innerHTML = ''; 
            
            if (horarios.length === 0) { 
                horariosContainer.innerHTML = '<p class="erro" style="text-align: left; margin: 0; color: #ef4444;"><i class="fa fa-calendar-times"></i> Nenhum horário disponível neste dia.</p>'; 
                return; 
            }

            horariosContainer.classList.remove('horario-grid');

            const grupos = {
                manha: { titulo: '<i class="fa fa-sun" style="color: #f59e0b;"></i> Manhã', btns: [] },
                tarde: { titulo: '<i class="fa fa-cloud-sun" style="color: #f97316;"></i> Tarde', btns: [] },
                noite: { titulo: '<i class="fa fa-moon" style="color: #6366f1;"></i> Noite', btns: [] }
            };

            horarios.forEach(h => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'disponivel horario-btn';
                
                let timeStr = '';
                if (typeof h === 'object') {
                    btn.textContent = h.horario;
                    btn.innerHTML += `<span class="horario-qualquer-barbeiro">${h.barbeiro_nome}</span>`;
                    btn.dataset.barbeiroId = h.barbeiro_id;
                    btn.dataset.horario = h.horario;
                    timeStr = h.horario;
                } else {
                    btn.textContent = h;
                    btn.dataset.horario = h;
                    timeStr = h;
                }

                btn.addEventListener('click', () => {
                    horariosContainer.querySelectorAll('button').forEach(b => b.classList.remove('selecionado'));
                    btn.classList.add('selecionado');
                    horarioInput.value = btn.dataset.horario;
                    horarioBarbeiroInput.value = btn.dataset.barbeiroId || '';
                    updateResumo();
                });

                const hour = parseInt(timeStr.split(':')[0], 10);
                if (hour < 12) grupos.manha.btns.push(btn);
                else if (hour < 18) grupos.tarde.btns.push(btn);
                else grupos.noite.btns.push(btn);
            });

            Object.keys(grupos).forEach(key => {
                if (grupos[key].btns.length > 0) {
                    const groupDiv = document.createElement('div');
                    groupDiv.className = 'horarios-grupo';
                    groupDiv.style.marginBottom = '20px';
                    groupDiv.style.width = '100%';

                    const titulo = document.createElement('h5');
                    titulo.innerHTML = grupos[key].titulo;
                    titulo.style.cssText = 'color: #475569; margin: 0 0 15px 0; font-size: 1.1rem; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; display: flex; align-items: center; gap: 8px;';

                    const grid = document.createElement('div');
                    grid.className = 'horario-grid'; 
                    grid.style.cssText = 'display: grid; grid-template-columns: repeat(auto-fill, minmax(75px, 1fr)); gap: 12px;';

                    grupos[key].btns.forEach(b => grid.appendChild(b));

                    groupDiv.appendChild(titulo);
                    groupDiv.appendChild(grid);
                    horariosContainer.appendChild(groupDiv);
                }
            });

        } catch (error) { 
            horariosContainer.innerHTML = '<p class="erro" style="text-align: left; margin: 0; color: #ef4444;"><i class="fa fa-exclamation-triangle"></i> Erro ao carregar os horários.</p>'; 
        }
    }

    // ==========================================
    // FUNÇÃO DE FECHAR MODAL COM TRANSIÇÃO CSS
    // ==========================================
    function fecharModal() {
        if (modal) {
            modal.classList.remove('active');
            // Aguarda o fim da transição do CSS (0.3s) para dar display: none
            setTimeout(() => {
                modal.style.display = 'none';
            }, 300);
        }
    }

    if (closeModalBtn) closeModalBtn.onclick = fecharModal;
    // Não fecha ao clicar no fundo (comportamento consistente com os demais modais); fecha só pelo botão X.

    function updateResumo() {
        const barbeiroId = barbeiroInput.value;
        const data = dataInput.value;
        const hora = horarioInput.value;
        const { itemIds, totalSlots } = getSelectedItemsInfo();

        if (itemIds.length === 0) {
            const confirmacao = document.getElementById('confirmacao-resumo-premium');
            if (confirmacao) {
                confirmacao.innerHTML = `
                    <div class="confirmation-empty">
                        <i class="fa fa-clipboard-check"></i>
                        <p>Seu resumo aparecera aqui assim que voce escolher servicos, data e horario.</p>
                    </div>
                `;
            }
            return;
        }

        const aderirPlanoCheckbox = document.getElementById('aderir_plano');
        const planoSelecionadoId = document.getElementById('plano_escolhido_id')?.value;
        const estaAderindo = aderirPlanoCheckbox && aderirPlanoCheckbox.checked && planoSelecionadoId;
        
        let planoAtivo = null;
        if (estaAderindo) {
            if (typeof planosData !== 'undefined') planoAtivo = planosData[planoSelecionadoId];
        } else if (typeof clienteAssinaturaAtiva !== 'undefined' && clienteAssinaturaAtiva) {
            if (typeof planosData !== 'undefined') planoAtivo = planosData[clienteAssinaturaAtiva.plano_id];
        }

        let servicosInclusos = [];
        if (planoAtivo && planoAtivo.servicos_ids) servicosInclusos = planoAtivo.servicos_ids.split(',').map(s => s.trim());

        let valorTotal = 0;
        const itensSelecionadosResumo = [];

        itemIds.forEach(itemId => {
            if (itemId.startsWith('cb-')) {
                const combo = (typeof combosData !== 'undefined') ? combosData[itemId] : null;
                if(combo) {
                    itensSelecionadosResumo.push(combo.nome);
                    const valorOriginal = parseFloat(combo.valor);
                    if (planoAtivo) {
                        const servicosDoCombo = combo.servicos_ids.split(',');
                        let itensCobertosCount = 0;
                        let custoItensNaoCobertos = 0;
                        servicosDoCombo.forEach(sid => {
                            const cleanSid = sid.trim();
                            if (servicosInclusos.includes(cleanSid)) { itensCobertosCount++; } 
                            else { if (servicosData[cleanSid]) custoItensNaoCobertos += parseFloat(servicosData[cleanSid].valor); }
                        });
                        if (itensCobertosCount > 0) {
                            const valorFinal = custoItensNaoCobertos;
                            valorTotal += valorFinal;
                        } else { valorTotal += valorOriginal; }
                    } else { valorTotal += valorOriginal; }
                }
            } else if (itemId.startsWith('sv-')) {
                const servico = (typeof servicosData !== 'undefined') ? servicosData[itemId] : null;
                if(servico) {
                    itensSelecionadosResumo.push(servico.nome);
                    const valorOriginal = parseFloat(servico.valor);
                    if (!(planoAtivo && servicosInclusos.includes(itemId))) valorTotal += valorOriginal;
                }
            }
        });

        if (estaAderindo && planoAtivo) {
            const valorPlano = parseFloat(planoAtivo.valor);
            valorTotal += valorPlano;
        }

        valorTotalEstimadoAtual = valorTotal;
        let descontoPreview = 0;
        if (descontoAplicadoPreview && descontoAplicadoPreview.discount > 0) {
            descontoPreview = Math.min(valorTotal, parseFloat(descontoAplicadoPreview.discount));
        }

        const valorFinalEstimado = Math.max(0, valorTotal - descontoPreview);
        const dataFormatada = data ? new Date(data + 'T00:00:00').toLocaleDateString('pt-BR') : '';
        const confirmacao = document.getElementById('confirmacao-resumo-premium');
        if (confirmacao) {
            const barbeiroResumo = (() => {
                if (!barbeiroId) return 'A definir';
                let nomeBarbeiro = 'Qualquer Barbeiro';
                if (barbeiroId !== 'qualquer') {
                    const barbeiro = typeof barbeirosData !== 'undefined' ? barbeirosData.find(b => b.id === barbeiroId) : null;
                    if (barbeiro) nomeBarbeiro = barbeiro.nome;
                }
                const barbeiroDoHorarioId = horarioBarbeiroInput.value;
                if (barbeiroDoHorarioId) {
                    const barbeiroH = typeof barbeirosData !== 'undefined' ? barbeirosData.find(b => b.id === barbeiroDoHorarioId) : null;
                    if (barbeiroH) nomeBarbeiro = barbeiroH.nome;
                }
                return nomeBarbeiro;
            })();

            confirmacao.innerHTML = `
                <div class="confirmation-header">
                    <div>
                        <h4>Confirmacao do agendamento</h4>
                        <small>Confira tudo antes de finalizar</small>
                    </div>
                    <div class="confirmation-total">${formatCurrency(valorFinalEstimado)}</div>
                </div>
                <div class="confirmation-grid">
                    <div class="confirmation-item"><span>Profissional</span><strong>${barbeiroResumo}</strong></div>
                    <div class="confirmation-item"><span>Data e hora</span><strong>${dataFormatada || 'Escolha uma data'} ${hora ? 'as ' + hora : ''}</strong></div>
                    <div class="confirmation-item"><span>Servicos</span><strong>${itensSelecionadosResumo.join(', ') || 'Nenhum servico selecionado'}</strong></div>
                    <div class="confirmation-item"><span>Duracao</span><strong>${totalSlots > 0 ? (totalSlots * 30) + ' minutos' : 'A calcular'}</strong></div>
                    ${descontoPreview > 0 ? `<div class="confirmation-item"><span>Desconto</span><strong>${descontoAplicadoPreview.label}: - ${formatCurrency(descontoPreview)}</strong></div>` : ''}
                </div>
            `;
        }
    }

    const cupomInput = document.getElementById('cupom');
    const cupomFeedback = document.getElementById('cupom-feedback');
    const btnAplicarCupom = document.getElementById('btn-aplicar-cupom');

    if (cupomInput) {
        cupomInput.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
            descontoAplicadoPreview = null;
            if (cupomFeedback) {
                cupomFeedback.textContent = '';
                cupomFeedback.className = 'discount-feedback';
            }
            updateResumo();
        });
    }

    if (btnAplicarCupom) {
        btnAplicarCupom.addEventListener('click', async function() {
            if (!cupomInput || !cupomInput.value.trim()) {
                if (cupomFeedback) {
                    cupomFeedback.textContent = 'Informe um codigo para aplicar.';
                    cupomFeedback.className = 'discount-feedback error';
                }
                return;
            }

            if (typeof isClienteLogado !== 'undefined' && !isClienteLogado) {
                mostrarErroWizard('Entre na sua conta para validar cupons e vouchers.');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'validate_discount_code');
            formData.append('csrf_token', getCsrfToken());
            formData.append('codigo', cupomInput.value.trim());
            formData.append('valor_total', String(valorTotalEstimadoAtual || 0));

            btnAplicarCupom.disabled = true;
            btnAplicarCupom.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';

            try {
                const response = await fetch('agendamento.php', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    descontoAplicadoPreview = result;
                    if (cupomFeedback) {
                        cupomFeedback.textContent = result.message || 'Codigo aplicado.';
                        cupomFeedback.className = 'discount-feedback success';
                    }
                } else {
                    descontoAplicadoPreview = null;
                    if (cupomFeedback) {
                        cupomFeedback.textContent = result.message || 'Codigo invalido.';
                        cupomFeedback.className = 'discount-feedback error';
                    }
                }
                updateResumo();
            } catch (error) {
                descontoAplicadoPreview = null;
                if (cupomFeedback) {
                    cupomFeedback.textContent = 'Nao foi possivel validar agora.';
                    cupomFeedback.className = 'discount-feedback error';
                }
            } finally {
                btnAplicarCupom.disabled = false;
                btnAplicarCupom.innerHTML = '<i class="fa fa-check"></i> Aplicar';
            }
        });
    }

    
    if(telefoneInput) {
        telefoneInput.addEventListener('input', function (e) {
            let v = e.target.value.replace(/\D/g, '');
            v = v.replace(/^(\d{2})(\d)/g, '($1) $2');
            v = v.replace(/(\d{5})(\d)/, '$1-$2');
            e.target.value = v.slice(0, 15);
        });
    }
    const btnToggleObs = document.getElementById('btn-toggle-observacoes');
    const obsContainer = document.getElementById('observacoes-container');
    if (btnToggleObs) {
        btnToggleObs.addEventListener('click', function() {
            const isHidden = obsContainer.style.display === 'none' || obsContainer.style.display === '';
            obsContainer.style.display = isHidden ? 'block' : 'none';
            this.innerHTML = isHidden ? '<i class="fa fa-plus"></i> Adicionar Observação' : '<i class="fa fa-minus"></i> Remover Observação';
        });
    }
    document.addEventListener('updateResumoNeeded', function() { updateResumo(); });
    updateFormState();
});
