document.addEventListener('DOMContentLoaded', function () {
    const dataInput = document.getElementById('reagendar_data_flatpickr');
    const dataHiddenInput = document.getElementById('reagendar_data');
    const horarioHiddenInput = document.getElementById('reagendar_horario');
    const horariosContainer = document.getElementById('reagendar_horarios-container');

    const fp = flatpickr(dataInput, {
        locale: "pt",
        minDate: "today",
        dateFormat: "Y-m-d",
        altInput: true,
        altFormat: "d de F de Y",
        onChange: function(selectedDates, dateStr, instance) {
            dataHiddenInput.value = dateStr;
            buscarHorarios();
        },
    });

    async function buscarHorarios() {
        const data = dataHiddenInput.value;
        // const numServicos = servicosIds.split(',').filter(Boolean).length; // <-- LINHA REMOVIDA (BUG)
        
        horariosContainer.innerHTML = 'Carregando...';
        horarioHiddenInput.value = '';

        if (!data) {
            horariosContainer.innerHTML = 'Selecione uma data no calendário.';
            return;
        }

        try {
            // --- CORREÇÃO AQUI ---
            // Trocamos 'num_servicos=${numServicos}' por 'itens_selecionados=${servicosIds}'
            // 'servicosIds' é a variável global definida em reagendar_barbeiro_pc.php
            const url = `get_horarios.php?barbeiro_id=${barbeiroId}&data=${data}&itens_selecionados=${servicosIds}`;
            // --- FIM DA CORREÇÃO ---

            const response = await fetch(url);
            if (!response.ok) {
                throw new Error('Erro na requisição de horários.');
            }
            const horariosDisponiveis = await response.json();
            
            horariosContainer.innerHTML = ''; 
            if (horariosDisponiveis.error || horariosDisponiveis.length === 0) {
                horariosContainer.innerHTML = `<p class="erro">Nenhum horário disponível para esta data.</p>`;
                return;
            }

            // O backend (get_horarios.php) agora é responsável por verificar os slots
            // Então, não precisamos mais da lógica de verificação de slots aqui no JS.
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
            console.error('Erro ao buscar horários:', error);
            horariosContainer.innerHTML = '<p class="erro">Ocorreu um erro ao carregar os horários. Tente novamente.</p>';
        }
    }
});