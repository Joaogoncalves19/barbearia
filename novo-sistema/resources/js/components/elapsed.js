/*
 * Tempo decorrido de um atendimento (area do profissional):
 *   <span x-data="elapsed" data-since="2026-10-07T14:05:00-03:00" x-text="label">23 min</span>
 * O servidor ja escreve o valor; aqui so atualiza a cada 30 s, sem recarregar.
 */
export default function elapsed() {
    return {
        label: '',
        timer: null,
        init() {
            this.label = this.$el.textContent.trim();
            const inicio = Date.parse(this.$el.dataset.since || '');
            if (Number.isNaN(inicio)) return;
            const atualizar = () => {
                const minutos = Math.max(0, Math.floor((Date.now() - inicio) / 60000));
                this.label = minutos < 60 ? `${minutos} min` : `${Math.floor(minutos / 60)}h${String(minutos % 60).padStart(2, '0')}`;
            };
            atualizar();
            this.timer = setInterval(atualizar, 30000);
        },
        destroy() {
            clearInterval(this.timer);
        },
    };
}
