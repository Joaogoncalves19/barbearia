document.addEventListener('DOMContentLoaded', function () {
    const dataInput = document.getElementById('reagendar_data_input');
    const horarioHiddenInput = document.getElementById('reagendar_horario');
    const horariosContainer = document.getElementById('reagendar_horarios-container');

    // Adiciona um "ouvinte" ao campo de data. Quando a data for alterada, a função buscarHorarios é chamada.
    if (dataInput) {
        dataInput.addEventListener('change', buscarHorarios);
    }

    async function buscarHorarios() {
        const data = dataInput.value;
        // As variáveis 'barbeiroId' e 'servicosIds' vêm do script inline no arquivo PHP.
        // const numServicos = servicosIds.split(',').filter(Boolean).length; // <-- LINHA REMOVIDA (BUG)
        
        horariosContainer.innerHTML = 'Carregando...';
        horarioHiddenInput.value = '';

        if (!data) {
            horariosContainer.innerHTML = 'Selecione uma data.';
            return;
        }

        try {
            // --- CORREÇÃO AQUI ---
            // Trocamos 'num_servicos=${numServicos}' por 'itens_selecionados=${servicosIds}'
            // 'servicosIds' é a variável global definida em reagendar_barbeiro.php
            const url = `get_horarios.php?barbeiro_id=${barbeiroId}&data=${data}&itens_selecionados=${servicosIds}`;
            // --- FIM DA CORREÇÃO ---

            const response = await fetch(url);
            const horariosDisponiveis = await response.json();
            
            horariosContainer.innerHTML = ''; // Limpa a mensagem de "Carregando..."
            if (horariosDisponiveis.error || horariosDisponiveis.length === 0) {
                horariosContainer.innerHTML = `<p class="erro">Nenhum horário disponível para esta data.</p>`;
                return;
            }

            // A verificação de slots consecutivos (bugada) foi REMOVIDA.
            // O backend 'get_horarios.php' agora faz isso corretamente.
            horariosDisponiveis.forEach(h => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'disponivel';
                btn.textContent = h;
                btn.addEventListener('click', () => {
                    horarioHiddenInput.value = h;
                    // Remove a seleção de outros botões e marca o clicado
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