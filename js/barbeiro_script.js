document.addEventListener('DOMContentLoaded', function () {
    // --- Lógica Geral dos Modais ---
    const modals = document.querySelectorAll('.modal-overlay');
    const openModalButtons = document.querySelectorAll('[data-modal-target]');
    const closeModalButtons = document.querySelectorAll('.modal-close');
    
    function openModal(modal) { if (modal) modal.style.display = 'flex'; }
    function closeModal(modal) { if (modal) { modal.style.display = 'none'; const form = modal.querySelector('form'); if(form) form.reset(); } }
    
    openModalButtons.forEach(button => {
        button.addEventListener('click', () => {
            const modal = document.querySelector(button.dataset.modalTarget);
            if (!modal) return;

            // **CORREÇÃO: Abre o modal ANTES de manipular seu conteúdo**
            openModal(modal);

            // Preenche os dados do modal depois que ele está visível
            if(modal.id === 'modal-add-produto') {
                modal.querySelector('#produto_agendamento_id_barbeiro').value = button.dataset.id;
            }
            if(modal.id === 'modal-reagendamento') {
                modal.querySelector('#reagendar_agendamento_id').value = button.dataset.id;
                modal.querySelector('#reagendar_servicos_ids').value = button.dataset.servicosIds;
                
                // Limpa o estado anterior do modal de reagendamento
                document.getElementById('reagendar_horarios-container').innerHTML = 'Selecione uma nova data.';
                document.getElementById('reagendar_horario').value = '';
                
                // Dispara a criação do calendário
                gerarCalendarioReagendamento(new Date().getFullYear(), new Date().getMonth());
            }
        });
    });

    closeModalButtons.forEach(button => button.addEventListener('click', () => closeModal(button.closest('.modal-overlay'))));
    // Fechamento por clique no fundo desativado de propósito: evita perder o que
    // está sendo preenchido no modal por um clique acidental. Fecha só pelo botão X.
    
    // --- Lógica do Novo Calendário de Reagendamento ---
    const calendarioContainer = document.getElementById('calendario-reagendamento');
    const dataHiddenInput = document.getElementById('reagendar_data');
    const horarioHiddenInput = document.getElementById('reagendar_horario');

    function gerarCalendarioReagendamento(ano, mes) {
        if (!calendarioContainer) return; // Garante que o container exista
        
        const hoje = new Date();
        hoje.setHours(0, 0, 0, 0);

        const diasNoMes = new Date(ano, mes + 1, 0).getDate();
        const primeiroDia = new Date(ano, mes, 1).getDay();
        const meses = ["Janeiro", "Fevereiro", "Março", "Abril", "Maio", "Junho", "Julho", "Agosto", "Setembro", "Outubro", "Novembro", "Dezembro"];

        let calendarioHTML = `
            <div class="calendario-header">
                <button type="button" class="nav-btn" id="prev-month-btn">&lt;</button>
                <span class="mes-ano">${meses[mes]} ${ano}</span>
                <button type="button" class="nav-btn" id="next-month-btn">&gt;</button>
            </div>
            <div class="dias-semana">
                <div>D</div><div>S</div><div>T</div><div>Q</div><div>Q</div><div>S</div><div>S</div>
            </div>
            <div class="dias-grid">
        `;

        for (let i = 0; i < primeiroDia; i++) {
            calendarioHTML += `<div class="dia-vazio"></div>`;
        }

        for (let dia = 1; dia <= diasNoMes; dia++) {
            const dataAtual = new Date(ano, mes, dia);
            if (dataAtual < hoje) {
                calendarioHTML += `<div class="dia passado">${dia}</div>`;
            } else {
                calendarioHTML += `<div class="dia" data-date="${ano}-${String(mes + 1).padStart(2, '0')}-${String(dia).padStart(2, '0')}">${dia}</div>`;
            }
        }

        calendarioHTML += `</div>`;
        calendarioContainer.innerHTML = calendarioHTML;

        // Adiciona eventos de clique aos dias
        calendarioContainer.querySelectorAll('.dia[data-date]').forEach(el => {
            el.addEventListener('click', () => {
                calendarioContainer.querySelectorAll('.dia.selecionado').forEach(d => d.classList.remove('selecionado'));
                el.classList.add('selecionado');
                dataHiddenInput.value = el.dataset.date;
                buscarHorariosReagendamento();
            });
        });

        // Adiciona eventos aos botões de navegação
        document.getElementById('prev-month-btn').addEventListener('click', () => {
            const novaData = new Date(ano, mes - 1);
            gerarCalendarioReagendamento(novaData.getFullYear(), novaData.getMonth());
        });
        document.getElementById('next-month-btn').addEventListener('click', () => {
            const novaData = new Date(ano, mes + 1);
            gerarCalendarioReagendamento(novaData.getFullYear(), novaData.getMonth());
        });
    }

    async function buscarHorariosReagendamento() {
        const data = dataHiddenInput.value;
        const servicosIds = document.getElementById('reagendar_servicos_ids').value;
        const numServicos = servicosIds.split(',').filter(Boolean).length;
        const horariosContainer = document.getElementById('reagendar_horarios-container');
        const erroHorario = document.getElementById('reagendar_erro_horario');

        horariosContainer.innerHTML = 'Carregando...';
        horarioHiddenInput.value = '';
        erroHorario.textContent = '';
        
        if (!data) return;

        try {
            const response = await fetch(`get_horarios.php?barbeiro_id=${barbeiroId}&data=${data}&num_servicos=${numServicos}`);
            const horariosDisponiveis = await response.json();
            
            horariosContainer.innerHTML = '';
            if (horariosDisponiveis.error || horariosDisponiveis.length === 0) {
                horariosContainer.innerHTML = `<p class="erro">Nenhum horário disponível.</p>`;
                return;
            }

            horariosDisponiveis.forEach(h => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'disponivel';
                btn.textContent = h;
                btn.addEventListener('click', () => {
                    horarioHiddenInput.value = h;
                    horariosContainer.querySelectorAll('button').forEach(b => b.classList.remove('selecionado'));
                    btn.classList.add('selecionado');
                });
                horariosContainer.appendChild(btn);
            });
        } catch (error) {
            horariosContainer.innerHTML = '<p class="erro">Erro ao carregar horários.</p>';
        }
    }
});