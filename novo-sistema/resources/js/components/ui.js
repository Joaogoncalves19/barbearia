/*
 * Componentes de interface pequenos e acessiveis.
 * Atributos ARIA sao expostos como strings ('true'/'false') por getters,
 * porque o build CSP do Alpine nao aceita expressoes no HTML.
 */

/** Menu suspenso: abre/fecha, fecha com Esc e ao clicar fora, foco no 1o item. */
export function dropdown() {
    return {
        open: false,
        get expanded() {
            return this.open ? 'true' : 'false';
        },
        toggle() {
            this.open = !this.open;
            if (this.open) {
                this.$nextTick(() => this.$refs.menu?.querySelector('a, button')?.focus());
            }
        },
        close() {
            if (!this.open) return;
            this.open = false;
            this.$refs.trigger?.focus();
        },
        closeSilently() {
            this.open = false;
        },
    };
}

/** Mostrar/ocultar (menu movel do site, barra lateral do painel). */
export function disclosure() {
    return {
        open: false,
        get expanded() {
            return this.open ? 'true' : 'false';
        },
        get panelClass() {
            return this.open ? 'is-open' : '';
        },
        toggle() {
            this.open = !this.open;
        },
        close() {
            this.open = false;
        },
    };
}

/**
 * Abas com o padrao ARIA: setas esquerda/direita, Home/End, so a aba ativa
 * entra na ordem de tabulacao. Paineis e abas ligados por id.
 */
export function tabs() {
    return {
        active: 0,
        init() {
            this.tabEls().forEach((tab, i) => {
                tab.addEventListener('click', () => this.select(i));
                tab.addEventListener('keydown', (e) => this.onKey(e, i));
            });
            this.render();
        },
        tabEls() {
            return Array.from(this.$root.querySelectorAll('[role="tab"]'));
        },
        panelEls() {
            return Array.from(this.$root.querySelectorAll('[role="tabpanel"]'));
        },
        select(i) {
            this.active = i;
            this.render();
        },
        onKey(e, i) {
            const total = this.tabEls().length;
            const destinos = { ArrowRight: (i + 1) % total, ArrowLeft: (i - 1 + total) % total, Home: 0, End: total - 1 };
            if (!(e.key in destinos)) return;
            e.preventDefault();
            this.select(destinos[e.key]);
            this.tabEls()[this.active].focus();
        },
        render() {
            this.tabEls().forEach((tab, i) => {
                tab.setAttribute('aria-selected', i === this.active ? 'true' : 'false');
                tab.tabIndex = i === this.active ? 0 : -1;
            });
            this.panelEls().forEach((panel, i) => {
                panel.hidden = i !== this.active;
            });
        },
    };
}
