# Design System (Fase 1)

Catálogo vivo: **`/design-system`** (com `BARBEARIA_PROTOTYPES=true`). Identidade e
justificativas das cores, fontes e escalas: [identidade-visual.md](identidade-visual.md).

## 1. Estrutura

```text
resources/css/
├── app.css              núcleo compartilhado (importa os arquivos abaixo)
├── fonts.css            fontes locais (@fontsource)
├── tokens.css           primitivos (--p-*), semânticos (--c-*), escalas
├── base.css             reset, tipografia, foco visível, utilitários
├── components/          button · forms · surfaces · feedback · overlays · data · navigation · brand
├── site.css → areas/site.css     experiência de marca (site público)
└── panel.css → areas/panel.css   experiência de produtividade (painel)

resources/views/components/
├── ui/*.blade.php       componentes (<x-ui.nome>)
├── layouts/             document · site · panel · auth · error
└── (App\View\Components\Icon) → <x-icon>
resources/js/
├── app.js               Alpine (build CSP) + registros
└── components/          ui.js (dropdown, disclosure, tabs) · dialog.js · booking.js (protótipo)
```

Tamanho atual (produção, gzip): CSS núcleo **7 KB**, site **2,5 KB**, painel **2 KB**,
JS **24,5 KB** (Alpine). Fontes em arquivos separados por subconjunto de caracteres
(o navegador baixa só o necessário).

## 2. Regras de uso (obrigatórias)

1. **Use os componentes `x-ui.*`.** Não crie variação local de botão, campo, card etc. Se faltar
   algo, crie um componente novo, pequeno e genérico, e documente aqui.
2. **Só tokens.** Nada de cor, tamanho ou espaço solto no CSS: use `--c-*`, `--fs-*`,
   `--space-*`, `--radius-*`. Primitivos (`--p-*`) só dentro de `tokens.css`.
3. **Sem `style=""` e sem `onclick=""`.** A CSP bloqueia os dois. Posicionamento dinâmico
   (ex.: agenda) sai num `<style nonce="{{ Vite::cspNonce() }}">` gerado pelo servidor.
4. **JavaScript só para interação local.** Preço, disponibilidade e permissão vêm do servidor.
   No HTML, diretivas Alpine referenciam só propriedades e métodos registrados em
   `Alpine.data()` (o build CSP não avalia expressões).
5. **Direção visual no `<html>`.** `data-direcao` (a|b) e `data-superficie` (clara|escura) ficam
   no `<html>`. Um trecho pode trocar de superfície com `data-superficie` (ex.: seção clara
   dentro do site escuro). Para mudar a direção num trecho, use os dois atributos.
6. **Site ≠ painel.** O site não usa cartões de indicador, pílulas de menu nem layout de painel;
   o painel não usa fonte de título, fotografia decorativa nem hero.

## 3. Componentes

Todos têm foco visível, alvo de toque ≥ 44 px e funcionam nas superfícies clara e escura.

| Componente | Uso | Parâmetros principais | Estados |
|---|---|---|---|
| `<x-ui.button>` | Ações e links com aparência de botão | `variant` primary·accent·secondary·ghost·danger · `size` sm·md·lg · `href` · `icon` · `icon-right` · `loading` · `block` | hover, foco, ativo, `disabled`, carregando (`aria-busy`, bloqueia clique duplo) |
| `.link-arrow` / `<a>` | Link de texto (com ou sem seta) | — | hover (sublinhado mais grosso), foco |
| `<x-ui.input>` | Campo de texto | `name` · `label` · `type` · `hint` · `error` · `optional` | foco, erro (`aria-invalid` + mensagem ligada por `aria-describedby`), desabilitado. Senha nunca é repreenchida |
| `<x-ui.select>` | Seleção | `options` (valor ⇒ rótulo) · `placeholder` | idem input |
| `<x-ui.textarea>` | Texto longo | `rows` | idem input |
| `<x-ui.checkbox>` / `<x-ui.radio>` | Escolhas | `label` · `hint` · `checked` | marcado, foco, desabilitado |
| `<x-ui.switch>` | Liga/desliga (anuncia "ativado") | `label` · `checked` | ligado, foco, desabilitado |
| `.option-card` | Cartão selecionável (agendamento) | radio/checkbox dentro de `<label>` | hover, marcado (acento), foco, desabilitado |
| `<x-ui.card>` | Superfície com borda | `title` · `variant` flush·sunken · slot `actions` | `.card--link`: card inteiro clicável com foco no card |
| `<x-ui.badge>` | Status curto (sempre com texto) | `variant` neutral·success·warning·danger·info·accent·**sample** | — |
| `<x-ui.alert>` | Mensagem em linha | `variant` info·success·warning·danger · `title` | erro usa `role="alert"`; demais `role="status"` |
| `<x-ui.modal>` | Diálogo (`<dialog>` nativo) | `id` · `title` · slot `footer` · abrir com `data-dialog-open="id"` | foco preso, Esc fecha, clique fora fecha, foco volta ao botão; no celular vira folha na base |
| `<x-ui.confirm>` | Confirmação de ação destrutiva | `action` (POST + CSRF) · `confirm-label` · `danger` | sem `action` = modo demonstração |
| `<x-ui.dropdown>` | Menu de ações | slot `trigger` · itens `.dropdown__item` | aberto/fechado, Esc devolve o foco, clique fora fecha |
| `<x-ui.table>` | Tabela responsiva | `caption` (obrigatório) · `stacked` (vira lista no celular; `td` com `data-label`) | hover de linha |
| Paginação | `{{ $paginator->links() }}` (view padrão) | — | página atual (`aria-current`), anterior/próxima desabilitadas |
| `<x-ui.avatar>` | Foto ou iniciais | `name` · `src` · `size` sm·md·lg·xl | — |
| `<x-ui.breadcrumbs>` | Onde estou | `items` | última = página atual |
| `<x-ui.tabs>` | Abas (padrão ARIA) | `tabs` (id ⇒ rótulo) + slots com o mesmo id | setas ←/→, Home, End; só a aba ativa entra no Tab |
| `.segmented` | Alternância curta (Dia · Lista) | itens com `aria-current`/`aria-pressed` | ativo |
| `.steps` | Etapas (agendamento) | itens com `is-done` / `aria-current="step"` | no celular mostra só o rótulo da etapa atual |
| `.nav-link` | Item de menu (painel/site) | `aria-current="page"` | hover, ativo (barra de acento) |
| `<x-ui.loading>` | Carregando (spinner **com texto**) | `label` | — |
| `<x-ui.skeleton>` | Conteúdo "fantasma" | `lines` | animação desligada com movimento reduzido |
| `<x-ui.empty-state>` | Estado vazio com próxima ação | `title` · `icon` · slot `action` | — |
| `<x-ui.error-state>` | Erro com como tentar de novo | `title` · slot `action` | `role="alert"` |
| `<x-ui.photo>` | Foto com proporção fixa ou **marcador identificado** | `src` · `alt` · `ratio` portrait·square·landscape·wide · `placeholder` · `eager` | sem `src` mostra qual foto real deve entrar |
| `<x-icon>` | Ícone SVG local | `name` · `label` (torna acessível) | decorativo por padrão |

Layouts: `<x-layouts.site>` (cabeçalho fixo, menu móvel, barra inferior "Agendar/WhatsApp"
no celular), `<x-layouts.panel>` (menu lateral que vira gaveta, barra superior com busca e
menu do usuário), `<x-layouts.auth>` e `<x-layouts.error>`.

## 4. Acessibilidade (parte do componente, não correção posterior)

- Contraste AA calculado para todos os pares ([identidade-visual.md](identidade-visual.md#contraste-verificado-wcag-22)).
- Foco visível de 3 px em todo elemento interativo (`:focus-visible`), com cor de alto contraste.
- "Pular para o conteúdo" em todas as páginas.
- Todo campo tem `<label>` visível; erro com texto, ícone e borda, ligado ao campo.
- HTML semântico: `header/nav/main/section/footer`, títulos em ordem, listas reais, `caption` em tabela.
- Ícones decorativos `aria-hidden`; botões só com ícone têm nome acessível.
- Status nunca só por cor (badge com texto; legenda na agenda).
- Alvos de toque ≥ 44 px; campos com 16 px (sem zoom no iOS).
- `prefers-reduced-motion` desliga animações e rolagem suave.
- Verificação automática: **axe-core** nas 8 telas × 2 direções × celular/desktop, falhando o
  teste em violação "serious" ou "critical".

## 5. Mobile first

- Todos os estilos partem do celular; `min-width` adiciona colunas (48 rem ≈ 768 px e
  64 rem ≈ 1024 px).
- Site: cabeçalho compacto com menu, **barra inferior fixa com "Agendar" e WhatsApp**, equipe
  em carrossel horizontal, menu de serviços em uma coluna.
- Agendamento: resumo vira barra fixa na base com total e "Continuar".
- Painel: menu lateral vira gaveta; indicadores em 2 colunas; agenda rola dentro do próprio
  quadro e tem **visão em lista** (tabela que vira cartões).
- Teste automático de **rolagem horizontal** (nenhuma página pode rolar de lado) no celular e no desktop.

## 6. Desempenho

- Páginas renderizadas no servidor; JS só para interação.
- CSS enxuto, sem framework de UI. Núcleo + um arquivo por área.
- Fontes locais variáveis, com subconjuntos e `font-display: swap`.
- Ícones inline só dos usados (sem fonte de ícones de 70 KB+).
- Imagens: `x-ui.photo` com proporção fixa (sem salto de layout) e `loading="lazy"` fora da
  primeira dobra. Na Fase 11: WebP/AVIF e `srcset` gerados no upload; mapa carregado só ao clicar.
- Assets versionados pelo Vite (cache longo seguro).

## 7. Como criar um componente novo

1. Verifique se um existente resolve (com `variant` ou slot).
2. CSS em `resources/css/components/<grupo>.css`, **só com tokens**, cobrindo hover, foco,
   ativo, desabilitado, carregando e erro quando aplicável.
3. Blade em `resources/views/components/ui/<nome>.blade.php`, com comentário de uso no topo.
4. Interação? `Alpine.data()` em `resources/js/components/`, com ARIA correto.
5. Adicione ao `/design-system` e a esta tabela.
6. Rode `npm run test:e2e` (axe + CSP + responsividade).

## 8. Telas de referência

| Tela | URL | Objetivo |
|---|---|---|
| 1. Home pública | `/prototipos/home` | Identidade de marca: hero, serviços com preço, equipe, sobre + galeria, avaliações, localização, CTA |
| 2. Serviços | `/prototipos/servicos` | Menu completo por categoria, preço e duração, agendar a partir do serviço |
| 3. Agendamento | `/prototipos/agendamento` | 4 etapas (serviços → profissional → data/hora → confirmação), sem cadastro prévio, resumo sempre visível |
| 4. Painel "Hoje" | `/prototipos/painel` | 4 indicadores do dia, agenda de hoje com próximo cliente, pendências, caixa |
| 5. Agenda | `/prototipos/agenda` (`?visao=lista`) | Colunas por profissional em grade de 15 min, status com legenda, lista para celular/recepção |
| Design System | `/design-system` | Tokens e todos os componentes com estados |

Todas mostram a faixa "Protótipo de referência visual", marcam dados como **exemplo**, têm
`noindex` e respondem **404 em produção**. Capturas em `img/fase1/`.
