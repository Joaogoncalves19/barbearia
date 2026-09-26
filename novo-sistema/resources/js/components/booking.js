/*
 * PROTOTIPO do fluxo de agendamento (so para validar a experiencia visual).
 *
 * Nada aqui e regra de negocio: o fluxo real (Fase 5) pede disponibilidade e
 * orcamento ao SERVIDOR, que e a unica fonte de verdade de preco e horario.
 * Os dados de exemplo chegam num <script type="application/json">.
 */
export default function booking() {
    return {
        step: 1,
        services: [],
        pros: [],
        days: [],
        slots: [],
        selectedServices: [],
        pro: '',
        day: '',
        slot: '',
        confirmed: false,

        init() {
            const fonte = document.getElementById('booking-sample-data');
            const dados = fonte ? JSON.parse(fonte.textContent) : {};
            this.services = dados.services || [];
            this.pros = dados.pros || [];
            this.days = dados.days || [];
            this.slots = dados.slots || [];

            this.$root.addEventListener('change', (e) => {
                if (e.target.name === 'servicos[]') {
                    this.selectedServices = Array.from(
                        this.$root.querySelectorAll('input[name="servicos[]"]:checked'),
                    ).map((el) => el.value);
                }
                if (e.target.name === 'profissional') {
                    this.pro = e.target.value;
                }
            });
            this.$root.addEventListener('click', (e) => {
                const dia = e.target.closest('[data-day]');
                if (dia && !dia.disabled) {
                    this.day = dia.dataset.day;
                    this.slot = '';
                    this.markPressed('[data-day]', dia);
                    this.markPressed('[data-slot]', null);
                }
                const horario = e.target.closest('[data-slot]');
                if (horario) {
                    this.slot = horario.dataset.slot;
                    this.markPressed('[data-slot]', horario);
                }
            });
        },

        // --- Estado derivado exposto como texto para o HTML ---
        get isStep1() { return this.step === 1; },
        get isStep2() { return this.step === 2; },
        get isStep3() { return this.step === 3; },
        get isStep4() { return this.step === 4; },
        get step1State() { return this.stepState(1); },
        get step2State() { return this.stepState(2); },
        get step3State() { return this.stepState(3); },
        get step4State() { return this.stepState(4); },
        get step1Current() { return this.step === 1 ? 'step' : 'false'; },
        get step2Current() { return this.step === 2 ? 'step' : 'false'; },
        get step3Current() { return this.step === 3 ? 'step' : 'false'; },
        get step4Current() { return this.step === 4 ? 'step' : 'false'; },

        get canAdvance() {
            if (this.step === 1) return this.selectedServices.length > 0;
            if (this.step === 2) return this.pro !== '';
            if (this.step === 3) return this.day !== '' && this.slot !== '';
            return true;
        },
        get cannotAdvance() { return !this.canAdvance; },
        get isFirst() { return this.step === 1; },
        get nextLabel() { return this.step === 4 ? 'Confirmar agendamento' : 'Continuar'; },

        get totalCents() {
            return this.services
                .filter((s) => this.selectedServices.includes(s.id))
                .reduce((soma, s) => soma + s.price_cents, 0);
        },
        get totalMinutes() {
            return this.services
                .filter((s) => this.selectedServices.includes(s.id))
                .reduce((soma, s) => soma + s.minutes, 0);
        },
        get totalLabel() { return this.formatMoney(this.totalCents); },
        get durationLabel() {
            const m = this.totalMinutes;
            if (!m) return '—';
            const h = Math.floor(m / 60);
            return h ? `${h} h ${m % 60 ? (m % 60) + ' min' : ''}`.trim() : `${m} min`;
        },
        get servicesLabel() {
            const nomes = this.services.filter((s) => this.selectedServices.includes(s.id)).map((s) => s.name);
            return nomes.length ? nomes.join(', ') : 'Nenhum serviço escolhido';
        },
        get proLabel() {
            const p = this.pros.find((x) => x.id === this.pro);
            return p ? p.name : '—';
        },
        get whenLabel() {
            const d = this.days.find((x) => x.value === this.day);
            return d && this.slot ? `${d.long} às ${this.slot}` : '—';
        },
        get countLabel() {
            const n = this.selectedServices.length;
            return n === 0 ? 'Nenhum serviço' : n === 1 ? '1 serviço' : `${n} serviços`;
        },

        stepState(n) {
            return this.step > n ? 'is-done' : '';
        },
        next() {
            if (!this.canAdvance) return;
            if (this.step < 4) {
                this.step++;
                this.$nextTick(() => this.$root.querySelector('[data-step-title]:not([hidden])')?.focus());
            } else {
                this.confirmed = true;
            }
        },
        back() {
            if (this.step > 1) this.step--;
        },
        // aria-pressed atualizado direto no DOM (o build CSP do Alpine nao
        // aceita chamadas com argumento dentro do HTML).
        markPressed(seletor, ativo) {
            this.$root.querySelectorAll(seletor).forEach((el) => {
                el.setAttribute('aria-pressed', el === ativo ? 'true' : 'false');
            });
        },
        formatMoney(cents) {
            return (cents / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
        },
    };
}
