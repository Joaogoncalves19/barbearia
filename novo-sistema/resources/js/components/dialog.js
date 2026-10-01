/*
 * Modais com o <dialog> nativo (foco preso, Esc e camada superior de graca).
 *
 *   <button data-dialog-open="id-do-dialog">Abrir</button>
 *   <dialog id="id-do-dialog">... <button data-dialog-close>Fechar</button></dialog>
 *
 * Ao fechar, o foco volta para o botao que abriu. Clicar no fundo fecha.
 * <dialog data-dialog-autoopen>: abre ao carregar a pagina (o envio do
 * formulario do modal voltou com erro de validacao).
 */
export function initDialogs() {
    let origem = null;

    document.addEventListener('click', (event) => {
        const abrir = event.target.closest('[data-dialog-open]');
        if (abrir) {
            const dialog = document.getElementById(abrir.dataset.dialogOpen);
            if (dialog && typeof dialog.showModal === 'function') {
                origem = abrir;
                dialog.showModal();
            }
            return;
        }

        const fechar = event.target.closest('[data-dialog-close]');
        if (fechar) {
            fechar.closest('dialog')?.close();
            return;
        }

        // Clique no fundo (o proprio <dialog>, fora do painel) fecha.
        if (event.target instanceof HTMLDialogElement && event.target.open) {
            event.target.close();
        }
    });

    document.addEventListener(
        'close',
        (event) => {
            if (event.target instanceof HTMLDialogElement && origem) {
                origem.focus();
                origem = null;
            }
        },
        true,
    );

    document.querySelectorAll('dialog[data-dialog-autoopen]').forEach((dialog) => {
        if (typeof dialog.showModal === 'function' && !dialog.open) {
            origem = document.querySelector(`[data-dialog-open="${dialog.id}"]`);
            dialog.showModal();
        }
    });
}
