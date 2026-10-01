// Copia do pacote lucide-static (licenca ISC) SOMENTE os icones usados,
// guardando apenas o conteudo interno do <svg>. O componente <x-icon>
// monta o <svg> com os atributos padrao (tamanho, cor, aria-hidden).
//
// Uso: node scripts/copiar-icones.mjs
// Para adicionar um icone: inclua o nome na lista e rode o script.
import { readFileSync, writeFileSync, copyFileSync } from 'node:fs';

const icones = [
    'arrow-right', 'armchair', 'badge-check', 'bell', 'calendar', 'calendar-days', 'calendar-plus', 'camera',
    'chart-column', 'check', 'chevron-down', 'chevron-left', 'chevron-right', 'circle-check',
    'circle-help', 'circle-x', 'clock', 'coffee', 'ellipsis', 'external-link', 'funnel', 'house',
    'image', 'info', 'layout-dashboard', 'list', 'loader-circle', 'log-out', 'map-pin', 'menu',
    'message-circle', 'package', 'pencil', 'phone', 'plus', 'scissors', 'search', 'settings',
    'sparkles', 'star', 'store', 'trash-2', 'triangle-alert', 'user', 'users', 'wallet', 'x',
    // Fase 3 (contas e acesso)
    'mail', 'key-round', 'shield-check', 'history',
    // Fase 4 (catalogo e equipe)
    'arrow-up', 'arrow-down', 'power', 'tag', 'eye-off', 'upload',
    // Fase 6 (atendimento, caixa e estoque)
    'play', 'undo-2', 'receipt', 'boxes', 'banknote',
];

const origem = new URL('../node_modules/lucide-static/icons/', import.meta.url);
const destino = new URL('../resources/icons/', import.meta.url);

for (const nome of icones) {
    const svg = readFileSync(new URL(`${nome}.svg`, origem), 'utf8');
    const interno = svg.replace(/<!--[\s\S]*?-->/g, '').match(/<svg[^>]*>([\s\S]*)<\/svg>/)[1]
        .split('\n').map(l => l.trim()).filter(Boolean).join('');
    writeFileSync(new URL(`${nome}.svg`, destino), interno + '\n');
}
copyFileSync(new URL('../node_modules/lucide-static/LICENSE', import.meta.url), new URL('LICENSE-lucide.txt', destino));
console.log(`${icones.length} icones copiados para resources/icons/`);
