// Funções expostas globalmente para serem chamadas pelo admin_ui_core.js e painel_barbeiro.js

// Seguranca (S-02): texto vindo de clientes (nome, comentario de avaliacao,
// horario, e-mail, telefone...) e respostas da IA passam por escHtmlDetalhes()
// antes de entrar em innerHTML. Sem isso, um comentario com HTML executava
// codigo na sessao do admin/barbeiro.
function escHtmlDetalhes(valor) {
    return String(valor ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

window.renderDetalhesBarbeiro = function(barbeiroId, modal) {
    // --- Proteções de Dados Globais ---
    const barbeiros = typeof barbeirosData !== 'undefined' ? barbeirosData : {};
    const agendamentos = typeof agendamentosData !== 'undefined' ? agendamentosData : [];
    const servicos = typeof servicosData !== 'undefined' ? servicosData : {};
    const combos = typeof combosData !== 'undefined' ? combosData : [];
    const avaliacoes = typeof avaliacoesData !== 'undefined' ? avaliacoesData : [];
    
    // Suporte robusto para variável horariosTrabalho
    const horariosTrabalho = (typeof adminJSData !== 'undefined' && adminJSData.horariosTrabalhoData) ? adminJSData.horariosTrabalhoData : (typeof horariosTrabalhoData !== 'undefined' ? horariosTrabalhoData : []);
    
    const clientesInfo = typeof clientesInfoData !== 'undefined' ? clientesInfoData : {};

    const barbeiro = barbeiros[barbeiroId];
    if (!barbeiro) return;

    // --- 1. PREPARAÇÃO DE DADOS ---
    const barbeiroAgendamentos = agendamentos.filter(a => 
        a.barbeiro_id === barbeiroId
    ).sort((a, b) => new Date(b.data + ' ' + b.hora) - new Date(a.data + ' ' + a.hora));

    let receitaTotal = 0;
    let totalConcluidos = 0;
    let totalAgendadosFuturos = 0;
    const hoje = new Date().toISOString().split('T')[0];

    barbeiroAgendamentos.forEach(ag => {
        if (ag.status === 'concluido') {
            totalConcluidos++;
            let valorAg = 0;
            if (ag.servicos_ids) {
                ag.servicos_ids.split(',').forEach(sid => {
                    const cleanSid = sid.trim();
                    if (servicos[cleanSid]) {
                        valorAg += parseFloat(servicos[cleanSid].valor);
                    } else if (Array.isArray(combos) && combos.find(c => c.id === cleanSid)) {
                        const combo = combos.find(c => c.id === cleanSid);
                        valorAg += parseFloat(combo.valor);
                    } else if (combos[cleanSid]) {
                        valorAg += parseFloat(combos[cleanSid].valor);
                    }
                });
            }
            if (ag.produtos_vendidos) {
                try {
                    const prods = JSON.parse(ag.produtos_vendidos);
                    if(Array.isArray(prods)){
                        prods.forEach(p => valorAg += parseFloat(p.valor));
                    }
                } catch(e){}
            }
            valorAg -= parseFloat(ag.desconto_aplicado || 0);
            receitaTotal += Math.max(0, valorAg);
        } else if (ag.status === 'aprovado' && ag.data >= hoje) {
            totalAgendadosFuturos++;
        }
    });

    const avaliacoesDoBarbeiro = avaliacoes.filter(a => a.barbeiro_id === barbeiroId);
    const mediaRating = avaliacoesDoBarbeiro.length > 0 
        ? (avaliacoesDoBarbeiro.reduce((acc, curr) => acc + parseInt(curr.rating), 0) / avaliacoesDoBarbeiro.length).toFixed(1) 
        : 'N/A';

    // Suporte a arrays legados (string) e objetos (SQLite)
    const horariosDoBarbeiro = [];
    horariosTrabalho.forEach(h => {
        if (typeof h === 'string' && h.startsWith(barbeiroId + '|')) {
            horariosDoBarbeiro.push(h.split('|'));
        } else if (typeof h === 'object' && h !== null && h.barbeiro_id === barbeiroId) {
            horariosDoBarbeiro.push([h.barbeiro_id, String(h.dia), h.inicio, h.fim]);
        }
    });

    const diasSemanaTexto = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];

    // --- PREPARAÇÃO DOS DADOS PARA A IA (AVALIAÇÃO 360) ---
    let comentariosRecentes = avaliacoesDoBarbeiro.slice(-5).map(av => {
        return av.comment ? `"${av.comment}" (Nota: ${av.rating})` : `Sem texto (Nota: ${av.rating})`;
    }).join(' | ');
    if (!comentariosRecentes) comentariosRecentes = "Sem avaliações recentes.";

    let textoDadosBarbeiroParaIA = `Nome do Profissional: ${barbeiro.nome}\n`;
    textoDadosBarbeiroParaIA += `Total de Atendimentos Concluídos: ${totalConcluidos}\n`;
    textoDadosBarbeiroParaIA += `Receita Gerada (Total Histórico): R$ ${receitaTotal.toFixed(2)}\n`;
    textoDadosBarbeiroParaIA += `Média de Avaliações: ${mediaRating} estrelas\n`;
    textoDadosBarbeiroParaIA += `Agendamentos Futuros na Agenda: ${totalAgendadosFuturos}\n`;
    textoDadosBarbeiroParaIA += `Comentários Recentes dos Clientes: ${comentariosRecentes}\n`;

    // --- 2. CONSTRUÇÃO DO HTML ---
    const statusHtml = (barbeiro.status === 'inativo') 
        ? '<span style="background:#dc3545; color:white; padding:2px 8px; border-radius:10px; font-size:0.8em; margin-left:10px;">Inativo</span>'
        : '<span style="background:#28a745; color:white; padding:2px 8px; border-radius:10px; font-size:0.8em; margin-left:10px;">Ativo</span>';

    const iaAvaliacaoHtml = `
        <div style="background: linear-gradient(135deg, #faf5ff 0%, #f3e8ff 100%); border: 1px solid #e9d5ff; border-radius: 12px; padding: 20px; margin-bottom: 25px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 12px;">
                <h4 style="margin: 0; color: #6b21a8; display: flex; align-items: center; gap: 8px; font-size: 1.15rem;"><i class="fa fa-clipboard-check"></i> Avaliação de Desempenho 360º (IA)</h4>
                <button id="btn-gerar-avaliacao-rh-${barbeiroId}" class="btn-action text-btn" style="background: #a855f7; color: white; border: none; padding: 8px 15px; border-radius: 8px; cursor: pointer; font-size: 0.9rem; font-weight: bold; box-shadow: 0 4px 10px rgba(168,85,247,0.2);"><i class="fa fa-magic"></i> Gerar Feedback</button>
            </div>
            <div id="ia-avaliacao-text-${barbeiroId}" style="font-size: 0.95rem; color: #4c1d95; line-height: 1.6;">
                <i class="fa fa-info-circle"></i> Deixe a Inteligência Artificial cruzar a receita gerada, os atendimentos e as notas das avaliações para criar um resumo de feedback pronto para a sua próxima reunião de alinhamento com a equipe.
            </div>
        </div>
    `;

    let fotoBarbeiroStr = barbeiro.foto ? barbeiro.foto.trim() : '';
    const fotoBarbeiroSrc = (fotoBarbeiroStr !== '') ? fotoBarbeiroStr : 'uploads/default-profile.jpg';

    let html = `
        <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 15px; padding-bottom: 20px; border-bottom: 1px solid #eee;">
            <img src="${fotoBarbeiroSrc}" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 4px solid #fff; box-shadow: 0 2px 10px rgba(0,0,0,0.1);" onerror="this.src='uploads/default-profile.jpg'">
            <div>
                <h2 style="margin: 0; color: #333;">${escHtmlDetalhes(barbeiro.nome)} ${statusHtml}</h2>
                <p style="margin: 5px 0 0; color: #666;"><i class="fa fa-user-circle"></i> Usuário: <strong>${escHtmlDetalhes(barbeiro.username)}</strong></p>
            </div>
        </div>

        ${iaAvaliacaoHtml}

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 15px; margin-bottom: 25px;">
            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; text-align: center; border: 1px solid #e0e0e0;">
                <div style="font-size: 0.8em; color: #6c757d; text-transform: uppercase;">Receita Gerada</div>
                <div style="font-size: 1.3em; font-weight: bold; color: #28a745;">R$ ${receitaTotal.toLocaleString('pt-BR', {minimumFractionDigits: 2})}</div>
            </div>
            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; text-align: center; border: 1px solid #e0e0e0;">
                <div style="font-size: 0.8em; color: #6c757d; text-transform: uppercase;">Atendimentos</div>
                <div style="font-size: 1.3em; font-weight: bold; color: #007bff;">${totalConcluidos}</div>
            </div>
            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; text-align: center; border: 1px solid #e0e0e0;">
                <div style="font-size: 0.8em; color: #6c757d; text-transform: uppercase;">Média Avaliação</div>
                <div style="font-size: 1.3em; font-weight: bold; color: #ffc107;">${mediaRating} <i class="fa fa-star" style="font-size:0.8em"></i></div>
            </div>
            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; text-align: center; border: 1px solid #e0e0e0;">
                <div style="font-size: 0.8em; color: #6c757d; text-transform: uppercase;">Agendados</div>
                <div style="font-size: 1.3em; font-weight: bold; color: #17a2b8;">${totalAgendadosFuturos}</div>
            </div>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
            <div>
                <h4 style="border-bottom: 2px solid #eee; padding-bottom: 10px; margin-bottom: 10px; color: #333;"><i class="fa fa-clock"></i> Horários de Trabalho</h4>`;
    
    if (horariosDoBarbeiro.length > 0) {
        html += '<ul style="list-style: none; padding: 0; margin-bottom: 20px; background: #fff; border: 1px solid #eee; border-radius: 8px; overflow: hidden;">';
        const ordemDias = { '1': 1, '2': 2, '3': 3, '4': 4, '5': 5, '6': 6, '0': 7 };
        horariosDoBarbeiro.sort((a, b) => ordemDias[a[1]] - ordemDias[b[1]]);
        
        horariosDoBarbeiro.forEach(h => {
            const diaNome = diasSemanaTexto[h[1]] || 'Dia';
            html += `<li style="padding: 10px 15px; border-bottom: 1px solid #f5f5f5; display: flex; justify-content: space-between;">
                <strong>${diaNome}</strong> <span>${h[2]} - ${h[3]}</span>
            </li>`;
        });
        html += '</ul>';
    } else {
        html += '<p style="color:#777; font-style:italic; margin-bottom: 20px;">Sem horários definidos.</p>';
    }

    html += `<h4 style="border-bottom: 2px solid #eee; padding-bottom: 10px; margin-bottom: 10px; color: #333;"><i class="fa fa-comment-dots"></i> Recentes</h4>`;
    if (avaliacoesDoBarbeiro.length > 0) {
        html += '<div style="max-height: 250px; overflow-y: auto;">';
        [...avaliacoesDoBarbeiro].reverse().slice(0, 5).forEach(av => {
            const clienteNome = clientesInfo[av.cliente_id] || 'Anônimo';
            const rating = '★'.repeat(av.rating) + '☆'.repeat(5 - av.rating);
            html += `
                <div style="background: #f9f9f9; padding: 10px; border-radius: 6px; margin-bottom: 10px; border: 1px solid #eee;">
                    <div style="display: flex; justify-content: space-between; font-size: 0.9em;">
                        <strong>${escHtmlDetalhes(clienteNome)}</strong>
                        <span style="color: #ffc107;">${rating}</span>
                    </div>
                    ${av.comment ? `<p style="margin: 5px 0 0; font-style: italic; color: #555; font-size: 0.9em;">"${escHtmlDetalhes(av.comment)}"</p>` : ''}
                </div>
            `;
        });
        html += '</div>';
    } else {
        html += '<p style="color:#777; font-style:italic;">Nenhuma avaliação.</p>';
    }
    
    html += `</div><div><h4 style="border-bottom: 2px solid #eee; padding-bottom: 10px; margin-bottom: 10px; color: #333;"><i class="fa fa-history"></i> Histórico (Últimos 10)</h4>`;
    
    if (barbeiroAgendamentos.length > 0) {
        html += '<div style="max-height: 400px; overflow-y: auto;">';
        barbeiroAgendamentos.slice(0, 10).forEach(ag => {
            const dataF = new Date(ag.data.replace(/-/g, '/')).toLocaleDateString('pt-BR');
            const statusColor = ag.status === 'concluido' ? '#28a745' : (ag.status === 'cancelado' ? '#dc3545' : '#ffc107');
            
            let valorDisplay = 0;
            if(ag.servicos_ids) {
                ag.servicos_ids.split(',').forEach(sid => {
                    const cleanSid = sid.trim();
                    if(servicos[cleanSid]) valorDisplay += parseFloat(servicos[cleanSid].valor);
                    else if(Array.isArray(combos) && combos.find(c => c.id === cleanSid)) valorDisplay += parseFloat(combos.find(c => c.id === cleanSid).valor);
                    else if(combos[cleanSid]) valorDisplay += parseFloat(combos[cleanSid].valor);
                });
            }
            if(ag.produtos_vendidos) {
                try { JSON.parse(ag.produtos_vendidos).forEach(p => valorDisplay += parseFloat(p.valor)); } catch(e){}
            }
            valorDisplay -= parseFloat(ag.desconto_aplicado || 0);

            html += `
                <div style="background: #fff; padding: 10px; border-radius: 6px; margin-bottom: 10px; border: 1px solid #e0e0e0; border-left: 4px solid ${statusColor};">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                        <strong style="color: #333;">${escHtmlDetalhes(ag.nome)}</strong>
                        <span style="font-weight: bold; color: #333;">R$ ${Math.max(0, valorDisplay).toFixed(2)}</span>
                    </div>
                    <div style="font-size: 0.85em; color: #666; display: flex; justify-content: space-between;">
                        <span>${dataF} às ${escHtmlDetalhes(ag.hora)}</span>
                        <span style="text-transform: capitalize; color: ${statusColor}; font-weight: bold;">${escHtmlDetalhes(ag.status)}</span>
                    </div>
                </div>
            `;
        });
        html += '</div>';
    } else {
        html += '<p style="color:#777; font-style:italic;">Nenhum agendamento registrado.</p>';
    }
    
    html += `</div></div>`;

    const nomeElementB = document.getElementById('barbeiro-detalhes-nome');
    if(nomeElementB) nomeElementB.style.display = 'none';
    const contentB = document.getElementById('barbeiro-detalhes-content');
    if (contentB) {
        contentB.innerHTML = html;

        setTimeout(() => {
            const btnAvaliacao = document.getElementById(`btn-gerar-avaliacao-rh-${barbeiroId}`);
            if (btnAvaliacao) {
                btnAvaliacao.addEventListener('click', async function() {
                    const contentAvaliacao = document.getElementById(`ia-avaliacao-text-${barbeiroId}`);
                    
                    btnAvaliacao.disabled = true;
                    btnAvaliacao.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processando...';
                    contentAvaliacao.innerHTML = '<div style="text-align: center; padding: 10px;"><i class="fa fa-circle-notch fa-spin fa-2x" style="color: #a855f7; margin-bottom: 10px;"></i><br><span style="color: #6b21a8; font-weight: bold;">A Inteligência Artificial está elaborando o feedback do profissional...</span></div>';
                    
                    try {
                        const response = await fetch('ajax_gemini.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ 
                                action: 'avaliacao_360_barbeiro',
                                dados_barbeiro: textoDadosBarbeiroParaIA
                            })
                        });
                        const result = await response.json();
                        
                        if (result.success) {
                            contentAvaliacao.innerHTML = '<strong style="display:block; margin-bottom:8px; color: #581c87; font-size: 1.05rem;"><i class="fa fa-check-circle"></i> Parecer do RH (IA):</strong>' + escHtmlDetalhes(result.resposta).replace(/\n/g, '<br>');
                        } else {
                            contentAvaliacao.innerHTML = '<span style="color:#ef4444; font-weight: bold;"><i class="fa fa-exclamation-triangle"></i> Erro: ' + escHtmlDetalhes(result.error) + '</span>';
                        }
                    } catch(e) {
                        contentAvaliacao.innerHTML = '<span style="color:#ef4444; font-weight: bold;"><i class="fa fa-wifi"></i> Erro de conexão com o servidor ou timeout. Tente novamente.</span>';
                        console.error(e);
                    } finally {
                        btnAvaliacao.disabled = false;
                        btnAvaliacao.innerHTML = '<i class="fa fa-sync-alt"></i> Atualizar Avaliação';
                    }
                });
            }
        }, 100);
    }
};

window.renderDetalhesCliente = function(clienteId, modal) {
    const clientes = typeof clientesData !== 'undefined' ? clientesData : [];
    const cliente = clientes.find(c => c.id === clienteId);
    if (!cliente) return;

    // Detecta em qual painel estamos rodando para adaptar o visual e a ação dos forms
    const isBarbeiroPanel = document.body.classList.contains('barbeiro-page');

    // Pesca o token CSRF da página para injetar dinamicamente no formulário das anotações
    const csrfInput = document.querySelector('input[name="csrf_token"]');
    const csrfToken = csrfInput ? csrfInput.value : '';

    const assinaturasObj = (typeof adminJSData !== 'undefined' && adminJSData.assinaturasData) ? adminJSData.assinaturasData : {};
    const planosObj = (typeof adminJSData !== 'undefined' && adminJSData.planosData) ? adminJSData.planosData : {};
    const agendamentos = typeof agendamentosData !== 'undefined' ? agendamentosData : [];
    const anotacoes = typeof anotacoesData !== 'undefined' ? anotacoesData : {};
    const servicos = typeof servicosData !== 'undefined' ? servicosData : {};
    const barbeiros = typeof barbeirosData !== 'undefined' ? barbeirosData : {};

    const clienteAgendamentos = agendamentos.filter(a => 
        a.email === cliente.email || 
        (a.telefone && cliente.telefone && a.telefone.replace(/\D/g, '') === cliente.telefone.replace(/\D/g, ''))
    ).sort((a, b) => new Date(b.data + ' ' + b.hora) - new Date(a.data + ' ' + a.hora)); 

    const clienteAnotacao = anotacoes[clienteId] || '';
    
    // --- 1. ESTATÍSTICAS E ASSINATURA ---
    const assinatura = assinaturasObj[clienteId];
    let isVip = false;
    let planoNome = '';
    let assinaturaHtml = '';

    if (assinatura && assinatura.status === 'ativo') {
        const dataFim = new Date(assinatura.data_fim);
        const hoje = new Date();
        hoje.setHours(0,0,0,0);
        
        if (dataFim >= hoje) {
            isVip = true;
            const plano = planosObj[assinatura.plano_id];
            planoNome = plano ? plano.nome : 'Plano de assinatura';
            const diasRestantes = Math.ceil((dataFim - hoje) / (1000 * 60 * 60 * 24));
            
            assinaturaHtml = `
                <div style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); padding: 15px; border-radius: 12px; margin-bottom: 20px; color: white; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.2);">
                    <div>
                        <h4 style="margin: 0 0 5px 0; color: white; display: flex; align-items: center; gap: 8px; font-size: 1rem;"><i class="fa fa-crown" style="color: #fbbf24;"></i> Assinante Ativo</h4>
                        <p style="margin: 0; font-weight: 600; font-size: 0.85rem; opacity: 0.9;">Plano: ${planoNome}</p>
                    </div>
                    <div style="text-align: right; background: rgba(255,255,255,0.2); padding: 8px 15px; border-radius: 10px;">
                        <span style="font-size: 1.5rem; font-weight: 800; line-height: 1;">${diasRestantes}</span>
                        <span style="display: block; font-size: 0.7rem; text-transform: uppercase; font-weight: 600;">dias restantes</span>
                    </div>
                </div>
            `;
        }
    }

    let totalGasto = 0;
    let totalEconomizado = 0;
    let totalVisitas = 0;
    const historicoDescontos = [];

    clienteAgendamentos.forEach(ag => {
        if (ag.status === 'concluido') {
            totalVisitas++;
            let valorServicos = 0;
            if (ag.servicos_ids) {
                ag.servicos_ids.split(',').forEach(sid => {
                    const s = servicos[sid.trim()] || ((typeof adminJSData !== 'undefined' && adminJSData.combosData) ? adminJSData.combosData[sid.trim()] : null) || ((typeof combosData !== 'undefined') ? combosData[sid.trim()] : null);
                    if (s) valorServicos += parseFloat(s.valor);
                });
            }
            if (ag.produtos_vendidos) {
                try {
                    const prods = JSON.parse(ag.produtos_vendidos);
                    if(Array.isArray(prods)){ prods.forEach(p => valorServicos += parseFloat(p.valor)); }
                } catch(e){}
            }

            const desconto = parseFloat(ag.desconto_aplicado || 0);
            if (desconto > 0) {
                totalEconomizado += desconto;
                historicoDescontos.push({ data: ag.data, tipo: ag.tipo_desconto || 'Outro', valor: desconto });
            }
            totalGasto += (valorServicos - desconto);
        }
    });

    const ultimaVisitaObj = clienteAgendamentos.find(ag => ag.status === 'concluido');
    const dataUltimaVisita = ultimaVisitaObj ? new Date(ultimaVisitaObj.data.replace(/-/g, '/')).toLocaleDateString('pt-BR') : 'Nunca';
    
    const ultimosServicosNomes = clienteAgendamentos.filter(ag => ag.status === 'concluido').slice(0, 5).map(ag => {
        let sNames = [];
        if(ag.servicos_ids) {
            ag.servicos_ids.split(',').forEach(sid => {
                const s = servicos[sid.trim()] || ((typeof adminJSData !== 'undefined' && adminJSData.combosData) ? adminJSData.combosData[sid.trim()] : null) || ((typeof combosData !== 'undefined') ? combosData[sid.trim()] : null);
                if(s) sNames.push(s.nome);
            });
        }
        return sNames.join(' + ');
    }).filter(Boolean);

    let textoDadosParaIA = `Nome do Cliente: ${cliente.nome}\n`;
    textoDadosParaIA += `Total Gasto Histórico: R$ ${totalGasto.toFixed(2)}\n`;
    textoDadosParaIA += `Visitas Concluídas: ${totalVisitas}\n`;
    textoDadosParaIA += `Data da Última Visita: ${dataUltimaVisita}\n`;
    textoDadosParaIA += `Assinante: ${isVip ? 'Sim, plano ' + planoNome : 'Não'}\n`;
    textoDadosParaIA += `Últimos serviços consumidos: ${ultimosServicosNomes.join(' | ')}\n`;

    let fotoClienteStr = cliente.foto_perfil ? cliente.foto_perfil.trim() : '';
    const fotoPerfil = (fotoClienteStr !== '') ? fotoClienteStr : 'uploads/default-profile.jpg';

    // --------------------------------------------------------------------------------------
    // ----------------- RENDERIZAÇÃO ESPECÍFICA: PAINEL DO BARBEIRO ------------------------
    // --------------------------------------------------------------------------------------
    if (isBarbeiroPanel) {
        let headerHtml = `
            <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid var(--border-light, #e2e8f0);">
                <div style="position: relative; flex-shrink: 0;">
                    <img src="${fotoPerfil}" style="width: 75px; height: 75px; border-radius: 50%; object-fit: cover; border: 3px solid var(--bg-card, #fff); box-shadow: 0 4px 6px rgba(0,0,0,0.1);" onerror="this.src='uploads/default-profile.jpg'">
                    ${isVip ? '<div title="Cliente Assinante" style="position: absolute; bottom: -2px; right: -2px; background: #10b981; color: white; border-radius: 50%; width: 26px; height: 26px; display: flex; align-items: center; justify-content: center; border: 2px solid var(--bg-card, #fff); box-shadow: 0 2px 4px rgba(0,0,0,0.1); font-size: 0.8rem;"><i class="fa fa-crown"></i></div>' : ''}
                </div>
                <div style="flex-grow: 1; overflow: hidden;">
                    <h2 style="margin: 0 0 5px 0; color: var(--text-main, #1e293b); font-weight: 800; font-size: 1.25rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${escHtmlDetalhes(cliente.nome)}</h2>
                    <div style="display: flex; flex-direction: column; gap: 4px; font-size: 0.85rem; color: var(--text-muted, #475569);">
                        <span style="display: flex; align-items: center; gap: 8px;"><i class="fa fa-envelope" style="width: 14px; text-align: center;"></i> ${escHtmlDetalhes(cliente.email)}</span>
                        <span style="display: flex; align-items: center; gap: 8px;"><i class="fa fa-phone" style="width: 14px; text-align: center;"></i> ${escHtmlDetalhes(cliente.telefone)}</span>
                    </div>
                </div>
            </div>
        `;

        let iaRaioxHtml = `
            <div style="background: linear-gradient(135deg, rgba(14,165,233,0.1) 0%, rgba(56,189,248,0.05) 100%); border: 1px solid rgba(14,165,233,0.2); border-radius: 12px; padding: 15px; margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <h4 style="margin: 0; color: #0284c7; display: flex; align-items: center; gap: 6px; font-size: 1rem;"><i class="fa fa-brain"></i> Raio-X (IA)</h4>
                    <button type="button" id="btn-gerar-raiox-${clienteId}" class="btn-primary" style="background: #0ea5e9; padding: 6px 12px; border-radius: 8px; font-size: 0.8rem; box-shadow: none; width: auto;"><i class="fa fa-magic"></i> Analisar</button>
                </div>
                <div id="raiox-content-${clienteId}" style="font-size: 0.85rem; color: var(--text-main, #1e293b); opacity: 0.9; line-height: 1.5;">
                    Descubra o perfil exato deste cliente e veja dicas de como fazê-lo voltar mais vezes.
                </div>
            </div>
        `;

        let statsHtml = `
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 20px;">
                <div style="background: var(--bg-hover, rgba(0,0,0,0.02)); padding: 15px; border-radius: 10px; text-align: center; border: 1px solid var(--border-light, #e2e8f0);">
                    <div style="font-size: 0.7rem; color: var(--text-muted, #64748b); font-weight: 700; text-transform: uppercase; margin-bottom: 3px;">Total Gasto</div>
                    <div style="font-size: 1.15rem; font-weight: 800; color: var(--text-main, #1e293b);">R$ ${totalGasto.toFixed(2).replace('.', ',')}</div>
                </div>
                <div style="background: var(--bg-hover, rgba(0,0,0,0.02)); padding: 15px; border-radius: 10px; text-align: center; border: 1px solid var(--border-light, #e2e8f0);">
                    <div style="font-size: 0.7rem; color: var(--text-muted, #64748b); font-weight: 700; text-transform: uppercase; margin-bottom: 3px;">Visitas</div>
                    <div style="font-size: 1.15rem; font-weight: 800; color: var(--secondary-color, #007bff);">${totalVisitas}</div>
                </div>
            </div>
        `;

        let historicoListHtml = `<div style="margin-bottom: 20px;">
            <h4 style="margin: 0 0 10px 0; color: var(--text-main, #1e293b); font-size: 1rem; display: flex; align-items: center; gap: 6px;"><i class="fa fa-history" style="color: var(--secondary-color, #007bff);"></i> Últimos Cortes</h4>
        `;
        if (clienteAgendamentos.length > 0) {
            historicoListHtml += '<div style="display: flex; flex-direction: column; gap: 8px;">';
            clienteAgendamentos.slice(0, 5).forEach(ag => {
                const dataF = new Date(ag.data.replace(/-/g, '/')).toLocaleDateString('pt-BR');
                let servicosTxt = [];
                let valorRow = 0;
                if(ag.servicos_ids) {
                    ag.servicos_ids.split(',').forEach(sid => {
                        const s = servicos[sid.trim()] || ((typeof combosData !== 'undefined') ? combosData[sid.trim()] : null);
                        if(s) { servicosTxt.push(s.nome); valorRow += parseFloat(s.valor); }
                    });
                }
                const descontoRow = parseFloat(ag.desconto_aplicado || 0);
                const valorFinalRow = valorRow - descontoRow;
                const corStatus = ag.status === 'concluido' ? '#10b981' : (ag.status === 'cancelado' ? '#ef4444' : '#f59e0b');

                historicoListHtml += `
                    <div style="background: var(--bg-hover, rgba(0,0,0,0.02)); border: 1px solid var(--border-light, #e2e8f0); border-radius: 8px; padding: 12px; display: flex; justify-content: space-between; align-items: center; border-left: 3px solid ${corStatus};">
                        <div style="overflow: hidden; padding-right: 10px;">
                            <div style="font-weight: 600; color: var(--text-main, #1e293b); font-size: 0.85rem; margin-bottom: 2px;">${dataF}</div>
                            <div style="font-size: 0.8rem; color: var(--text-muted, #64748b); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${servicosTxt.join(', ')}</div>
                        </div>
                        <div style="text-align: right; flex-shrink: 0;">
                            <div style="font-weight: 800; color: var(--text-main, #1e293b); font-size: 0.95rem;">R$ ${valorFinalRow.toFixed(2)}</div>
                        </div>
                    </div>
                `;
            });
            historicoListHtml += '</div></div>';
        } else {
            historicoListHtml += '<p style="color: var(--text-muted, #64748b); font-size: 0.85rem; font-style: italic; margin-top: 5px;">Nenhum agendamento registrado.</p></div>';
        }

        let anotacoesHtml = `
            <div>
                <h4 style="margin: 0 0 10px 0; color: var(--text-main, #1e293b); font-size: 1rem; display: flex; align-items: center; gap: 6px;"><i class="fa fa-sticky-note" style="color: #f59e0b;"></i> Anotações Privadas</h4>
                <form method="POST" action="barbeiro_actions.php">
                    <input type="hidden" name="csrf_token" value="${csrfToken}">
                    <input type="hidden" name="action" value="salvar_anotacao">
                    <input type="hidden" name="cliente_id" value="${clienteId}">
                    <textarea name="anotacao" class="modern-input" rows="3" style="resize: vertical; margin-bottom: 10px;" placeholder="Ex: prefere disfarçado na zero, não gosta de pomada...">${escHtmlDetalhes(clienteAnotacao)}</textarea>
                    <button type="submit" class="btn-primary" style="width: 100%;"><i class="fa fa-save"></i> Salvar Anotação</button>
                </form>
            </div>
        `;

        const contentDiv = document.getElementById('cliente-detalhes-content');
        if(contentDiv) {
            contentDiv.innerHTML = `
                ${headerHtml}
                ${assinaturaHtml}
                ${iaRaioxHtml}
                ${statsHtml}
                ${historicoListHtml}
                ${anotacoesHtml}
            `;
        }
    } 
    // --------------------------------------------------------------------------------------
    // ----------------- RENDERIZAÇÃO CLÁSSICA: PAINEL DO ADMINISTRADOR ---------------------
    // --------------------------------------------------------------------------------------
    else {
        let headerHtml = `
            <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 25px; padding-bottom: 20px; border-bottom: 1px solid #e2e8f0;">
                <div style="position: relative;">
                    <img src="${fotoPerfil}" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 4px solid #fff; box-shadow: 0 4px 6px rgba(0,0,0,0.05);" onerror="this.src='uploads/default-profile.jpg'">
                    ${isVip ? '<div title="Cliente Assinante" style="position: absolute; bottom: 0; right: 0; background: #10b981; color: white; border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border: 3px solid #f8fafc; box-shadow: 0 2px 4px rgba(0,0,0,0.1);"><i class="fa fa-crown"></i></div>' : ''}
                </div>
                <div style="flex-grow: 1;">
                    <h2 style="margin: 0 0 8px 0; color: #1e293b; font-weight: 800; font-size: 1.5rem;">${escHtmlDetalhes(cliente.nome)}</h2>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; font-size: 0.9rem; color: #475569; background: #fff; padding: 15px; border-radius: 12px; border: 1px solid #e2e8f0;">
                        <span style="display: flex; align-items: center; gap: 8px;"><i class="fa fa-envelope" style="color: #94a3b8;"></i> ${escHtmlDetalhes(cliente.email)}</span>
                        <span style="display: flex; align-items: center; gap: 8px;"><i class="fa fa-phone" style="color: #94a3b8;"></i> ${escHtmlDetalhes(cliente.telefone)}</span>
                        <span style="display: flex; align-items: center; gap: 8px;"><i class="fa fa-id-card" style="color: #94a3b8;"></i> ${escHtmlDetalhes(cliente.cpf || 'Não informado')}</span>
                        <span style="display: flex; align-items: center; gap: 8px;"><i class="fa fa-birthday-cake" style="color: #94a3b8;"></i> ${cliente.data_nascimento ? new Date(cliente.data_nascimento.replace(/-/g, '/')).toLocaleDateString('pt-BR') : 'Não informada'}</span>
                    </div>
                </div>
            </div>
        `;

        let iaRaioxHtml = `
            <div style="background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); border: 1px solid #bae6fd; border-radius: 12px; padding: 20px; margin-bottom: 25px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 12px;">
                    <h4 style="margin: 0; color: #0369a1; display: flex; align-items: center; gap: 8px; font-size: 1.15rem;"><i class="fa fa-brain"></i> Raio-X Comportamental (IA)</h4>
                    <button id="btn-gerar-raiox-${clienteId}" class="btn-action text-btn" style="background: #0ea5e9; color: white; border: none; padding: 8px 15px; border-radius: 8px; cursor: pointer; font-size: 0.9rem; font-weight: bold; box-shadow: 0 4px 10px rgba(14,165,233,0.2);"><i class="fa fa-magic"></i> Analisar Cliente</button>
                </div>
                <div id="raiox-content-${clienteId}" style="font-size: 0.95rem; color: #0c4a6e; line-height: 1.6;">
                    <i class="fa fa-info-circle"></i> Gere uma análise inteligente com o Gemini para descobrir o perfil exato de consumo deste cliente e receber sugestões claras de como o fazer regressar ou aumentar o que ele gasta na sua barbearia.
                </div>
            </div>
        `;

        let statsHtml = `
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 25px;">
                <div style="background: #fff; padding: 20px; border-radius: 12px; text-align: center; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                    <div style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px;">Total Gasto</div>
                    <div style="font-size: 1.5rem; font-weight: 800; color: #1e293b;">R$ ${totalGasto.toFixed(2).replace('.', ',')}</div>
                </div>
                <div style="background: #fff; padding: 20px; border-radius: 12px; text-align: center; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                    <div style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px;">Economizado</div>
                    <div style="font-size: 1.5rem; font-weight: 800; color: #10b981;">R$ ${totalEconomizado.toFixed(2).replace('.', ',')}</div>
                </div>
                <div style="background: #fff; padding: 20px; border-radius: 12px; text-align: center; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                    <div style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px;">Visitas Concluídas</div>
                    <div style="font-size: 1.5rem; font-weight: 800; color: var(--secondary-color, #007bff);">${totalVisitas}</div>
                </div>
            </div>
        `;

        let historicoListHtml = '';
        if (clienteAgendamentos.length > 0) {
            historicoListHtml = '<div style="display: flex; flex-direction: column; gap: 10px;">';
            clienteAgendamentos.forEach(ag => {
                const dataF = new Date(ag.data.replace(/-/g, '/')).toLocaleDateString('pt-BR');
                const barbeiroNome = barbeiros[ag.barbeiro_id] ? barbeiros[ag.barbeiro_id].nome : 'N/A';
                const statusLabel = ag.status.replace(/_/g, ' ');
                
                let detalhesExtras = '';
                if (ag.tipo_desconto === 'assinatura_vip' || ag.tipo_desconto === 'adesao_plano') {
                    detalhesExtras += `<span style="background: #e0e7ff; color: #4f46e5; padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; margin-right: 5px;"><i class="fa fa-crown"></i> Assinatura</span>`;
                }
                if (parseFloat(ag.desconto_aplicado) > 0 && ag.tipo_desconto !== 'assinatura_vip' && ag.tipo_desconto !== 'adesao_plano') {
                     detalhesExtras += `<span style="background: #fef3c7; color: #d97706; padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; margin-right: 5px;"><i class="fa fa-tag"></i> ${ag.tipo_desconto}</span>`;
                }

                let servicosTxt = [];
                let valorRow = 0;
                if(ag.servicos_ids) {
                    ag.servicos_ids.split(',').forEach(sid => {
                        const s = servicos[sid.trim()] || ((typeof adminJSData !== 'undefined' && adminJSData.combosData) ? adminJSData.combosData[sid.trim()] : null) || ((typeof combosData !== 'undefined') ? combosData[sid.trim()] : null);
                        if(s) { servicosTxt.push(s.nome); valorRow += parseFloat(s.valor); }
                    });
                }
                if (ag.produtos_vendidos) {
                    try {
                        const prods = JSON.parse(ag.produtos_vendidos);
                        if(Array.isArray(prods)){ prods.forEach(p => valorRow += parseFloat(p.valor)); }
                    } catch(e){}
                }
                
                const descontoRow = parseFloat(ag.desconto_aplicado || 0);
                const valorFinalRow = valorRow - descontoRow;

                historicoListHtml += `
                    <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 15px; display: flex; justify-content: space-between; align-items: center; transition: 0.2s; border-left: 4px solid ${ag.status === 'concluido' ? '#10b981' : (ag.status === 'cancelado' ? '#ef4444' : '#f59e0b')};">
                        <div>
                            <div style="font-weight: 700; color: #1e293b; margin-bottom: 5px; display: flex; align-items: center; gap: 10px;">
                                ${dataF} às ${escHtmlDetalhes(ag.hora)} 
                                <span style="font-size: 0.7rem; padding: 3px 8px; border-radius: 6px; text-transform: uppercase; font-weight: 800; background: #f1f5f9; color: #475569;">${escHtmlDetalhes(statusLabel)}</span>
                            </div>
                            <div style="font-size: 0.9rem; color: #475569; display: flex; align-items: center; gap: 15px;">
                                <span><i class="fa fa-cut" style="color: #94a3b8; margin-right: 4px;"></i> ${servicosTxt.join(', ')}</span>
                                <span><i class="fa fa-user-tie" style="color: #94a3b8; margin-right: 4px;"></i> ${barbeiroNome}</span>
                            </div>
                            ${detalhesExtras ? `<div style="margin-top: 8px;">${detalhesExtras}</div>` : ''}
                        </div>
                        <div style="text-align: right;">
                            ${descontoRow > 0 ? `<div style="font-size: 0.8rem; color: #ef4444; font-weight: 600; margin-bottom: 2px;">- R$ ${descontoRow.toFixed(2)}</div>` : ''}
                            <div style="font-weight: 800; color: #1e293b; font-size: 1.1rem;">R$ ${valorFinalRow.toFixed(2)}</div>
                            ${ag.status === 'concluido' ? 
                                `<div style="font-weight: 800; color: #10b981; font-size: 0.75rem; text-transform: uppercase; margin-top: 5px;"><i class="fa fa-check-circle"></i> Pago</div>` : 
                                `<a href="admin.php?tab=agendamentos&busca=${ag.id}" class="btn-action text-btn btn-info-action" style="margin-top: 5px; display: inline-flex; padding: 4px 10px; font-size: 0.75rem; background: #0ea5e9; color: white; border-radius: 6px; text-decoration: none;"><i class="fa fa-eye"></i> Ver</a>`
                            }
                        </div>
                    </div>
                `;
            });
            historicoListHtml += '</div>';
        } else {
            historicoListHtml = '<div style="background: #fff; border: 1px dashed #cbd5e1; padding: 20px; text-align: center; border-radius: 10px; color: #64748b;"><i class="fa fa-calendar-times" style="font-size: 2rem; margin-bottom: 10px; opacity: 0.5; display: block;"></i> Nenhum agendamento registrado.</div>';
        }

        let cuponsHtml = '';
        if (historicoDescontos.length > 0) {
            cuponsHtml = `
                <div style="margin-top: 25px; background: #fef3c7; padding: 20px; border-radius: 12px; border: 1px solid #fde68a;">
                    <h4 style="margin: 0 0 15px 0; color: #92400e; font-size: 1.05rem; display: flex; align-items: center; gap: 8px;"><i class="fa fa-gift"></i> Histórico de Benefícios Resgatados</h4>
                    <ul style="list-style: none; padding: 0; margin: 0; max-height: 120px; overflow-y: auto;">
                        ${historicoDescontos.map(d => `
                            <li style="display: flex; justify-content: space-between; font-size: 0.9rem; padding: 8px 0; border-bottom: 1px dashed rgba(146, 64, 14, 0.2); color: #92400e;">
                                <span>${new Date(d.data.replace(/-/g, '/')).toLocaleDateString('pt-BR')} - <strong>${d.tipo.toUpperCase()}</strong></span>
                                <span style="font-weight: 800;">R$ ${d.valor.toFixed(2)}</span>
                            </li>
                        `).join('')}
                    </ul>
                </div>
            `;
        }

        let anotacoesHtml = `
            <div style="margin-top: 30px; background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0;">
                <h4 style="margin: 0 0 15px 0; font-size: 1.1rem; color: #1e293b; display: flex; align-items: center; gap: 8px;"><i class="fa fa-sticky-note" style="color: #f59e0b;"></i> Anotações Internas (Privadas)</h4>
                <form method="POST" action="admin.php">
                    <input type="hidden" name="csrf_token" value="${csrfToken}">
                    <input type="hidden" name="action" value="salvar_anotacao">
                    <input type="hidden" name="cliente_id" value="${clienteId}">
                    <textarea name="anotacao" rows="3" style="width: 100%; padding: 15px; border-radius: 10px; border: 1px solid #cbd5e1; font-size: 0.95rem; resize: vertical; font-family: 'Inter', sans-serif; outline: none; transition: 0.2s;" placeholder="Escreva observações sobre o cliente aqui... (Ex: prefere corte na tesoura, alérgico a produto X)" onfocus="this.style.borderColor='var(--secondary-color)'; this.style.boxShadow='0 0 0 3px rgba(0,123,255,0.1)'" onblur="this.style.borderColor='#cbd5e1'; this.style.boxShadow='none'">${escHtmlDetalhes(clienteAnotacao)}</textarea>
                    <button type="submit" class="btn-primary" style="margin-top: 15px; padding: 10px 20px; font-size: 0.95rem; border-radius: 8px;"><i class="fa fa-save"></i> Salvar Anotação</button>
                </form>
            </div>
        `;

        const nomeElement = document.getElementById('cliente-detalhes-nome');
        if(nomeElement) nomeElement.style.display = 'none';

        const contentDiv = document.getElementById('cliente-detalhes-content');
        if(contentDiv) {
            contentDiv.innerHTML = `
                ${headerHtml}
                ${assinaturaHtml}
                ${iaRaioxHtml}
                ${statsHtml}
                <div style="margin-top: 20px;">
                    <h4 style="border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; margin-bottom: 15px; color: #1e293b; font-size: 1.1rem; display: flex; align-items: center; gap: 8px;"><i class="fa fa-history" style="color: var(--secondary-color, #007bff);"></i> Histórico de Agendamentos</h4>
                    <div style="max-height: 350px; overflow-y: auto; padding-right: 5px;">
                        ${historicoListHtml}
                    </div>
                </div>
                ${cuponsHtml}
                ${anotacoesHtml}
            `;
        }
    }

    // --------------------------------------------------------------------------------------
    // ---------------------------- EVENTO DE CLICK (IA) COMUM ------------------------------
    // --------------------------------------------------------------------------------------
    setTimeout(() => {
        const btnRaioX = document.getElementById(`btn-gerar-raiox-${clienteId}`);
        if (btnRaioX) {
            btnRaioX.addEventListener('click', async function() {
                const contentRaioX = document.getElementById(`raiox-content-${clienteId}`);
                
                btnRaioX.disabled = true;
                btnRaioX.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Analisando...';
                contentRaioX.innerHTML = `<div style="text-align: center; padding: 15px;"><i class="fa fa-circle-notch fa-spin fa-2x" style="color: #0ea5e9; margin-bottom: 10px;"></i><br><span style="color: ${isBarbeiroPanel ? 'var(--text-main)' : '#0c4a6e'}; font-weight: bold;">A Inteligência Artificial está processando o histórico deste cliente...</span></div>`;
                
                try {
                    const response = await fetch('ajax_gemini.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'raio_x_cliente', dados_cliente: textoDadosParaIA })
                    });
                    const result = await response.json();
                    
                    if (result.success) {
                        contentRaioX.innerHTML = '<strong style="display:block; margin-bottom:8px; color: #0369a1; font-size: 1.05rem;"><i class="fa fa-check-circle"></i> Análise Concluída:</strong>' + escHtmlDetalhes(result.resposta).replace(/\n/g, '<br>');
                    } else {
                        contentRaioX.innerHTML = '<span style="color:#ef4444; font-weight: bold;"><i class="fa fa-exclamation-triangle"></i> Erro: ' + escHtmlDetalhes(result.error) + '</span>';
                    }
                } catch(e) {
                    contentRaioX.innerHTML = '<span style="color:#ef4444; font-weight: bold;"><i class="fa fa-wifi"></i> Erro de conexão com o servidor ou timeout. Tente novamente.</span>';
                    console.error(e);
                } finally {
                    btnRaioX.disabled = false;
                    btnRaioX.innerHTML = '<i class="fa fa-sync-alt"></i> Atualizar Análise';
                }
            });
        }
    }, 100);
};