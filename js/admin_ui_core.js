document.addEventListener('DOMContentLoaded', function () {
    // --- Variáveis Globais do Script ---
    const modals = document.querySelectorAll('.modal-overlay');
    const openModalButtons = document.querySelectorAll('[data-modal-target]');
    const closeModalButtons = document.querySelectorAll('.modal-close');

    function updateSubscriptionCommissionFields(form) {
        const typeInput = form?.querySelector('[name="comissao_assinatura_tipo"]');
        const valueInput = form?.querySelector('[name="comissao_assinatura_valor"]');
        const valueGroup = form?.querySelector('#comissao-assinatura-valor-group');
        const valueLabel = form?.querySelector('#comissao-assinatura-valor-label');
        const hint = form?.querySelector('#comissao-assinatura-hint');
        const preview = form?.querySelector('#comissao-assinatura-preview');
        const standardInput = form?.querySelector('[name="comissao"]');
        const guideItems = form?.querySelectorAll('[data-commission-guide]');
        if (!typeInput || !valueInput || !valueGroup || !valueLabel || !hint) return;

        const type = typeInput.value;
        valueGroup.hidden = !['percentual', 'fixo'].includes(type);
        valueInput.required = ['percentual', 'fixo'].includes(type);
        guideItems?.forEach(item => {
            const selected = item.dataset.commissionGuide === type;
            item.classList.toggle('is-selected', selected);
            item.setAttribute('aria-current', selected ? 'true' : 'false');
        });

        if (type === 'percentual') {
            valueLabel.textContent = 'Percentual exclusivo (%)';
            valueInput.max = '100';
            valueInput.placeholder = 'Ex: 35';
            hint.textContent = 'O percentual exclusivo será aplicado sobre o preço de tabela dos serviços realizados.';
        } else if (type === 'fixo') {
            valueLabel.textContent = 'Valor por atendimento (R$)';
            valueInput.removeAttribute('max');
            valueInput.placeholder = 'Ex: 20,00';
            hint.textContent = 'O valor fixo será lançado uma vez para cada atendimento de assinatura concluído.';
        } else if (type === 'nenhuma') {
            hint.textContent = 'Atendimentos cobertos por assinatura não gerarão comissão de serviços para este profissional.';
        } else {
            hint.textContent = 'A comissão normal será calculada sobre o preço de tabela dos serviços, mesmo quando o cliente não pagar por eles no atendimento.';
        }

        if (preview) {
            const referenceValue = 50;
            const standardPercentage = Math.min(100, Math.max(0, Number(standardInput?.value || 0)));
            const configuredValue = Math.max(0, Number(valueInput.value || 0));
            const currency = value => Number(value).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

            if (type === 'percentual') {
                const percentage = Math.min(100, configuredValue);
                preview.textContent = `${percentage.toLocaleString('pt-BR')}% de ${currency(referenceValue)} = ${currency(referenceValue * percentage / 100)} de comissão.`;
            } else if (type === 'fixo') {
                preview.textContent = `${currency(configuredValue)} de comissão por atendimento concluído, mesmo que o serviço custe ${currency(referenceValue)}.`;
            } else if (type === 'nenhuma') {
                preview.textContent = `${currency(0)} de comissão pelos serviços cobertos pela assinatura.`;
            } else {
                preview.textContent = `${standardPercentage.toLocaleString('pt-BR')}% de ${currency(referenceValue)} = ${currency(referenceValue * standardPercentage / 100)} de comissão.`;
            }
        }
    }
    
    // Tornar funções úteis globais para os outros módulos
    window.openModal = function(modal) { if (modal) modal.style.display = 'flex'; }
    window.closeModal = function(modal) { if (modal) { modal.style.display = 'none'; } }
    window.updateComboServicesInput = function(container) { 
        const hiddenInput = container.nextElementSibling; 
        const selecionados = Array.from(container.querySelectorAll('.servico-btn-modal.selected')).map(btn => btn.dataset.id); 
        hiddenInput.value = selecionados.join(','); 
    }
    
    // --- Lógica de Modais (Abrir/Fechar e Reset) ---
    function bindModalTrigger(button) {
        button.addEventListener('click', () => {
            const modal = document.querySelector(button.dataset.modalTarget);
            if (!modal) return; 

            const form = modal.querySelector('form');
            if (form) {
                form.reset();
                if (modal.id === 'modal-combo') {
                    form.querySelectorAll('.servico-btn-modal').forEach(btn => btn.classList.remove('selected'));
                    const containerCombo = form.querySelector('#servicos-para-combo-container');
                    if(containerCombo) window.updateComboServicesInput(containerCombo);
                }
                if(modal.id === 'modal-add-produto') {
                    modal.querySelector('#produto_agendamento_id').value = button.dataset.id;
                }
                if(form.querySelector('input[name="id"]')) form.querySelector('input[name="id"]').value = '';
                if(form.querySelector('input[name="current_username"]')) form.querySelector('input[name="current_username"]').value = '';
                
                if(modal.id === 'modal-cliente') {
                    form.querySelector('input[name="foto_perfil_atual"]').value = ''; 
                    const senhaInput = modal.querySelector('input[name="nova_senha"]');
                    if (senhaInput) {
                       senhaInput.placeholder = button.dataset.id ? "Deixe em branco para não alterar" : "Obrigatório para novo cadastro";
                       senhaInput.required = !button.dataset.id; 
                    }
                }
                if(modal.id === 'modal-barbeiro') {
                    form.querySelectorAll('input[name="especialidades_ids[]"]').forEach(cb => cb.checked = false);
                    const senhaInput = modal.querySelector('input[name="password"]');
                    if (senhaInput) {
                       senhaInput.placeholder = button.dataset.id ? "Deixe em branco para não alterar" : "Obrigatório para novo cadastro";
                       senhaInput.required = !button.dataset.id; 
                    }
                    const subscriptionType = form.querySelector('[name="comissao_assinatura_tipo"]');
                    if (subscriptionType) subscriptionType.value = 'padrao';
                    const subscriptionValue = form.querySelector('[name="comissao_assinatura_valor"]');
                    if (subscriptionValue) subscriptionValue.value = '0';
                    updateSubscriptionCommissionFields(form);
                }

                if (modal.id === 'modal-plano') {
                    modal.querySelector('h3').textContent = 'Novo Plano de Assinatura';
                    form.querySelectorAll('input[name="plano_servicos_ids[]"]').forEach(cb => cb.checked = false);
                }
            }

            // --- Lógica de Preenchimento Básico de Formulários (Editar/Movimentar) ---
            if (button.dataset.id || button.dataset.type === 'pagar_comissao') {
                const type = button.dataset.type;
                const id = button.dataset.id;
                
                if (type === 'cliente') {
                    const clientes = typeof clientesData !== 'undefined' ? clientesData : [];
                    const cliente = clientes.find(c => c.id === id);
                    if (cliente && form) {
                        form.querySelector('[name="id"]').value = cliente.id;
                        form.querySelector('[name="nome"]').value = cliente.nome;
                        form.querySelector('[name="email"]').value = cliente.email;
                        form.querySelector('[name="telefone"]').value = cliente.telefone;
                        form.querySelector('[name="cpf"]').value = cliente.cpf || '';
                        form.querySelector('[name="data_nascimento"]').value = cliente.data_nascimento;
                        form.querySelector('[name="foto_perfil_atual"]').value = cliente.foto_perfil || '';
                    }
                } else if (type === 'barbeiro') {
                    const barbeiros = typeof barbeirosData !== 'undefined' ? barbeirosData : {};
                    const barbeiro = barbeiros[id];
                    if (barbeiro && form) {
                        form.querySelector('[name="id"]').value = barbeiro.id;
                        form.querySelector('[name="nome"]').value = barbeiro.nome;
                        form.querySelector('[name="username"]').value = barbeiro.username;
                        form.querySelector('[name="foto_atual"]').value = barbeiro.foto;
                        form.querySelector('[name="status"]').value = barbeiro.status || 'ativo';
                        
                        const comissaoInput = form.querySelector('[name="comissao"]');
                        if (comissaoInput) {
                            comissaoInput.value = barbeiro.comissao || 0;
                        }
                        
                        const comissaoProdInput = form.querySelector('[name="comissao_produtos"]');
                        if (comissaoProdInput) {
                            comissaoProdInput.checked = (barbeiro.comissao_produtos == 1);
                        }

                        const comissaoAssinaturaTipo = form.querySelector('[name="comissao_assinatura_tipo"]');
                        const comissaoAssinaturaValor = form.querySelector('[name="comissao_assinatura_valor"]');
                        if (comissaoAssinaturaTipo) {
                            comissaoAssinaturaTipo.value = barbeiro.comissao_assinatura_tipo || 'padrao';
                        }
                        if (comissaoAssinaturaValor) {
                            comissaoAssinaturaValor.value = barbeiro.comissao_assinatura_valor || 0;
                        }
                        updateSubscriptionCommissionFields(form);
                        
                        const especialidadesSalvas = barbeiro.servicos_ids || '';
                        const especialidadesIdsArray = especialidadesSalvas.split(',').filter(Boolean);

                        if (especialidadesIdsArray.length > 0) {
                            especialidadesIdsArray.forEach(itemId => {
                                const checkbox = form.querySelector(`input[name="especialidades_ids[]"][value="${itemId.trim()}"]`);
                                if (checkbox) {
                                    checkbox.checked = true;
                                }
                            });
                        }
                    }
                } else if (type === 'plano') {
                    const nome = button.dataset.nome;
                    const valor = button.dataset.valor;
                    const servicosIds = button.dataset.servicos ? button.dataset.servicos.split(',') : [];

                    if (form) {
                        form.querySelector('input[name="id"]').value = id;
                        form.querySelector('input[name="nome"]').value = nome;
                        form.querySelector('input[name="valor"]').value = valor;

                        servicosIds.forEach(sid => {
                            const checkbox = form.querySelector(`input[name="plano_servicos_ids[]"][value="${sid.trim()}"]`);
                            if (checkbox) checkbox.checked = true;
                        });

                        modal.querySelector('h3').textContent = 'Editar Plano de Assinatura';
                    }
                } else if (type === 'produto') {
                    if (form) {
                        form.querySelector('input[name="id"]').value = id;
                        form.querySelector('input[name="nome"]').value = button.dataset.nome || '';
                        form.querySelector('input[name="valor"]').value = button.dataset.valor || '';
                        form.querySelector('input[name="quantidade"]').value = button.dataset.quantidade || '';
                        const inputMinimo = form.querySelector('input[name="estoque_minimo"]');
                        if (inputMinimo) inputMinimo.value = button.dataset.estoqueMinimo || '5';
                        const inputCusto = form.querySelector('input[name="custo"]');
                        if (inputCusto) inputCusto.value = button.dataset.custo || '0';
                        const selectCategoria = form.querySelector('select[name="categoria_id"]');
                        if (selectCategoria) selectCategoria.value = button.dataset.categoria || '';
                    }
                } else if (type === 'movimentar_estoque') {
                    if (form) {
                        form.querySelector('#mov_produto_id').value = id;
                        form.querySelector('#mov_produto_nome').value = button.dataset.nome || '';
                    }
                } else if (type === 'ausencias') {
                    const nomeEl = modal.querySelector('#ausencia-barbeiro-nome');
                    if (nomeEl) nomeEl.textContent = button.dataset.nome || '';
                    const hid = modal.querySelector('#ausencia_barbeiro_id');
                    if (hid) hid.value = id;
                    const lista = modal.querySelector('#ausencias-lista');
                    const csrfInput = modal.querySelector('input[name="csrf_token"]');
                    const csrf = csrfInput ? csrfInput.value : '';
                    let itens = [];
                    try { itens = JSON.parse(button.dataset.ausencias || '[]'); } catch (e) { itens = []; }
                    if (lista) {
                        if (!itens.length) {
                            lista.innerHTML = '<p style="color:#94a3b8;font-size:.8rem;margin:0;">Nenhuma folga ou férias registrada.</p>';
                        } else {
                            lista.innerHTML = itens.map(function (a) {
                                var extra = a.motivo ? (' · ' + a.motivo) : '';
                                return '<div class="ausencia-item">'
                                    + '<div><strong>' + a.periodo + '</strong><small>' + a.tipo_label + extra + '</small></div>'
                                    + '<a href="admin.php?action=excluir_ausencia&id=' + encodeURIComponent(a.id) + '&csrf_token=' + encodeURIComponent(csrf) + '" class="ausencia-del" title="Remover" onclick="return confirm(\'Remover este período?\')"><i class="fa fa-trash"></i></a>'
                                    + '</div>';
                            }).join('');
                        }
                    }
                } else if (type === 'comanda') {
                    window.abrirComandaAdmin(id, modal);
                } else if (type === 'despesa') {
                    if (form) {
                        form.querySelector('#mod_desp_id').value = id;
                        form.querySelector('#mod_desp_desc').value = button.dataset.desc || '';
                        form.querySelector('#mod_desp_val').value = button.dataset.val || '';
                        form.querySelector('#mod_desp_venc').value = button.dataset.venc || '';
                        form.querySelector('#mod_desp_cat').value = button.dataset.cat || '';
                        form.querySelector('#mod_desp_status').value = button.dataset.status || '';
                        var recEl = form.querySelector('#mod_desp_recorrente');
                        if (recEl) recEl.checked = button.dataset.recorrente === '1';
                    }
                } else if (type === 'pagar_comissao') {
                    if (form) {
                        form.querySelector('#pay_bid').value = id;
                        document.getElementById('pay_nome').innerText = button.dataset.nome || '';
                        document.getElementById('pay_fat').innerText = parseFloat(button.dataset.fat || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2});
                        document.getElementById('pay_com').innerText = parseFloat(button.dataset.com || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2});
                        form.querySelector('#pay_fat_hidden').value = button.dataset.fat || '';
                        form.querySelector('#pay_com_hidden').value = button.dataset.com || '';
                    }
                } else if (type === 'cupom') {
                    if (form) {
                        form.querySelector('input[name="id"]').value = id;
                        if(form.querySelector('input[name="codigo"]')) form.querySelector('input[name="codigo"]').value = button.dataset.codigo || '';
                        if(form.querySelector('input[name="desconto_percentual"]')) form.querySelector('input[name="desconto_percentual"]').value = button.dataset.desconto || '';
                        if(form.querySelector('input[name="usos_maximos"]')) form.querySelector('input[name="usos_maximos"]').value = button.dataset.usos || '';
                        if(form.querySelector('input[name="data_validade"]')) form.querySelector('input[name="data_validade"]').value = button.dataset.validade || '';
                        if(form.querySelector('input[name="usos_atuais"]')) form.querySelector('input[name="usos_atuais"]').value = button.dataset.atuais || '0';
                        var tipoSel = form.querySelector('select[name="tipo_desconto"]');
                        if(tipoSel) { tipoSel.value = button.dataset.tipo || 'percentual'; tipoSel.dispatchEvent(new Event('change')); }
                        if(form.querySelector('input[name="valor_desconto"]')) form.querySelector('input[name="valor_desconto"]').value = button.dataset.valor || '';
                        if(form.querySelector('select[name="ativo"]')) form.querySelector('select[name="ativo"]').value = (button.dataset.ativo !== undefined ? button.dataset.ativo : '1');
                    }
                } else {
                    const containerElement = button.closest('tr') || button.closest('.barbeiro-card');
                     if (containerElement) {
                        if (type === 'servico' && containerElement.tagName === 'TR') { 
                            const servicos = typeof servicosData !== 'undefined' ? servicosData : {};
                            const servico = servicos[id]; 
                            if (servico && form) {
                                form.querySelector('[name="id"]').value = servico.id; 
                                form.querySelector('[name="nome"]').value = servico.nome; 
                                form.querySelector('[name="valor"]').value = servico.valor; 
                                form.querySelector('[name="slots"]').value = servico.slots || 1;
                                form.querySelector('[name="categoria_id"]').value = servico.categoria_id || '';
                                var descEl = form.querySelector('[name="descricao"]');
                                if (descEl) descEl.value = servico.descricao || '';
                            }
                        }
                        else if (type === 'usuario' && containerElement.tagName === 'TR') { 
                            form.querySelector('[name="current_username"]').value = id; 
                            form.querySelector('[name="new_username"]').value = containerElement.cells[0].textContent; 
                            const roleSelect = form.querySelector('[name="new_role"]');
                            if (roleSelect) roleSelect.value = button.dataset.role || 'proprietario';
                        }
                        else if (type === 'combo' && containerElement.tagName === 'TR') {
                            const combos = typeof combosData !== 'undefined' ? combosData : [];
                            const combo = Array.isArray(combos) ? combos.find(c => c.id === id) : combos[id]; 
                            if (combo) {
                                form.querySelector('[name="id"]').value = combo.id;
                                form.querySelector('[name="nome"]').value = combo.nome;
                                form.querySelector('[name="valor"]').value = combo.valor;
                                form.querySelector('[name="categoria_id"]').value = combo.categoria_id || '';
                                
                                const servicosIds = combo.servicos_ids.split(',');
                                const servicosContainer = form.querySelector('#servicos-para-combo-container');
                                servicosContainer.querySelectorAll('.servico-btn-modal').forEach(btn => {
                                    if (servicosIds.includes(btn.dataset.id)) {
                                        btn.classList.add('selected');
                                    } else {
                                        btn.classList.remove('selected');
                                    }
                                });
                                window.updateComboServicesInput(servicosContainer);
                            }
                        }
                        else if (type === 'categoria' && containerElement.tagName === 'TR') { 
                            const categorias = typeof categoriasData !== 'undefined' ? categoriasData : [];
                            const categoria = categorias.find(c => c.id === id);
                            if (categoria && form) {
                                form.querySelector('[name="id"]').value = categoria.id;
                                form.querySelector('[name="nome"]').value = categoria.nome;
                                form.querySelector('[name="ordem"]').value = categoria.ordem || 10;
                            }
                        }
                    }
                }
            }

            // --- Delegação para os Módulos Específicos com Try-Catch ---
            try {
                if (modal.id === 'modal-barbeiro-detalhes' && typeof window.renderDetalhesBarbeiro === 'function') {
                    window.renderDetalhesBarbeiro(button.dataset.id, modal);
                }
                if (modal.id === 'modal-cliente-detalhes' && typeof window.renderDetalhesCliente === 'function') {
                    window.renderDetalhesCliente(button.dataset.id, modal);
                }
                if (modal.id === 'modal-bloqueio-horarios' && typeof window.initModalBloqueio === 'function') {
                    window.initModalBloqueio(button, modal);
                }
            } catch (error) {
                console.error("Erro ao processar dados do modal detalhado:", error);
            }
            
            // --- Modais Simples Restantes ---
            if (modal.id === 'modal-responder-avaliacao') {
                const avaliacaoId = button.dataset.id_avaliacao;
                const respostaExistente = button.dataset.resposta_existente || '';
                const reviewText = button.dataset.review_text || '';
                const reviewRating = parseInt(button.dataset.review_rating || '0', 10);
                form.querySelector('#modal_resposta_id_avaliacao').value = avaliacaoId;
                form.querySelector('#modal_texto_resposta').value = respostaExistente;
                const hText = form.querySelector('#modal_review_text_hidden'); if (hText) hText.value = reviewText;
                const hRating = form.querySelector('#modal_review_rating_hidden'); if (hRating) hRating.value = reviewRating;
                const stars = modal.querySelector('#modal_review_stars');
                if (stars) stars.textContent = '★'.repeat(reviewRating) + '☆'.repeat(Math.max(0, 5 - reviewRating));
                const disp = modal.querySelector('#modal_review_text_display');
                if (disp) disp.textContent = reviewText ? '"' + reviewText + '"' : 'O cliente deixou apenas a nota, sem comentário.';
            }

            if (modal.id === 'modal-ver-agendamento') {
                const agendamentoId = button.dataset.agendamentoId;
                const agendamentos = typeof agendamentosData !== 'undefined' ? agendamentosData : [];
                const agendamento = agendamentos.find(ag => ag.id === agendamentoId);
                const contentDiv = modal.querySelector('#modal-agendamento-detalhes-content');
                
                if (agendamento) {
                    const barbeiros = typeof barbeirosData !== 'undefined' ? barbeirosData : {};
                    const servicos = typeof servicosData !== 'undefined' ? servicosData : {};
                    const combos = typeof combosData !== 'undefined' ? combosData : {};
                    
                    const barbeiro = barbeiros[agendamento.barbeiro_id] || { nome: 'N/A' };
                    let servicosHtml = '';
                    let valorTotal = 0;
                    const servicosIds = agendamento.servicos_ids.split(',');
                    let servicosJaEmCombo = [];

                    servicosIds.forEach(sid => {
                        const combo = Array.isArray(combos) ? combos.find(c => c.id === sid.trim()) : combos[sid.trim()];
                        if(combo) {
                            servicosHtml += `<li>${combo.nome} (Combo) <span>R$ ${parseFloat(combo.valor).toFixed(2)}</span></li>`; 
                            valorTotal += parseFloat(combo.valor); 
                            if(combo.servicos_ids) {
                                servicosJaEmCombo = servicosJaEmCombo.concat(combo.servicos_ids.split(',').map(s => s.trim()));
                            }
                        }
                    });
                    servicosIds.forEach(sid => {
                        const servico = servicos[sid.trim()];
                        if(servico && !servicosJaEmCombo.includes(sid.trim())) { 
                            servicosHtml += `<li>${servico.nome} <span>R$ ${parseFloat(servico.valor).toFixed(2)}</span></li>`; 
                            valorTotal += parseFloat(servico.valor); 
                        }
                    });

                    const desconto = parseFloat(agendamento.desconto_aplicado || 0);
                    const valorFinal = valorTotal - desconto;
                    let descontoHtml = '';
                    if (desconto > 0) {
                        descontoHtml = `<div class="detalhes-total desconto"><strong>Desconto (${agendamento.tipo_desconto || ''}):</strong><span>- R$ ${desconto.toFixed(2)}</span></div>`;
                    }

                    contentDiv.innerHTML = `
                        <div class="detalhes-info">
                            <p><strong>Cliente:</strong> ${agendamento.nome}</p>
                            <p><strong>Barbeiro:</strong> ${barbeiro.nome}</p>
                            <p><strong>Data:</strong> ${new Date(agendamento.data.replace(/-/g, '/')).toLocaleDateString('pt-BR')} às ${agendamento.hora}</p>
                        </div>
                        <ul class="detalhes-servicos">${servicosHtml}</ul>
                        ${descontoHtml}
                        <div class="detalhes-total">
                            <strong>Valor Final:</strong><span>R$ ${valorFinal.toFixed(2)}</span>
                        </div>
                    `;
                } else {
                    contentDiv.innerHTML = '<p class="erro">Não foi possível carregar os detalhes do agendamento.</p>';
                }
            }
            
            // Independente de erros acima, o modal deve abrir
            window.openModal(modal);
        });
    }
    openModalButtons.forEach(bindModalTrigger);
    window.__reinitModalTriggers = function(root) {
        root.querySelectorAll('[data-modal-target]').forEach(bindModalTrigger);
    };

    document.querySelectorAll('[name="comissao_assinatura_tipo"]').forEach(select => {
        select.addEventListener('change', function () {
            updateSubscriptionCommissionFields(this.form);
        });
        updateSubscriptionCommissionFields(select.form);
    });

    document.querySelectorAll('[name="comissao"], [name="comissao_assinatura_valor"]').forEach(input => {
        input.addEventListener('input', function () {
            updateSubscriptionCommissionFields(this.form);
        });
    });

    closeModalButtons.forEach(button => button.addEventListener('click', () => window.closeModal(button.closest('.modal-overlay'))));
    // Fechamento por clique no fundo desativado de propósito: evita perder o que
    // está sendo preenchido no modal por um clique acidental. Fecha só pelo botão X.

    // --- UI Helpers: Inputs, CPF, etc ---
    const fotoInput = document.querySelector('input[type="file"][name="foto"]');
    if (fotoInput) { fotoInput.addEventListener('change', function() { if (this.files.length === 0) return; const file = this.files[0], allowedTypes = ['image/jpeg', 'image/png'], maxSize = 2 * 1024 * 1024; if (!allowedTypes.includes(file.type)) { alert('Erro: Apenas arquivos JPG e PNG são permitidos.'); this.value = ''; } if (file.size > maxSize) { alert('Erro: O arquivo é muito grande (máximo 2 MB).'); this.value = ''; } }); }
    
    const cpfInputModal = document.getElementById('cpf-modal');
    if(cpfInputModal) {
        cpfInputModal.addEventListener('input', function() {
            let v = this.value.replace(/\D/g, '').substring(0, 11);
            v = v.replace(/(\d{3})(\d)/, '$1.$2');
            v = v.replace(/(\d{3})(\d)/, '$1.$2');
            v = v.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
            this.value = v;
        });
    }

    const modalCombo = document.getElementById('modal-combo');
    if (modalCombo) { 
        const servicosContainer = modalCombo.querySelector('#servicos-para-combo-container'); 
        servicosContainer.addEventListener('click', function(e) { 
            if (e.target.classList.contains('servico-btn-modal')) { 
                e.target.classList.toggle('selected'); 
                window.updateComboServicesInput(servicosContainer); 
            } 
        }); 
    }

    // --- Lógica de Tabs e Sub-tabs ---
    const tabs = document.querySelectorAll('.tab-btn');
    const contents = document.querySelectorAll('.tabcontent');
    const urlParams = new URLSearchParams(window.location.search);
    const activeTabName = urlParams.get('tab') || 'dashboard';

    const initialTab = document.querySelector(`.tab-btn[data-tab="${activeTabName}"]`);
    if(initialTab){
        tabs.forEach(t => t.classList.remove('active'));
        contents.forEach(c => c.classList.remove('active'));
        initialTab.classList.add('active');
        const activeContent = document.getElementById(activeTabName);
        if (activeContent) activeContent.classList.add('active');
    }

    // Executa <script> vindos de HTML injetado via innerHTML (não rodam sozinhos).
    function executeInjectedScripts(container) {
        // Vários scripts próprios de cada aba fazem
        // document.addEventListener('DOMContentLoaded', fn) — o que funciona
        // no carregamento inicial da página, mas nunca dispara quando o
        // script é injetado depois via AJAX, porque o DOMContentLoaded real
        // já aconteceu. Enquanto executamos os scripts recém-injetados,
        // interceptamos esse registro e chamamos a função na hora, já que o
        // DOM da aba (o único que esses scripts consultam) já está pronto.
        const originalAddEventListener = document.addEventListener.bind(document);
        document.addEventListener = function(type, listener, options) {
            if (type === 'DOMContentLoaded') {
                try { listener(); } catch (e) { console.error(e); }
                return;
            }
            return originalAddEventListener(type, listener, options);
        };

        try {
            container.querySelectorAll('script').forEach(oldScript => {
                const newScript = document.createElement('script');
                Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
                newScript.textContent = oldScript.textContent;
                oldScript.parentNode.replaceChild(newScript, oldScript);
            });
        } finally {
            document.addEventListener = originalAddEventListener;
        }
    }

    // Reaplica os comportamentos globais (modais, confirmações, spinner de forms)
    // a uma aba que acabou de ser injetada via AJAX.
    function reinitTabBehaviors(tabName, root) {
        if (window.__reinitModalTriggers) window.__reinitModalTriggers(root);
        if (window.__reinitAdminTabBehaviors) window.__reinitAdminTabBehaviors(root);
        if (tabName === 'barbeiros' && window.__reinitBarbeiroGrid) window.__reinitBarbeiroGrid();
        if (tabName === 'agendamentos' && window.__reinitAgenda) window.__reinitAgenda();
    }

    function finishTabActivation(tabName) {
        const event = new CustomEvent('tabChanged', { detail: tabName });
        document.dispatchEvent(event);

        const url = new URL(window.location);
        url.searchParams.set('tab', tabName);
        window.history.pushState({}, '', url);
    }

    function loadTabViaAjax(tabName, activeContent) {
        activeContent.dataset.fetching = '1';
        activeContent.innerHTML = '<div class="tab-lazy-loading"><i class="fa fa-spinner fa-spin"></i> Carregando...</div>';

        fetch('admin.php?ajax_tab=' + encodeURIComponent(tabName), { credentials: 'same-origin' })
            .then(response => {
                if (!response.ok) throw new Error('Falha ao carregar a aba (' + response.status + ')');
                return response.text();
            })
            .then(html => {
                activeContent.innerHTML = html;
                delete activeContent.dataset.fetching;
                activeContent.dataset.loaded = '1';
                delete activeContent.dataset.lazy;
                executeInjectedScripts(activeContent);
                reinitTabBehaviors(tabName, activeContent);
                finishTabActivation(tabName);
            })
            .catch(() => {
                // Rede/servidor falhou: recarrega a página normalmente nesta aba.
                const url = new URL(window.location);
                url.searchParams.set('tab', tabName);
                window.location.href = url.toString();
            });
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            const tabName = tab.dataset.tab;
            tabs.forEach(t => t.classList.remove('active'));
            contents.forEach(c => c.classList.remove('active'));
            tab.classList.add('active');
            const activeContent = document.getElementById(tabName);
            if (!activeContent) return;
            activeContent.classList.add('active');

            if (activeContent.dataset.fetching === '1') return;

            if (activeContent.dataset.loaded === '1') {
                finishTabActivation(tabName);
                return;
            }

            loadTabViaAjax(tabName, activeContent);
        });
    });

    // --- Accordions ---
    var acc = document.getElementsByClassName("accordion-button");
    for (var i = 0; i < acc.length; i++) {
        acc[i].addEventListener("click", function() {
            this.classList.toggle("active");
            var panel = this.nextElementSibling;
            if (panel.style.maxHeight) {
                panel.style.maxHeight = null;
            } else {
                panel.style.maxHeight = (panel.scrollHeight + 20) + "px";
            } 
        });
    }
    const initialAccordion = urlParams.get('accordion');
    if (initialAccordion) {
        const accordionBtn = document.querySelector(initialAccordion);
        if (accordionBtn) accordionBtn.click();
    }

    // --- Adesão de Plano no Agendamento Manual ---
    const chkAderir = document.getElementById('manual_aderir_plano');
    const containerPlano = document.getElementById('container_plano_manual');
    const selectPlano = document.getElementById('manual_plano_id');

    if (chkAderir && containerPlano) {
        chkAderir.addEventListener('change', function() {
            if (this.checked) {
                containerPlano.style.display = 'block';
                selectPlano.required = true;
            } else {
                containerPlano.style.display = 'none';
                selectPlano.required = false;
                selectPlano.value = '';
            }
        });
    }
    const btnOpenManual = document.querySelector('[data-modal-target="#modal-agendamento-manual"]');
    if(btnOpenManual && chkAderir){
        btnOpenManual.addEventListener('click', function(){
            chkAderir.checked = false;
            chkAderir.dispatchEvent(new Event('change'));
        });
    }

    // As notificações de novos agendamentos agora são tratadas pelo motor
    // compartilhado js/notif_agendamentos.js (cartões ricos + som, responsivo),
    // configurado via window.NA_CONFIG. Polling antigo removido daqui.

});

// ===================== COMANDA DIGITAL (ADMIN) =====================
window.abrirComandaAdmin = function (agendamentoId, modal) {
    var brl = function (v) { return 'R$ ' + (Number(v) || 0).toFixed(2).replace('.', ','); };
    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    var loading = modal.querySelector('#comanda-loading');
    var form = modal.querySelector('#form-comanda');
    loading.style.display = 'block';
    form.style.display = 'none';
    modal.querySelector('#comanda-agendamento-id').value = agendamentoId;
    modal.querySelector('#comanda-gorjeta').value = '0';

    fetch('ajax_comanda.php?id=' + encodeURIComponent(agendamentoId))
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.success) {
                loading.innerHTML = '<i class="fa fa-triangle-exclamation"></i> ' + ((data && data.message) || 'Erro ao carregar a comanda.');
                return;
            }
            modal.querySelector('#comanda-cliente').textContent = data.cliente || '';
            modal.querySelector('#comanda-hora').textContent = data.hora ? ('· ' + data.hora) : '';

            var html = '';
            (data.servicos || []).forEach(function (s) {
                html += '<div class="comanda-item"><span><i class="fa fa-scissors"></i> ' + esc(s.nome) + '</span><span>' + brl(s.valor) + '</span></div>';
            });
            (data.produtos || []).forEach(function (p) {
                html += '<div class="comanda-item"><span><i class="fa fa-box"></i> ' + esc(p.nome) + '</span><span>' + brl(p.valor) + '</span></div>';
            });
            if (Number(data.desconto) > 0) {
                html += '<div class="comanda-item comanda-desc"><span><i class="fa fa-tag"></i> Desconto</span><span>- ' + brl(data.desconto) + '</span></div>';
            }
            if (!html) html = '<div class="comanda-item"><span>Nenhum item lançado.</span><span></span></div>';
            modal.querySelector('#comanda-itens').innerHTML = html;

            var chips = '';
            (data.servicos_disponiveis || []).forEach(function (s) {
                chips += '<label class="comanda-chip"><input type="checkbox" name="servicos_extras[]" value="' + esc(s.id) + '" data-valor="' + s.valor + '"> ' + esc(s.nome) + ' <b>+' + brl(s.valor) + '</b></label>';
            });
            var wrap = modal.querySelector('#comanda-extras-wrap');
            if (chips) { modal.querySelector('#comanda-extras-chips').innerHTML = chips; wrap.style.display = 'block'; }
            else { wrap.style.display = 'none'; }

            modal._comandaBase = (Number(data.subtotal_servicos) || 0) + (Number(data.subtotal_produtos) || 0) - (Number(data.desconto) || 0);

            function recalc() {
                var total = modal._comandaBase;
                modal.querySelectorAll('#comanda-extras-chips input:checked').forEach(function (c) { total += Number(c.dataset.valor) || 0; });
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

// ===================== IA: gerar descrição de serviço =====================
document.addEventListener('click', function (e) {
    var b = e.target.closest('#btn-ia-descricao-servico');
    if (!b) return;
    e.preventDefault();
    var form = b.closest('form');
    if (!form) return;
    var nome = (form.querySelector('[name="nome"]') || {}).value ? form.querySelector('[name="nome"]').value.trim() : '';
    var valor = (form.querySelector('[name="valor"]') || {}).value || '';
    var ta = form.querySelector('[name="descricao"]');
    if (!nome) {
        if (window.Swal) Swal.fire({ icon: 'info', title: 'Informe o nome', text: 'Preencha o nome do serviço antes de gerar a descrição.' });
        else alert('Preencha o nome do serviço primeiro.');
        return;
    }
    var orig = b.innerHTML;
    b.disabled = true; b.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Gerando...';
    fetch('ajax_gemini.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
        body: JSON.stringify({ action: 'gerar_descricao_item', nome_item: nome, tipo_item: 'serviço de barbearia', contexto_item: valor ? ('Preço: R$ ' + valor) : '' })
    })
    .then(function (r) { return r.json(); })
    .then(function (d) { if (!d || !d.success) throw new Error((d && d.error) || 'Falha na IA'); if (ta) ta.value = (d.resposta || '').trim(); })
    .catch(function (err) { if (window.Swal) Swal.fire({ icon: 'error', title: 'Erro', text: err.message }); else alert(err.message); })
    .finally(function () { b.disabled = false; b.innerHTML = orig; });
});
