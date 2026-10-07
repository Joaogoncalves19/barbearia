# Temas visuais

> Etapa de refinamento visual e temas (depois do redesign "Ofício", antes da Fase 13).
> Relatório da etapa: [relatorio-refinamento-visual.md](relatorio-refinamento-visual.md).
> Linguagem base: [redesign-visual.md](redesign-visual.md).

**Um produto, oito identidades.** Cada barbearia escolhe um tema predefinido em
**Configurações → Aparência**. O tema muda cores, letras, cantos e o detalhe gráfico do site, da conta
do cliente e do painel de toda a equipe. A estrutura das telas, os textos, os preços e as regras não mudam.

## 1. Arquitetura

### Onde fica cada coisa

| Peça | Arquivo | Papel |
|---|---|---|
| Catálogo de temas | `app/Modules/SiteContent/Support/Theme.php` | Nome, paleta resumida, descrição, amostras de cor da tela Aparência, superfície (clara/escura) de cada parte da interface |
| Escolha da barbearia | `app/Modules/SiteContent/Support/Appearance.php` | Lê e grava o tema na tabela `settings` (chave `appearance`), com auditoria |
| Tela | `AppearanceController`, `resources/views/panel/appearance/*` | Escolha com miniaturas, prévia, gravação |
| Tokens base (= Ofício) | `resources/css/tokens.css` | Primitivos, semânticos por superfície, escalas |
| Um arquivo por tema | `resources/css/themes/<chave>.css` | Só troca tokens |
| Lista de temas | `resources/css/themes/index.css` | Importa os 8 arquivos (carregado em `app.css`, logo depois dos tokens) |

### Como o tema chega à página

O layout base (`components/layouts/document.blade.php`) escreve `data-tema="<chave>"` e
`data-superficie="clara|escura"` no `<html>`.

- **Superfície por papel.** As telas não pedem "escura" ou "clara". Pedem uma **parte da interface**:
  `site`, `band` (a faixa "A casa" do início), `panel`, `sidebar` e `auth` (o painel da marca nas
  telas de acesso). O tema traduz cada parte. Por isso o Minimal tem o site claro, o Black Label tem o
  painel escuro e o Old School tem o site claro com a faixa escura, sem nenhuma tela saber disso.
- **Cor da barra do navegador.** A `meta theme-color` também vem do tema.
- **Prévia.** A tela de prévia liga o tema só naquela requisição (`Appearance::PREVIEW_ATTRIBUTE`);
  nada é gravado.

### As três camadas de tokens

1. **Primitivos (`--p-*`).** A paleta crua do tema:
   - `--p-ink-950…600`: o escuro;
   - `--p-paper-0…200`: o claro;
   - `--p-stone-*`: textos secundários e bordas;
   - `--p-accent-*`: a cor de marca;
   - escolhas por superfície: `--p-accent-solid-dark`, `--p-accent-text-dark`, `--p-focus-dark`,
     `--p-on-primary-accent`;
   - placa sem foto: `--p-plate-*` e `--p-on-plate`.
2. **Semânticos (`--c-*`).** O que os componentes usam. São definidos **por superfície** a partir
   dos primitivos:

   | Grupo | Tokens |
   |---|---|
   | Fundos | `--c-bg`, `--c-surface`, `--c-surface-raised`, `--c-surface-sunken` |
   | Textos | `--c-text`, `--c-text-muted` |
   | Bordas | `--c-border`, `--c-border-strong`, `--c-border-control`, `--c-line` |
   | Ação principal | `--c-primary`, `--c-on-primary` |
   | Cor de marca | `--c-accent`, `--c-on-accent`, `--c-accent-text`, `--c-accent-soft` |
   | Foco e seleção | `--c-focus`, `--c-selection` |
   | Estados | `--c-success`, `--c-warning`, `--c-danger`, `--c-info`, cada um com `-bg` |
   | Bloco invertido | `--c-on-primary-accent`, `--c-on-primary-danger` |
   | Placa sem foto | `--c-plate`, `--c-on-plate` |

   Equivalência com o exemplo do briefing:

   | Briefing | Token |
   |---|---|
   | background | `--c-bg` |
   | surface | `--c-surface` |
   | surface-elevated | `--c-surface-raised` |
   | text-primary | `--c-text` |
   | text-secondary | `--c-text-muted` |
   | border | `--c-border` |
   | primary | `--c-primary` |
   | primary-foreground | `--c-on-primary` |
   | accent | `--c-accent` |
   | accent-foreground | `--c-on-accent` |
   | success / warning / danger | `--c-success` / `--c-warning` / `--c-danger` |
   | focus | `--c-focus` |
3. **Escalas e forma.** São todas tokens que o tema pode trocar:

   | Grupo | Tokens |
   |---|---|
   | Famílias | `--font-display` (títulos), `--font-figure` (números), `--font-body` (texto) |
   | Larguras | `--font-display-stretch`, `--font-title-stretch`, `--font-compact-stretch` |
   | Pesos | `--fw-display`, `--fw-display-strong`, `--fw-display-soft` |
   | Caixa e espaçamento | `--display-case`, `--display-tracking`, `--btn-case`, `--btn-tracking` |
   | Cantos | `--radius-control`, `--radius-card`, `--radius-media` |
   | Tamanhos | `--fs-display`, `--fs-3xl` |
   | Sombras | `--shadow-*` |
   | Fio dos rótulos | `--rule-width`, `--rule-height`, `--rule-image` |
   | Motivo gráfico | `--motif`, `--motif-height` |

### Regras da arquitetura

- **Componentes só usam semânticos e escalas.** Nenhuma tela tem cor solta. Fora de `tokens.css` e
  `themes/`, as únicas cores escritas são:
  - o preto e o branco do comprovante impresso (`@media print`);
  - as amostras e a cor da barra do navegador no catálogo `Theme.php` (dados do tema, conferidos
    contra o CSS por teste);
  - os e-mails, que ficaram fora desta etapa por pedido.
- **Todo elemento com `data-tema` recomeça do conjunto completo do Ofício.** Os primitivos estão em
  `:root, [data-tema]`, e cada tema sobrescreve só o que muda. Assim as miniaturas da tela Aparência
  (um tema dentro de uma página de outro tema) nunca herdam nada do tema da página. As escolhas por
  superfície também são primitivos, por isso não há seletor de descendente que vaze de um tema para
  outro.
- **Motivo gráfico.** Os lugares que desenham o motivo são `.ruler` (elemento), o pé do topo do site,
  a placa sem foto e o estado vazio. Eles declaram as cores (`--motif-line`, `--motif-mark`,
  `--motif-accent`, `--motif-pos`). O tema troca só o **desenho** (`--motif`, `--motif-height`).
- **Fontes.** Todas são auto-hospedadas (pacotes `@fontsource`, licenças livres). Declarar a família
  não baixa nada: o navegador só busca a família que a página usa, ou seja, a do tema ativo.
  - Ofício, Urban Barber e Red Barber: Archivo em larguras diferentes.
  - Black Label: Cormorant.
  - Gentleman's Club: Newsreader.
  - Old School e Copper Club: Roboto Slab.
  - Minimal: só Inter.

## 2. Os oito temas

| # | Tema | Paleta | Letra dos títulos | Detalhe gráfico | Superfícies |
|---|---|---|---|---|---|
| 01 | **Ofício** (padrão) | Grafite `#121211` · Osso `#f5f2ec` · Latão `#b5814a` | Archivo condensada 72%, caixa alta no site | Régua técnica | Site escuro, painel claro, barra grafite |
| 02 | **Black Label** | Preto `#0a0a0a` · Carvão `#1c1b19` · Ouro velho `#b39a63` · Marfim `#f6f2e9` | Cormorant (serifa alta), caixa alta; números em Archivo | Fio de ouro (linha fina com trecho curto dourado) | **Tudo escuro**, inclusive o painel |
| 03 | **Gentleman's Club** | Verde garrafa `#15271f` · Creme `#f5efe2` · Castanho `#6b4429` · Dourado `#b39150` | Newsreader (serifa tradicional), caixa normal | Filete duplo | Site verde, painel creme, barra verde; placa sem foto em castanho |
| 04 | **Urban Barber** | Petróleo `#11252c` · Areia `#f3efe7` · Cobre `#c97f4b` | Archivo **expandida** (112%), pesada, caixa alta | Blocos geométricos (barra + quadrado), cantos retos | Site petróleo, painel areia |
| 05 | **Old School** | Terracota `#b5532f` · Papel `#f2e9d7` · Marrom `#2d1f16` · Creme `#f9f4e8` | Roboto Slab (letra de cartaz), caixa alta | Filete de gráfica (grosso + fino) | **Site claro** com faixa marrom; botão terracota com texto creme |
| 06 | **Red Barber** | Vinho `#9c2731` · Preto `#120c0d` · Creme `#f6f1e9` · Cobre `#dca06f` | Archivo **extra-condensada** (62%), pesada | Traço forte | Site preto; vinho só na ação, cobre nos rótulos do escuro |
| 07 | **Minimal** | Branco quente `#f8f7f4` · Cinza `#8c8a85` · Grafite `#1e1e1e` · Champanhe `#a8926a` | Inter, caixa normal, espaçamento justo | Quase nenhum (fio fino; rótulos sem fio) | **Tudo claro**, barra lateral clara com fio; placa sem foto clara |
| 08 | **Copper Club** | Café `#221914` · Creme `#f6f0e6` · Cobre `#c67a43` · Carvão `#1f1f1e` | Roboto Slab, caixa normal | Costura (tracejado) | Site café, painel creme; placa sem foto em carvão |

Os valores completos de cada tema estão no arquivo dele. As amostras da tela Aparência são conferidas
contra o CSS pelo teste `AppearanceThemesTest`.

**Botões.** Black Label, Urban Barber, Old School e Red Barber usam rótulos em caixa alta espaçada.
Os outros temas usam caixa normal.

**Cantos.**
- 0 no Black Label e no Urban Barber;
- 2–4 px no Ofício, Gentleman's Club, Old School e Red Barber;
- 4–8 px no Copper Club;
- 6–10 px no Minimal.

## 3. Tipografia

- **Texto e interface:** sempre Inter. Leitura, formulários e tabelas não mudam de tema.
- **Títulos:** a família do tema (§2). Caixa alta só nos temas que pedem: Ofício, Black Label,
  Urban Barber, Old School e Red Barber.
- **Números** (preços, horários, valores do caixa e da comissão): a família de título do tema, com
  algarismos tabulares. No Black Label os números ficam na Archivo, porque a Cormorant em tamanho
  pequeno prejudica a leitura da operação.

## 4. Elementos gráficos

| Tema | Motivo (pé do topo, quadro de preços, placa sem foto, estado vazio, chamada final) | Fio dos rótulos |
|---|---|---|
| Ofício | Régua técnica (marcas a cada 8 px, maiores a cada 40 px) | Fio fino |
| Black Label | Linha fina com 3,5 rem dourados | Fio fino |
| Gentleman's Club | Filete duplo | Filete duplo |
| Urban Barber | Barra + quadrado | Quadrado + fio |
| Old School | Filete grosso e fino de gráfica | Grosso + fino |
| Red Barber | Traço curto e grosso sobre fio | Traço grosso |
| Minimal | Fio fino | Nenhum |
| Copper Club | Costura tracejada | Costura |

Os elementos são sutis: nada de barber pole, tesoura ou navalha desenhada, textura de madeira ou
couro. A régua continua sendo a assinatura do Ofício.

## 5. Contraste e acessibilidade

Pares exigidos em **cada** tema e **nas duas superfícies**:

| Par | Mínimo |
|---|---|
| Texto, texto secundário e cor de marca como texto sobre fundo, superfície e superfície rebaixada | 4,5:1 |
| Texto sobre a ação principal, sobre a cor de marca e no bloco invertido | 4,5:1 |
| Texto de estado (sucesso, alerta, erro, informação) sobre o próprio fundo e sobre a superfície | 4,5:1 |
| Iniciais sobre a placa sem foto | 4,5:1 |
| Borda de campo sobre a superfície; foco sobre o fundo | 3:1 |

Como isso é garantido:

1. **Antes de entrar no CSS.** Os valores foram conferidos por script. Dois ajustes saíram daí: a
   borda de campo e o papel do Old School.
2. **No navegador.** `tests/e2e/temas.spec.js` mede os pares acima com os tokens reais, nos 8 temas.
3. **Nas telas.** O axe roda em cada tela de cada tema, no desktop e no celular.

Escolhas que vieram do contraste:

- **Red Barber:** no escuro, o sólido da ação é um vinho mais vivo (`#b8323d`: 3,3:1 contra o preto,
  5,6:1 com o creme). Os rótulos e o foco no escuro ficam em cobre, para o vermelho não dominar.
- **Old School e Red Barber:** o texto sobre a cor de marca é creme. O hover escurece também no fundo
  escuro.
- **Botão de perigo no escuro:** em todos os temas vira contorno. O vermelho claro sólido ficava
  salmão e gritava.

## 6. Personalização futura (não implementada)

O valor gravado já é um objeto (`settings.appearance = {"theme": "..."}`), não um texto solto.

- **Por onde entra.** A personalização entra como uma **camada por cima do tema**, em chaves novas do
  mesmo objeto: cores próprias, fontes, favicon e logo. Logo, nome da barbearia, imagens, redes
  sociais e contatos já são editados em **Conteúdo do site** e **Imagens do site**.
- **Cores próprias.** Seriam primitivos (`--p-accent-*`, por exemplo) escritos num `<style>` com
  nonce depois do arquivo do tema, conferidos pelo mesmo cálculo de contraste antes de salvar.
- **O que não muda.** Os componentes e o catálogo de temas.

## 7. Como adicionar um tema

1. **Catálogo.** Acrescente a entrada em `Theme::THEMES`:
   - `name`, `palette`, `description`, `type`, `motif`;
   - 4 `swatches` com cores que existem no CSS do tema;
   - `surfaces` para `site`, `band`, `panel`, `sidebar` e `auth`;
   - `colors` da barra do navegador.
2. **CSS do tema.** Copie `resources/css/themes/oficio.css` para `resources/css/themes/<chave>.css`.
   Dentro de `[data-tema='<chave>']`, troque:
   - os primitivos que mudam (as 18 cores básicas e, se precisar, as escolhas por superfície);
   - os tokens de tipografia, cantos e botões.
3. **Motivo (opcional).** Defina `--motif` e `--motif-height` nos lugares do motivo e na miniatura:
   `[data-tema='<chave>'] :is(.ruler, .opening, .monogram, .state), .theme-mini[data-tema='<chave>'] .theme-mini__motif`.
4. **Lista.** Importe o arquivo em `resources/css/themes/index.css`.
5. **Fonte nova.** Instale o pacote `@fontsource-variable/...` com a versão fixa e importe em
   `fonts.css`.
6. **Testes.** Acrescente a chave em `tests/e2e/temas.spec.js` e em `tests/visual/capturas.spec.js`,
   depois rode:
   - `AppearanceThemesTest`, que confere o arquivo, a importação e as amostras;
   - a suíte de navegador (projeto `temas`), que confere contraste, axe e rolagem.

Não é preciso mexer em nenhuma tela nem em nenhum componente.
