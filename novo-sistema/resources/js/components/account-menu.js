/*
 * Area do cliente (Fase 12): no celular o menu da conta e uma faixa que rola
 * de lado (so ela, nunca a pagina). Ao abrir uma tela, a faixa ja mostra o
 * item atual, sem mexer na rolagem da pagina.
 */
export function initAccountMenu() {
    const faixa = document.querySelector('.account-menu ul');
    const atual = faixa?.querySelector('[aria-current="page"]');
    if (!faixa || !atual || faixa.scrollWidth <= faixa.clientWidth) {
        return;
    }
    const item = atual.closest('li') ?? atual;
    faixa.scrollLeft = Math.max(0, item.offsetLeft - (faixa.clientWidth - item.offsetWidth) / 2);
}
