function getFeedbackTarget(field) {
    if (!field) return null;
    if (field.id === 'barbeiro') return document.getElementById('barber-selection-grid');
    if (field.id === 'servicos' || field.id === 'combos_selecionados') return document.getElementById('servicos-container');
    if (field.id === 'horario') return document.getElementById('horarios-container');
    return field;
}

function ensureFieldError(field) {
    const target = getFeedbackTarget(field);
    if (!target) return null;

    const errorId = `${field.id || field.name}-feedback`;
    let errorEl = document.getElementById(errorId);
    if (!errorEl) {
        errorEl = document.createElement('div');
        errorEl.id = errorId;
        errorEl.className = 'field-error';
        target.insertAdjacentElement('afterend', errorEl);
    }
    field.setAttribute('aria-describedby', errorId);
    return errorEl;
}

function setFieldState(field, message) {
    const target = getFeedbackTarget(field);
    const errorEl = ensureFieldError(field);
    if (!target || !errorEl) return;

    target.classList.add('is-invalid');
    target.classList.remove('is-valid');
    errorEl.textContent = message;
    errorEl.classList.add('active');
    field.setAttribute('aria-invalid', 'true');
}

function clearFieldState(field) {
    const target = getFeedbackTarget(field);
    if (!target) return;

    target.classList.remove('is-invalid');
    if (field.value && field.type !== 'hidden') target.classList.add('is-valid');

    const errorEl = document.getElementById(`${field.id || field.name}-feedback`);
    if (errorEl) {
        errorEl.textContent = '';
        errorEl.classList.remove('active');
    }
    field.removeAttribute('aria-invalid');
}

function isValidEmail(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value).trim());
}

function isValidPhone(value) {
    return String(value).replace(/\D/g, '').length >= 10;
}

function mostrarErroWizard(mensagem, field) {
    const errorContainer = document.getElementById('wizard-error-container');
    const errorText = document.getElementById('wizard-error-text');
    if (!errorContainer || !errorText) return;

    errorText.innerText = mensagem;
    errorContainer.classList.add('active');

    if (field) {
        setTimeout(() => {
            const target = getFeedbackTarget(field);
            if (target) target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (field.type !== 'hidden' && !field.disabled) field.focus({ preventScroll: true });
        }, 50);
    } else {
        errorContainer.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    setTimeout(() => {
        errorContainer.classList.remove('active');
    }, 5000);
}

function simulateLoading(message, callback) {
    const overlay = document.getElementById('wizard-loading');
    const msgEl = document.getElementById('loading-msg');

    if (!overlay || !msgEl) {
        if (callback) callback();
        return;
    }

    msgEl.innerText = message;
    overlay.classList.add('active');

    setTimeout(() => {
        overlay.classList.remove('active');
        if (callback) callback();
    }, 450);
}

function updateStepIndicator(currentStep) {
    const icons = ['fa-user', 'fa-user-tie', 'fa-cut', 'fa-calendar-alt', 'fa-check'];
    const hero = document.getElementById('booking-hero');
    if (hero) hero.classList.toggle('is-hidden', currentStep > 1);

    for (let i = 1; i <= 5; i++) {
        const dot = document.getElementById('dot-' + i);
        const line = document.getElementById('line-' + i);
        if (!dot) continue;

        if (i < currentStep) {
            dot.className = 'step-dot completed';
            dot.innerHTML = '<i class="fa fa-check"></i>';
            if (line) line.classList.add('active');
        } else if (i === currentStep) {
            dot.className = 'step-dot active';
            dot.innerHTML = `<i class="fa ${icons[i - 1]}"></i>`;
            if (line) line.classList.remove('active');
        } else {
            dot.className = 'step-dot';
            dot.innerHTML = `<i class="fa ${icons[i - 1]}"></i>`;
            if (line) line.classList.remove('active');
        }
    }
}

function validateStep(current) {
    if (current === 1) {
        const nome = document.getElementById('nome');
        const telefone = document.getElementById('telefone');
        const email = document.getElementById('email');

        if (!nome.value.trim()) return { field: nome, message: 'Informe seu nome para continuar.' };
        if (!isValidPhone(telefone.value)) return { field: telefone, message: 'Informe um telefone valido com DDD.' };
        if (!isValidEmail(email.value)) return { field: email, message: 'Informe um e-mail valido.' };
    }

    if (current === 2) {
        const barbeiro = document.getElementById('barbeiro');
        if (!barbeiro.value) return { field: barbeiro, message: 'Escolha um profissional ou a opcao Qualquer.' };
    }

    if (current === 3) {
        const servicos = document.getElementById('servicos');
        const combos = document.getElementById('combos_selecionados');
        if (!servicos.value && !combos.value) return { field: servicos, message: 'Selecione pelo menos um servico ou combo.' };
    }

    if (current === 4) {
        const data = document.getElementById('data');
        const horario = document.getElementById('horario');
        if (!data.value) return { field: data, message: 'Escolha uma data disponivel.' };
        if (!horario.value) return { field: horario, message: 'Escolha um horario disponivel.' };
    }

    return null;
}

function nextStep(current) {
    document.getElementById('wizard-error-container')?.classList.remove('active');

    const validation = validateStep(current);
    if (validation) {
        setFieldState(validation.field, validation.message);
        mostrarErroWizard(validation.message, validation.field);
        return;
    }

    simulateLoading('Carregando o proximo passo...', () => {
        document.getElementById('step-' + current)?.classList.remove('active');
        document.getElementById('step-' + (current + 1))?.classList.add('active');
        updateStepIndicator(current + 1);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}

function prevStep(current) {
    document.getElementById('wizard-error-container')?.classList.remove('active');
    document.getElementById('step-' + current)?.classList.remove('active');
    document.getElementById('step-' + (current - 1))?.classList.add('active');
    updateStepIndicator(current - 1);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

document.addEventListener('DOMContentLoaded', function() {
    const btnRepetir = document.getElementById('btn-repetir-ultimo');
    const form = document.getElementById('form-agendamento');

    ['nome', 'telefone', 'email', 'data', 'plano_escolhido_id'].forEach(id => {
        const field = document.getElementById(id);
        if (!field) return;
        field.addEventListener('input', () => clearFieldState(field));
        field.addEventListener('change', () => clearFieldState(field));
    });

    ['barbeiro', 'servicos', 'combos_selecionados', 'horario'].forEach(id => {
        const field = document.getElementById(id);
        if (!field) return;
        field.addEventListener('change', () => clearFieldState(field));
    });

    if (form) {
        form.addEventListener('submit', function(e) {
            for (let step = 1; step <= 4; step++) {
                const validation = validateStep(step);
                if (validation) {
                    e.preventDefault();
                    setFieldState(validation.field, validation.message);
                    mostrarErroWizard(validation.message, validation.field);
                    return;
                }
            }

            const aderirPlano = document.getElementById('aderir_plano');
            const plano = document.getElementById('plano_escolhido_id');
            if (aderirPlano && aderirPlano.checked && plano && !plano.value) {
                e.preventDefault();
                setFieldState(plano, 'Selecione um plano para aderir.');
                mostrarErroWizard('Selecione um plano para aderir.', plano);
            }
        }, true);
    }

    if(btnRepetir) {
        btnRepetir.addEventListener('click', function() {
            const bId = this.getAttribute('data-barbeiro');
            const servicos = this.getAttribute('data-servicos');
            const combos = this.getAttribute('data-combos');

            if (typeof selectBarberCard === 'function') {
                const card = document.querySelector(`.barber-card-selectable[data-value="${bId}"]`);
                if (card) selectBarberCard(bId, card);
            } else {
                const barbeiroOption = document.querySelector(`.custom-option[data-value="${bId}"]`);
                if(barbeiroOption) barbeiroOption.click();
            }

            document.querySelectorAll('.servico-btn.selected').forEach(btn => {
                btn.click();
            });

            const sIdsArray = servicos ? servicos.split(',').map(s => s.trim()) : [];
            const cIdsArray = combos ? combos.split(',').map(s => s.trim()) : [];

            sIdsArray.forEach(id => {
                if(id) {
                    const servicoBtn = document.querySelector(`.servico-btn:not(.combo-btn)[data-id="${id}"]`);
                    if (servicoBtn && !servicoBtn.classList.contains('selected')) servicoBtn.click();
                }
            });

            cIdsArray.forEach(id => {
                if(id) {
                    const comboBtn = document.querySelector(`.servico-btn.combo-btn[data-id="${id}"]`);
                    if (comboBtn && !comboBtn.classList.contains('selected')) comboBtn.click();
                }
            });

            document.dispatchEvent(new Event('updateResumoNeeded'));

            simulateLoading('Restaurando seu ultimo pedido...', () => {
                document.querySelectorAll('.wizard-step').forEach(step => step.classList.remove('active'));
                document.getElementById('step-4')?.classList.add('active');
                updateStepIndicator(4);

                const dataInput = document.getElementById('data');
                if(dataInput && dataInput._flatpickr) dataInput._flatpickr.clear();

                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });
    }
});
