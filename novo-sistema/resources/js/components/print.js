/*
 * Botao de imprimir dos comprovantes: <button data-print>Imprimir</button>.
 * Sem JavaScript no HTML (CSP): o clique e tratado aqui.
 */
export function initPrint() {
    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-print]')) {
            window.print();
        }
    });
}
