(function () {
    'use strict';

    // Estado compartilhado entre o modal (persistente) e a grade da aba.
    var preferredTime = '';
    var loadManualTimes = function () {};

    // ============================================================
    // MODAL: AGENDAMENTO MANUAL (persistente em admin_modals.php)
    // Vincula os handlers uma única vez, independentemente de qual
    // aba estiver ativa — resolve o caso em que a aba é carregada
    // via AJAX depois do load inicial.
    // ============================================================
    function initManualModal() {
        var manualModal = document.getElementById('modal-agendamento-manual');
        if (!manualModal || manualModal.dataset.bound === '1') return;
        manualModal.dataset.bound = '1';

        var manualForm = manualModal.querySelector('form');
        var manualBarber = document.getElementById('manual_barbeiro');
        var manualDate = document.getElementById('manual_data');
        var manualServices = document.getElementById('manual_servicos');
        var manualTime = document.getElementById('manual_horario');
        var manualTimes = document.getElementById('manual_horarios-container');
        var serviceButtons = Array.from(manualModal.querySelectorAll('.servico-btn-modal'));
        var clientSearch = document.getElementById('busca_cliente_input');
        var clientList = document.getElementById('lista_clientes_dropdown');
        var clientId = document.getElementById('manual_cliente_id');
        var clientName = document.getElementById('manual_nome');
        var clientPhone = document.getElementById('manual_telefone');

        if (manualDate) manualDate.min = new Date().toISOString().slice(0, 10);

        // ---- Busca de cliente cadastrado ----
        if (clientSearch && clientList) {
            var clientOptions = Array.from(clientList.querySelectorAll('li'));

            function filterClients() {
                var query = clientSearch.value.trim().toLocaleLowerCase('pt-BR');
                var visible = 0;
                clientOptions.forEach(function (option, index) {
                    var content = ((option.dataset.nome || '') + ' ' + (option.dataset.telefone || '')).toLocaleLowerCase('pt-BR');
                    var show = index === 0 || query === '' || content.includes(query);
                    option.style.display = show ? '' : 'none';
                    if (show) visible++;
                });
                clientList.style.display = visible ? 'block' : 'none';
            }

            clientSearch.addEventListener('focus', filterClients);
            clientSearch.addEventListener('input', filterClients);
            clientOptions.forEach(function (option) {
                option.addEventListener('click', function () {
                    var selectedId = option.dataset.id || '';
                    clientId.value = selectedId;
                    clientName.value = option.dataset.nome || '';
                    clientPhone.value = option.dataset.telefone || '';
                    clientSearch.value = selectedId ? option.dataset.nome : '';
                    clientList.style.display = 'none';
                });
            });
            document.addEventListener('click', function (event) {
                if (!event.target.closest('.custom-dropdown-container')) {
                    clientList.style.display = 'none';
                }
            });
        }

        function selectedServices() {
            return serviceButtons.filter(function (button) {
                return button.classList.contains('selected');
            }).map(function (button) {
                return button.dataset.id;
            });
        }

        // ---- Carrega os horários (mostrando os ocupados) ----
        loadManualTimes = async function () {
            var services = selectedServices();
            if (manualServices) manualServices.value = services.join(',');
            if (!manualBarber || !manualDate || !manualTimes || !manualTime
                || !manualBarber.value || !manualDate.value || !services.length) {
                if (manualTimes) {
                    manualTimes.style.fontStyle = 'italic';
                    manualTimes.innerHTML = '<span class="ag-times-hint">Selecione profissional, data e serviços para ver os horários.</span>';
                }
                if (manualTime) manualTime.value = '';
                return;
            }

            manualTime.value = '';
            manualTimes.style.fontStyle = 'normal';
            manualTimes.innerHTML = '<span class="ag-times-hint"><i class="fa fa-spinner fa-spin"></i> Carregando horários...</span>';
            var params = new URLSearchParams({
                barbeiro_id: manualBarber.value,
                data: manualDate.value,
                itens_selecionados: services.join(','),
                mode: 'admin_manual_completo',
                admin_mode: '1'
            });

            try {
                var response = await fetch('get_horarios.php?' + params.toString(), { credentials: 'same-origin' });
                var times = await response.json();
                manualTimes.innerHTML = '';

                if (!Array.isArray(times) || !times.length) {
                    manualTimes.style.fontStyle = 'italic';
                    manualTimes.innerHTML = '<span class="ag-times-hint">Nenhum horário para este dia (profissional sem expediente, folga ou férias).</span>';
                    return;
                }

                var livres = 0;
                var grid = document.createElement('div');
                grid.className = 'ag-times-grid';

                times.forEach(function (time) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = time.horario;
                    if (time.ocupado) {
                        button.className = 'horario-btn ocupado';
                        button.disabled = true;
                        button.title = 'Horário indisponível (ocupado, intervalo ou fora do expediente)';
                    } else {
                        livres++;
                        button.className = 'horario-btn disponivel';
                        button.addEventListener('click', function () {
                            grid.querySelectorAll('.horario-btn').forEach(function (item) {
                                item.classList.remove('selecionado');
                            });
                            button.classList.add('selecionado');
                            manualTime.value = time.horario;
                        });
                    }
                    grid.appendChild(button);

                    if (!time.ocupado && preferredTime === time.horario) {
                        window.setTimeout(function () { button.click(); }, 0);
                        preferredTime = '';
                    }
                });

                // Legenda
                var legend = document.createElement('div');
                legend.className = 'ag-times-legend';
                legend.innerHTML = '<span><i class="dot dot-livre"></i> Disponível</span><span><i class="dot dot-ocupado"></i> Ocupado</span>';

                manualTimes.appendChild(grid);
                manualTimes.appendChild(legend);

                if (livres === 0) {
                    var aviso = document.createElement('div');
                    aviso.className = 'ag-times-hint';
                    aviso.style.marginTop = '10px';
                    aviso.innerHTML = '<i class="fa fa-triangle-exclamation"></i> Todos os horários deste dia estão ocupados.';
                    manualTimes.appendChild(aviso);
                }
            } catch (error) {
                manualTimes.style.fontStyle = 'italic';
                manualTimes.innerHTML = '<span class="ag-times-hint">Não foi possível carregar os horários.</span>';
            }
        };

        serviceButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                button.classList.toggle('selected');
                loadManualTimes();
            });
        });
        if (manualBarber) manualBarber.addEventListener('change', loadManualTimes);
        if (manualDate) manualDate.addEventListener('change', loadManualTimes);

        if (manualForm) {
            manualForm.addEventListener('submit', function (event) {
                var message = '';
                if (!manualServices.value) message = 'Selecione pelo menos um serviço.';
                else if (!manualBarber.value) message = 'Selecione o profissional.';
                else if (!manualDate.value) message = 'Escolha a data.';
                else if (!manualTime.value) message = 'Escolha um horário disponível.';
                if (!message) return;
                event.preventDefault();
                if (window.Swal) {
                    Swal.fire({ icon: 'warning', title: 'Revise o agendamento', text: message });
                } else {
                    window.alert(message);
                }
            });
        }
    }

    // ============================================================
    // MODAL: REAGENDAMENTO (parte persistente — handler da data)
    // ============================================================
    function initRescheduleModal() {
        var rescheduleModal = document.getElementById('modal-reagendamento');
        if (!rescheduleModal || rescheduleModal.dataset.bound === '1') return;
        rescheduleModal.dataset.bound = '1';

        var rescheduleDate = document.getElementById('reagendar_data');
        var rescheduleTime = document.getElementById('reagendar_horario');
        var rescheduleTimes = document.getElementById('reagendar_horarios-container');

        if (rescheduleDate) {
            rescheduleDate.min = new Date().toISOString().slice(0, 10);
            rescheduleDate.addEventListener('change', async function () {
                var barberId = document.getElementById('reagendar_barbeiro_id').value;
                var servicesIds = document.getElementById('reagendar_servicos_ids').value;
                if (!barberId || !rescheduleDate.value) return;
                rescheduleTimes.innerHTML = '<span><i class="fa fa-spinner fa-spin"></i> Carregando...</span>';
                var params = new URLSearchParams({
                    barbeiro_id: barberId,
                    data: rescheduleDate.value,
                    itens_selecionados: servicesIds,
                    admin_mode: '1'
                });
                try {
                    var response = await fetch('get_horarios.php?' + params.toString(), { credentials: 'same-origin' });
                    var times = await response.json();
                    rescheduleTimes.innerHTML = '';
                    if (!Array.isArray(times) || !times.length) {
                        rescheduleTimes.textContent = 'Nenhum horário disponível.';
                        return;
                    }
                    times.forEach(function (entry) {
                        var value = typeof entry === 'string' ? entry : entry.horario;
                        var button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'horario-btn';
                        button.textContent = value;
                        button.addEventListener('click', function () {
                            rescheduleTimes.querySelectorAll('.horario-btn').forEach(function (item) {
                                item.classList.remove('selected');
                            });
                            button.classList.add('selected');
                            rescheduleTime.value = value;
                        });
                        rescheduleTimes.appendChild(button);
                    });
                } catch (error) {
                    rescheduleTimes.textContent = 'Não foi possível carregar os horários.';
                }
            });
        }
    }

    // ============================================================
    // CONTEÚDO DA ABA (.agenda-premium) — recriado a cada carga.
    // ============================================================
    function initAgendaRoot() {
        var root = document.querySelector('.agenda-premium');
        if (!root || root.dataset.bound === '1') return;
        root.dataset.bound = '1';

        var csrf = root.dataset.csrf || '';
        var returnDate = root.dataset.date || '';

        root.addEventListener('click', function (event) {
            // Abrir o modal manual: limpa a seleção anterior
            var manualTrigger = event.target.closest('[data-modal-target="#modal-agendamento-manual"]');
            if (manualTrigger) {
                document.querySelectorAll('#modal-agendamento-manual .servico-btn-modal').forEach(function (button) {
                    button.classList.remove('selected');
                });
                var servicesInput = document.getElementById('manual_servicos');
                var timeInput = document.getElementById('manual_horario');
                if (servicesInput) servicesInput.value = '';
                if (timeInput) timeInput.value = '';
                var timesBox = document.getElementById('manual_horarios-container');
                if (timesBox) {
                    timesBox.style.fontStyle = 'italic';
                    timesBox.innerHTML = '<span class="ag-times-hint">Selecione profissional, data e serviços para ver os horários.</span>';
                }
            }

            // "Usar este horário" a partir de um slot vago da grade
            var slot = event.target.closest('.ag-use-slot');
            if (slot) {
                preferredTime = slot.dataset.time || '';
                window.setTimeout(function () {
                    var dateInput = document.getElementById('manual_data');
                    var barberInput = document.getElementById('manual_barbeiro');
                    if (dateInput && slot.dataset.date) dateInput.value = slot.dataset.date;
                    if (barberInput && slot.dataset.barber) barberInput.value = slot.dataset.barber;
                    loadManualTimes();
                }, 10);
            }

            var modalTrigger = event.target.closest('[data-ag-modal]');
            if (modalTrigger) {
                event.preventDefault();
                var modal = document.getElementById(modalTrigger.dataset.agModal);
                if (modal) modal.classList.add('is-open');
            }
        });

        root.querySelectorAll('.ag-modal-backdrop').forEach(function (modal) {
            modal.addEventListener('click', function (event) {
                // Não fecha ao clicar no fundo (evita perder dados por clique acidental); só pelo botão de fechar.
                if (event.target.closest('[data-close-ag-modal]')) {
                    modal.classList.remove('is-open');
                }
            });
        });

        // ---- Seleção em lote / impressão ----
        var checkboxes = Array.from(root.querySelectorAll('.ag-row-check'));
        var batchbar = document.getElementById('agenda-batchbar');
        var selectedCount = document.getElementById('agenda-selected-count');

        function updateBatch() {
            var selected = checkboxes.filter(function (checkbox) { return checkbox.checked; });
            if (batchbar) batchbar.classList.toggle('is-visible', selected.length > 0);
            if (selectedCount) selectedCount.textContent = selected.length;
            root.querySelectorAll('[data-ag-row]').forEach(function (row) {
                var checkbox = row.querySelector('.ag-row-check');
                row.classList.toggle('is-print-selected', Boolean(checkbox && checkbox.checked));
            });
        }

        checkboxes.forEach(function (checkbox) { checkbox.addEventListener('change', updateBatch); });

        var selectAll = document.getElementById('agenda-select-all');
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                checkboxes.forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
                updateBatch();
            });
        }

        var clearSelection = document.getElementById('agenda-clear-selection');
        if (clearSelection) {
            clearSelection.addEventListener('click', function () {
                checkboxes.forEach(function (checkbox) { checkbox.checked = false; });
                if (selectAll) selectAll.checked = false;
                updateBatch();
            });
        }

        var batchForm = document.getElementById('agenda-batch-form');
        if (batchForm) {
            batchForm.addEventListener('submit', function (event) {
                var action = batchForm.querySelector('[name="acao_lote"]').value;
                if (action === 'imprimir') {
                    event.preventDefault();
                    window.print();
                    return;
                }
                if (!action || !window.confirm('Aplicar esta ação aos horários selecionados?')) {
                    event.preventDefault();
                }
            });
        }

        root.querySelectorAll('.ag-whatsapp-confirm').forEach(function (link) {
            link.addEventListener('click', function () {
                var formData = new FormData();
                formData.append('action', 'agenda_marcar_confirmacao');
                formData.append('csrf_token', csrf);
                formData.append('agendamento_id', link.dataset.confirmId || '');
                formData.append('confirmacao_status', 'enviado');
                formData.append('return_date', returnDate);
                fetch('admin.php', { method: 'POST', body: formData, credentials: 'same-origin' }).catch(function () {});
            });
        });

        // ---- Botões de reagendar (elementos da aba) ----
        root.querySelectorAll('.reagendar-btn').forEach(function (button) {
            button.addEventListener('click', function () {
                var idInput = document.getElementById('reagendar_agendamento_id');
                var barberInput = document.getElementById('reagendar_barbeiro_id');
                var servInput = document.getElementById('reagendar_servicos_ids');
                var dateInput = document.getElementById('reagendar_data');
                var timeInput = document.getElementById('reagendar_horario');
                var timesBox = document.getElementById('reagendar_horarios-container');
                if (idInput) idInput.value = button.dataset.id || '';
                if (barberInput) barberInput.value = button.dataset.barbeiroId || '';
                if (servInput) servInput.value = button.dataset.servicosIds || '';
                if (dateInput) dateInput.value = '';
                if (timeInput) timeInput.value = '';
                if (timesBox) timesBox.textContent = 'Selecione uma nova data.';
            });
        });
    }

    function initAgenda() {
        initManualModal();
        initRescheduleModal();
        initAgendaRoot();
    }

    document.addEventListener('DOMContentLoaded', initAgenda);
    // Permite ao carregador de abas (AJAX) reinicializar ao entrar em Agendamentos.
    window.__reinitAgenda = initAgenda;

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        document.querySelectorAll('.ag-modal-backdrop.is-open').forEach(function (modal) {
            modal.classList.remove('is-open');
        });
    });
})();
