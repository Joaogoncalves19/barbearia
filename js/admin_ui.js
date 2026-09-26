// --- FUNÇÃO PARA MANTER A ABA AO ATUALIZAR ---
function reloadCurrentTab(event) {
    event.preventDefault();
    const activeTab = document.querySelector('.tab-btn.active');
    if (activeTab) {
        const tabId = activeTab.getAttribute('data-tab');
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tabId);
        window.location.href = url.toString();
    } else {
        window.location.reload();
    }
}

// --- RELÓGIO DA TOPBAR ---
// Monta a estrutura uma vez e depois só troca o texto. Antes o innerHTML era
// reescrito a cada segundo: os ícones eram recriados e a largura do chip
// mudava junto com os segundos, empurrando os botões vizinhos da barra.
(function () {
    var elemento = null;
    var alvoData = null;
    var alvoHora = null;
    var timer = null;

    function montar(el) {
        el.replaceChildren();
        var iconeData = document.createElement('i');
        iconeData.className = 'fa fa-calendar-day';
        alvoData = document.createElement('span');
        alvoData.className = 'topbar-date';
        var separador = document.createElement('span');
        separador.className = 'topbar-datetime-sep';
        separador.setAttribute('aria-hidden', 'true');
        var iconeHora = document.createElement('i');
        iconeHora.className = 'fa fa-clock';
        alvoHora = document.createElement('time');
        alvoHora.className = 'topbar-time';
        el.append(iconeData, alvoData, separador, iconeHora, alvoHora);
    }

    // "qui., 10 de set." -> "Qui, 10 set"
    function formatarDataCurta(agora) {
        var texto = agora
            .toLocaleDateString('pt-BR', { weekday: 'short', day: '2-digit', month: 'short' })
            .replace(/\./g, '')
            .replace(/ de /g, ' ');
        return texto.charAt(0).toUpperCase() + texto.slice(1);
    }

    function formatarDataLonga(agora) {
        var texto = agora.toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
        return texto.charAt(0).toUpperCase() + texto.slice(1);
    }

    function updateTopbarTime() {
        if (!elemento) elemento = document.getElementById('topbar-datetime');
        if (!elemento) return;
        if (!alvoData) montar(elemento);

        var agora = new Date();
        alvoData.textContent = formatarDataCurta(agora);
        alvoHora.textContent = agora.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
        alvoHora.dateTime = agora.toISOString();
        elemento.title = formatarDataLonga(agora);

        // O relógio mostra hora e minuto, então nada muda antes da virada do
        // minuto: reagenda no minuto cheio em vez de acordar a cada segundo.
        window.clearTimeout(timer);
        timer = window.setTimeout(updateTopbarTime, (60 - agora.getSeconds()) * 1000 - agora.getMilliseconds() + 50);
    }

    document.addEventListener('DOMContentLoaded', updateTopbarTime);
    // Aba oculta / máquina suspensa: reacerta a hora ao voltar.
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) updateTopbarTime();
    });
})();

// Atualiza saudação de acordo com o horário
document.addEventListener("DOMContentLoaded", function() {
    const msgElement = document.getElementById('boas-vindas-msg');
    if(msgElement) {
        const horaAt = new Date().getHours();
        let saudacao = "Boa noite";
        if(horaAt >= 5 && horaAt < 12) saudacao = "Bom dia";
        else if(horaAt >= 12 && horaAt < 18) saudacao = "Boa tarde";
        msgElement.innerHTML = `<i class="fa fa-hand-sparkles" style="color:#f59e0b;"></i> ${saudacao}, bom trabalho!`;
    }
});

window.initModalBloqueio = function(trigger, modal) {
    const barbeiroId = trigger.dataset.id || '';
    const barbeiroNome = trigger.dataset.nome || 'Profissional';
    const calendario = modal.querySelector('#bloqueio-calendario');
    const horariosContainer = modal.querySelector('#bloqueio-horarios-container');
    const inputBarbeiro = modal.querySelector('#bloqueio_barbeiro_id');
    const inputData = modal.querySelector('#bloqueio_data_selecionada');
    const inputActionModal = modal.querySelector('#action_bloqueio');
    const inputMesModal = modal.querySelector('#bloqueio_mes_atual');
    const inputAnoModal = modal.querySelector('#bloqueio_ano_atual');
    const titulo = modal.querySelector('#bloqueio-barbeiro-nome');
    const btnToggleModal = modal.querySelector('#btn-toggle-todos-bloqueios');
    const meses = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
    const hoje = new Date();
    hoje.setHours(0, 0, 0, 0);
    let mesAtual = hoje.getMonth();
    let anoAtual = hoje.getFullYear();
    let requestId = 0;

    inputBarbeiro.value = barbeiroId;
    inputData.value = '';
    inputActionModal.value = 'salvar_bloqueios';
    modal.querySelector('#modo_mes').value = '';
    if (titulo) {
        titulo.innerHTML = '<div class="bloqueio-header-icon"><i class="fa fa-calendar-times"></i></div> Agenda de ' + barbeiroNome;
    }

    function placeholderHorarios(message, icon) {
        horariosContainer.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#94a3b8;padding:40px 10px;font-style:italic;">'
            + '<i class="fa ' + icon + '" style="font-size:2rem;margin-bottom:12px;opacity:.35;display:block;"></i>'
            + message + '</div>';
        btnToggleModal.style.display = 'none';
    }

    async function carregarHorarios(data) {
        const currentRequest = ++requestId;
        placeholderHorarios('Carregando horários...', 'fa-spinner fa-spin');
        const endpoint = mode => 'get_horarios.php?' + new URLSearchParams({
            barbeiro_id: barbeiroId,
            data: data,
            mode: mode
        }).toString();
        try {
            const responses = await Promise.all([
                fetch(endpoint('todos_slots')),
                fetch(endpoint('bloqueados')),
                fetch(endpoint('ocupados'))
            ]);
            const results = await Promise.all(responses.map(response => response.json()));
            if (currentRequest !== requestId) return;
            const slots = results[0];
            const blockedSet = new Set(Array.isArray(results[1]) ? results[1] : []);
            const occupiedSet = new Set(Array.isArray(results[2]) ? results[2] : []);
            horariosContainer.innerHTML = '';

            if (!Array.isArray(slots) || !slots.length) {
                placeholderHorarios('Este profissional não possui expediente nesta data.', 'fa-calendar-xmark');
                return;
            }

            slots.forEach(hora => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'horario-btn-bloqueio';
                button.textContent = hora;

                if (!blockedSet.has(hora) && occupiedSet.has(hora)) {
                    button.classList.add('ocupado');
                    button.disabled = true;
                    button.title = 'Horário ocupado por agendamento ou intervalo fixo';
                    horariosContainer.appendChild(button);
                    return;
                }

                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.name = 'horarios_bloqueados[]';
                checkbox.value = hora;
                checkbox.hidden = true;
                if (blockedSet.has(hora)) {
                    button.classList.add('selected');
                    checkbox.checked = true;
                }
                button.addEventListener('click', function() {
                    button.classList.toggle('selected');
                    checkbox.checked = button.classList.contains('selected');
                });
                horariosContainer.append(button, checkbox);
            });
            btnToggleModal.style.display = 'inline-block';
        } catch (error) {
            placeholderHorarios('Não foi possível carregar os horários. Tente novamente.', 'fa-triangle-exclamation');
        }
    }

    function selecionarDia(button, data) {
        calendario.querySelectorAll('.dia.selecionado').forEach(dia => dia.classList.remove('selecionado'));
        button.classList.add('selecionado');
        inputData.value = data;
        inputActionModal.value = 'salvar_bloqueios';
        carregarHorarios(data);
    }

    function renderCalendario() {
        inputMesModal.value = mesAtual;
        inputAnoModal.value = anoAtual;
        inputData.value = '';
        placeholderHorarios('Selecione um dia no calendário ao lado para ver os horários.', 'fa-hand-pointer');
        const primeiroDia = new Date(anoAtual, mesAtual, 1).getDay();
        const totalDias = new Date(anoAtual, mesAtual + 1, 0).getDate();
        calendario.innerHTML = '';

        const header = document.createElement('div');
        header.className = 'calendario-header';
        const anterior = document.createElement('button');
        anterior.type = 'button';
        anterior.className = 'nav-btn';
        anterior.title = 'Mês anterior';
        anterior.innerHTML = '<i class="fa fa-chevron-left"></i>';
        const tituloMes = document.createElement('span');
        tituloMes.className = 'mes-ano';
        tituloMes.textContent = meses[mesAtual] + ' ' + anoAtual;
        const proximo = document.createElement('button');
        proximo.type = 'button';
        proximo.className = 'nav-btn';
        proximo.title = 'Próximo mês';
        proximo.innerHTML = '<i class="fa fa-chevron-right"></i>';
        header.append(anterior, tituloMes, proximo);

        const semana = document.createElement('div');
        semana.className = 'dias-semana-grid';
        ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'].forEach(nome => {
            const span = document.createElement('span');
            span.textContent = nome;
            semana.appendChild(span);
        });
        const dias = document.createElement('div');
        dias.className = 'dias-grid';
        for (let i = 0; i < primeiroDia; i++) {
            const vazio = document.createElement('span');
            vazio.className = 'dia-vazio';
            dias.appendChild(vazio);
        }
        for (let dia = 1; dia <= totalDias; dia++) {
            const dataObj = new Date(anoAtual, mesAtual, dia);
            const data = anoAtual + '-' + String(mesAtual + 1).padStart(2, '0') + '-' + String(dia).padStart(2, '0');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'dia';
            button.textContent = dia;
            if (dataObj < hoje) {
                button.classList.add('passado');
                button.disabled = true;
            } else {
                button.addEventListener('click', () => selecionarDia(button, data));
            }
            dias.appendChild(button);
        }
        anterior.addEventListener('click', function() {
            mesAtual--;
            if (mesAtual < 0) { mesAtual = 11; anoAtual--; }
            renderCalendario();
        });
        proximo.addEventListener('click', function() {
            mesAtual++;
            if (mesAtual > 11) { mesAtual = 0; anoAtual++; }
            renderCalendario();
        });
        calendario.append(header, semana, dias);
    }

    renderCalendario();
};

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('form-bloqueio-horarios');
    if (!form) return;
    form.addEventListener('submit', function(event) {
        const action = document.getElementById('action_bloqueio').value;
        const data = document.getElementById('bloqueio_data_selecionada').value;
        if (action === 'salvar_bloqueios' && !data) {
            event.preventDefault();
            alert('Selecione um dia no calendário antes de salvar.');
        }
    });
});

// --- LÓGICA DO DROPDOWN CUSTOMIZADO DE CLIENTES ---
document.addEventListener("DOMContentLoaded", function() {
    const buscaInput = document.getElementById('busca_cliente_input');
    const listaDropdown = document.getElementById('lista_clientes_dropdown');
    const hiddenId = document.getElementById('manual_cliente_id');
    const nomeInput = document.getElementById('manual_nome');
    const telefoneInput = document.getElementById('manual_telefone');

    if(buscaInput) {
        // Mostra a lista ao focar
        buscaInput.addEventListener('focus', () => listaDropdown.style.display = 'block');
        
        // Esconde a lista ao clicar fora
        document.addEventListener('click', (e) => {
            if(!buscaInput.contains(e.target) && !listaDropdown.contains(e.target)) {
                listaDropdown.style.display = 'none';
            }
        });

        // Filtra os nomes/telefones digitando
        buscaInput.addEventListener('input', function() {
            const term = this.value.toLowerCase();
            const items = listaDropdown.querySelectorAll('li');
            
            items.forEach(item => {
                const text = item.textContent.toLowerCase();
                if(text.includes(term)) item.style.display = 'flex';
                else item.style.display = 'none';
            });
        });

        // Selecionar um item da lista
        listaDropdown.addEventListener('click', function(e) {
            const li = e.target.closest('li');
            if(!li) return;
            
            const id = li.getAttribute('data-id');
            const nome = li.getAttribute('data-nome');
            const telefone = li.getAttribute('data-telefone');

            hiddenId.value = id;
            
            if(id) { // Cliente Existente
                buscaInput.value = nome;
                nomeInput.value = nome;
                nomeInput.readOnly = true;
                nomeInput.style.backgroundColor = '#f1f5f9';
                telefoneInput.value = telefone;
                telefoneInput.readOnly = true;
                telefoneInput.style.backgroundColor = '#f1f5f9';
            } else { // Novo Cliente
                buscaInput.value = '';
                nomeInput.value = '';
                nomeInput.readOnly = false;
                nomeInput.style.backgroundColor = '#fff';
                telefoneInput.value = '';
                telefoneInput.readOnly = false;
                telefoneInput.style.backgroundColor = '#fff';
                nomeInput.focus();
            }
            
            listaDropdown.style.display = 'none';
        });
    }
});

// Outros scripts da página (Bloqueio de horários, etc)
document.addEventListener('DOMContentLoaded', function() {
    const containerHorarios = document.getElementById('bloqueio-horarios-container');
    const btnToggle = document.getElementById('btn-toggle-todos-bloqueios');
    
    const btnBloquearMes = document.getElementById('btn-bloquear-mes');
    const btnDesbloquearMes = document.getElementById('btn-desbloquear-mes');
    const formBloqueio = document.getElementById('form-bloqueio-horarios');
    const inputAction = document.getElementById('action_bloqueio');
    const inputModo = document.getElementById('modo_mes');
    const inputMes = document.getElementById('bloqueio_mes_atual');
    const inputAno = document.getElementById('bloqueio_ano_atual');
    
    const mesesTextoMap = {"Jan":0, "Fev":1, "Mar":2, "Abr":3, "Mai":4, "Jun":5, "Jul":6, "Ago":7, "Set":8, "Out":9, "Nov":10, "Dez":11};

    function setMesAnoFromCalendar() {
        const mesAnoSpan = document.querySelector('#bloqueio-calendario .mes-ano');
        if (mesAnoSpan) {
            const partes = mesAnoSpan.textContent.trim().split(' ');
            if (partes.length >= 2) {
                inputMes.value = mesesTextoMap[partes[0]];
                inputAno.value = partes[1];
                return partes[0] + ' de ' + partes[1];
            }
        }
        return null;
    }

    if (btnBloquearMes) {
        btnBloquearMes.addEventListener('click', function() {
            const mesLegivel = setMesAnoFromCalendar();
            if(!mesLegivel) return alert('Por favor, abra a janela de bloqueios do profissional primeiro.');
            
            if(confirm(`Deseja realmente BLOQUEAR todos os horários livres de todos os dias do mês de ${mesLegivel}?\n\n(Ação ideal para Férias ou Fechamento temporário)`)){
                inputAction.value = 'acao_mes_inteiro';
                inputModo.value = 'bloquear';
                formBloqueio.submit();
            }
        });
    }
    
    if (btnDesbloquearMes) {
        btnDesbloquearMes.addEventListener('click', function() {
            const mesLegivel = setMesAnoFromCalendar();
            if(!mesLegivel) return alert('Por favor, abra a janela de bloqueios do profissional primeiro.');

            if(confirm(`Deseja realmente DESBLOQUEAR todos os horários fechados manualmente neste mês (${mesLegivel})?\n\n(Isso não afetará os agendamentos já marcados pelos clientes)`)){
                inputAction.value = 'acao_mes_inteiro';
                inputModo.value = 'desbloquear';
                formBloqueio.submit();
            }
        });
    }

    if (containerHorarios && btnToggle) {
        const observer = new MutationObserver(function(mutations) {
            const botoes = containerHorarios.querySelectorAll('.horario-btn-bloqueio:not(.ocupado)');
            
            if (botoes.length > 0) {
                btnToggle.style.display = 'inline-block';
                const todosSelecionados = Array.from(botoes).every(b => b.classList.contains('selected'));
                btnToggle.innerHTML = todosSelecionados ? '<i class="fa fa-unlock"></i> Desbloquear Dia' : '<i class="fa fa-lock"></i> Bloquear Dia';
            } else {
                btnToggle.style.display = 'none';
            }
        });
        
        observer.observe(containerHorarios, { childList: true, subtree: true });

        btnToggle.addEventListener('click', function() {
            const botoes = containerHorarios.querySelectorAll('.horario-btn-bloqueio:not(.ocupado)');
            const todosSelecionados = Array.from(botoes).every(b => b.classList.contains('selected'));
            
            botoes.forEach(btn => {
                const input = btn.nextElementSibling; 
                if (todosSelecionados) {
                    btn.classList.remove('selected');
                    if(input && input.type === 'checkbox') input.checked = false;
                } else {
                    btn.classList.add('selected');
                    if(input && input.type === 'checkbox') input.checked = true;
                }
            });
            
            this.innerHTML = todosSelecionados ? '<i class="fa fa-lock"></i> Bloquear Dia' : '<i class="fa fa-unlock"></i> Desbloquear Dia';
        });
    }

    // --- LÓGICA DA GRADE DE HORÁRIOS FIXA DOS BARBEIROS ---
    initBarbeiroScheduleGrid();
});

// Extraída como função nomeada para poder ser rechamada quando a aba
// "barbeiros" é carregada depois via AJAX (ver js/admin_ui_core.js).
function initBarbeiroScheduleGrid() {
    const selectBarbeiroGrade = document.getElementById('select-barbeiro-grade');
    const containerGrade = document.getElementById('container-grade-horarios');
    const avisoGrade = document.getElementById('aviso-selecao-grade');
    const inputBarbeiroIdGrade = document.getElementById('input_barbeiro_id_grade');
    const btnReplicar = document.getElementById('btn-replicar-horarios');

    if (selectBarbeiroGrade) {
        selectBarbeiroGrade.addEventListener('change', function() {
            const barbeiroId = this.value;
            if (barbeiroId) {
                containerGrade.style.display = 'block';
                avisoGrade.style.display = 'none';
                inputBarbeiroIdGrade.value = barbeiroId;

                // Reset all inputs para evitar dados cruzados
                document.querySelectorAll('.schedule-table input[type="time"]').forEach(input => input.value = '');
                document.querySelectorAll('.schedule-table input[type="checkbox"]').forEach(input => input.checked = false);

                // Carrega horários existentes da variável global definida no PHP
                const horarios = typeof adminJSData !== 'undefined' && adminJSData.horariosTrabalhoData ? adminJSData.horariosTrabalhoData : (typeof horariosTrabalhoData !== 'undefined' ? horariosTrabalhoData : []);
                
                horarios.forEach(h => {
                    let hBarbeiroId, hDia, hInicio, hFim;
                    
                    // Suporte para o array em formato texto legado ou JSON direto do SQLite
                    if (typeof h === 'string') {
                        const parts = h.split('|');
                        hBarbeiroId = parts[0];
                        hDia = parts[1];
                        hInicio = parts[2];
                        hFim = parts[3];
                    } else if (typeof h === 'object') {
                        hBarbeiroId = h.barbeiro_id;
                        hDia = h.dia;
                        hInicio = h.inicio;
                        hFim = h.fim;
                    }

                    if (hBarbeiroId === barbeiroId) {
                        const checkbox = document.getElementById('dia_ativo_' + hDia);
                        const inputInicio = document.getElementById('inicio_' + hDia);
                        const inputFim = document.getElementById('fim_' + hDia);
                        
                        if (checkbox) checkbox.checked = true;
                        if (inputInicio) inputInicio.value = hInicio;
                        if (inputFim) inputFim.value = hFim;
                    }
                });

            } else {
                containerGrade.style.display = 'none';
                avisoGrade.style.display = 'block';
                inputBarbeiroIdGrade.value = '';
            }
        });
        
        // Ação do Botão Replicar Horários de Segunda
        if (btnReplicar) {
            btnReplicar.addEventListener('click', function() {
                const segAtivo = document.getElementById('dia_ativo_1').checked;
                const segInicio = document.getElementById('inicio_1').value;
                const segFim = document.getElementById('fim_1').value;
                
                const diasParaReplicar = [2, 3, 4, 5, 6]; // Terça (2) a Sábado (6)
                
                diasParaReplicar.forEach(dia => {
                    document.getElementById('dia_ativo_' + dia).checked = segAtivo;
                    document.getElementById('inicio_' + dia).value = segInicio;
                    document.getElementById('fim_' + dia).value = segFim;
                });
                
                alert('Horários de Segunda-feira replicados para a semana (Terça a Sábado)!');
            });
        }
    }
}
window.__reinitBarbeiroGrid = initBarbeiroScheduleGrid;
