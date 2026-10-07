/*
 * JavaScript da aplicacao. Regra: o servidor renderiza tudo; o JS so cuida de
 * interacoes locais (abrir/fechar, trocar aba, selecionar horario).
 *
 * Usamos o build CSP do Alpine (@alpinejs/csp): ele nao usa eval/new Function,
 * entao a Content-Security-Policy continua sem 'unsafe-eval'. Consequencia:
 * nas views, as diretivas so referenciam propriedades e metodos registrados
 * aqui via Alpine.data() -- nada de JavaScript solto dentro do HTML.
 */
import Alpine from '@alpinejs/csp';
import booking from './components/booking';
import elapsed from './components/elapsed';
import { dropdown, disclosure, tabs } from './components/ui';
import { initDialogs } from './components/dialog';
import { initPrint } from './components/print';
import { initAccountMenu } from './components/account-menu';

Alpine.data('dropdown', dropdown);
Alpine.data('disclosure', disclosure);
Alpine.data('tabs', tabs);
Alpine.data('booking', booking);
Alpine.data('elapsed', elapsed);

window.Alpine = Alpine;
Alpine.start();

initDialogs();
initPrint();
initAccountMenu();
