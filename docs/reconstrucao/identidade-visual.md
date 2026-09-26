# Identidade visual — proposta (Fase 1)

> **Status: direção A "Ofício contemporâneo" aprovada pelo dono no início da Fase 2 e oficial.**
> A direção B "Urbano gráfico" ficou só como **referência histórica**: os primitivos e a fonte dela estão em
> `resources/css/prototypes/direcao-b.css`, carregado apenas nas páginas de referência
> (`/prototipos?direcao=b`, com a flag `BARBEARIA_PROTOTYPES`). Nenhuma tela do produto a carrega; o layout
> força a direção A fora dos protótipos (teste `ReferenceScreensTest::test_direcao_a_e_a_oficial_...`).
> As seções abaixo que descrevem a B são registro da comparação feita na Fase 1.
>
> **Logo, nome de exibição e fotos reais são pendências do dono** (D-07, D-08). As telas usam
> "Barbearia Exemplo", um monograma provisório e marcadores de foto identificados.

## 1. Objetivo

Transmitir uma **barbearia moderna, masculina, sofisticada e acolhedora**, sem cair no clichê
"vintage": nada de texturas envelhecidas, rótulos de barber pole, bigodes ou tipografia de
faroeste. O caráter vem de **fotografia real**, **tipografia com personalidade**, **cor
contida** e **respiro**.

O que mudou em relação ao sistema antigo (ver [ux-ui-atual.md](ux-ui-atual.md)):

| Antes | Agora |
|---|---|
| Azul padrão `#007bff`, sem relação com barbearia | Um único acento quente (cobre) ou vibrante (tomate), escolhido por significado |
| Fundo azul-marinho de painel, cartões "de vidro" | Carvão quente no site; papel claro no painel; seções claras e escuras alternadas |
| Tipografia neutra de SaaS em peso 900 | Serifada contemporânea (A) ou grotesca expressiva (B) nos títulos; Inter no texto |
| Nenhuma foto real; ícones no lugar de fotos | Fotografia como elemento principal; marcadores dizem qual foto real vai em cada lugar |
| Números fictícios e botão "Admin" no site | Só dados reais; painel em URL própria |

## 2. As duas direções

### Direção A — "Ofício contemporâneo" (recomendada)

- **Ideia:** o cuidado do ofício. Referências de couro, madeira escura, latão e luz quente,
  traduzidas em cores lisas e tipografia editorial. Sofisticada e acolhedora.
- **Cores:** carvão quente + papel/osso + **cobre**.
- **Títulos:** *Fraunces* (serifada variável, eixo óptico no máximo, peso 520). Itálico
  pontual para ênfase ("boa *conversa*").
- **Forma:** cantos levemente arredondados (6 px nos controles, 10 px nos cards).
- **Quando escolher:** público que valoriza experiência, atendimento e ambiente, com preço
  médio/alto.

### Direção B — "Urbano gráfico"

- **Ideia:** energia de rua e cultura urbana, gráfica e direta.
- **Cores:** tinta (quase preto esverdeado) + gesso + **tomate** (o vermelho do barber pole,
  sem as listras).
- **Títulos:** *Bricolage Grotesque* (grotesca variável, peso 700).
- **Forma:** cantos retos (2 px). Mais contraste, menos ornamento.
- **Quando escolher:** público mais jovem, cortes de tendência, forte presença em redes sociais.

**Diferença prática:** A privilegia calor e sofisticação; B privilegia impacto e
contemporaneidade. Estrutura, componentes e acessibilidade são idênticos; muda só a camada
de tokens (troca em minutos).

## 3. Paleta — lógica e tokens

A paleta tem três papéis, pedidos no briefing:

| Papel | Direção A | Direção B | Por quê |
|---|---|---|---|
| **Cor principal** (marca, fundo do site, texto sobre claro) | Carvão `#121110` → `#4A443D` | Tinta `#0D1110` → `#414C48` | Base escura e quente (A) ou fria (B): elegância e contraste para fotografia |
| **Cor secundária** (superfícies claras, respiro) | Papel/osso `#F8F5EF`, `#FFFDF9` | Gesso `#F5F6F3`, `#FFFFFF` | Alternância de seções claras evita o "site escuro inteiro" monótono e cansativo |
| **Cor de destaque** (conversão: "Agendar", foco, item ativo) | Cobre `#BD8246` | Tomate `#E0522F` | **Um único acento**, reservado para a ação principal: o olho encontra o "Agendar" em qualquer tela |

Tokens semânticos (o que os componentes usam), em `resources/css/tokens.css`:

| Token | Uso | Claro (A) | Escuro (A) |
|---|---|---|---|
| `--c-bg` | Fundo da página | `#F8F5EF` | `#121110` |
| `--c-surface` | Cards, campos | `#FFFDF9` | `#1B1917` |
| `--c-text` | Texto principal | `#121110` | `#F8F5EF` |
| `--c-text-muted` | Texto secundário | `#6A6258` | `#B8AFA2` |
| `--c-border` | Divisores (decorativos) | `#DDD5C8` | `#35312C` |
| `--c-border-control` | Borda de campo (≥ 3:1) | `#8C8377` | `#8A8177` |
| `--c-primary` / `--c-on-primary` | Botão principal | carvão / papel | papel / carvão |
| `--c-accent` / `--c-on-accent` | Botão de destaque | cobre / carvão | cobre / carvão |
| `--c-accent-text` | Acento como texto/link | `#80532A` | `#E2B37D` |
| `--c-focus` | Contorno de foco | `#80532A` | `#E2B37D` |
| `--c-success` | Sucesso | `#2B7350` | `#72CB9A` |
| `--c-warning` | Alerta | `#8A5A12` | `#E9B863` |
| `--c-danger` | Erro | `#A8342A` | `#F0907F` |
| `--c-info` | Informação | `#2A617E` | `#86BEDC` |

Cores de estado são iguais nas duas direções, sempre acompanhadas de ícone e texto (nunca só
cor). Cada uma tem fundo suave (`--c-*-bg`) para alertas e badges.

### Contraste verificado (WCAG 2.2)

Calculado para todos os pares texto/fundo usados. Mínimo AA: 4,5:1 para texto e 3:1 para
componentes de interface.

| Par | Direção A | Direção B |
|---|---|---|
| Texto / fundo claro | 16,9 | 17,3 |
| Texto secundário / fundo claro | 5,5 | 5,2 |
| Texto no botão principal | 16,1 | 16,2 |
| Texto no botão de destaque (escuro sobre acento) | 5,6 | 4,8 |
| Acento como texto / fundo claro | 6,1 | 5,5 |
| Texto / fundo escuro | ≥ 16,5 | ≥ 17,0 |
| Texto secundário / fundo escuro | 8,7 | 9,2 |
| Acento como texto / fundo escuro | 9,9 | 8,4 |
| Borda de campo / superfície clara (UI) | 3,7 | 3,8 |
| Borda de campo / superfície escura (UI) | 4,6 | 4,3 |
| Sucesso / alerta / erro / info sobre fundo suave | 4,9 / 5,2 / 5,5 / 5,8 | idem |

Regra derivada: **nunca** usar o acento sólido como cor de texto sobre fundo claro (2,6–3:1).
Para isso existe `--c-accent-text`. O axe-core roda nas telas de referência e falha o teste se
aparecer contraste insuficiente.

## 4. Tipografia

| Uso | Fonte | Pesos |
|---|---|---|
| Títulos do site (display, H1–H3) | A: Fraunces Variable (opsz 144) · B: Bricolage Grotesque Variable | A: 520 · B: 700 |
| Texto, interface, **títulos do painel** | Inter Variable | 400, 500, 600, 700 |
| Números (preço, hora) | Inter com `tabular-nums` | 600 |

Fontes **hospedadas no próprio domínio** (pacotes `@fontsource`, licença OFL), carregadas com
`font-display: swap`. Sem Google Fonts em tempo de execução (desempenho e LGPD).

Escala (fluida entre celular e desktop):

| Token | Tamanho | Uso |
|---|---|---|
| `--fs-display` | 44 → 88 px | Hero |
| `--fs-3xl` | 36 → 56 px | Título de página (H1) |
| `--fs-2xl` | 28 → 38 px | Título de seção (H2) |
| `--fs-xl` | 22 → 26 px | Subtítulo (H3), título de página no painel |
| `--fs-lg` | 20 px | Título de card, nome de serviço |
| `--fs-md` | 18 px | Texto de destaque (lead) |
| `--fs-base` | 16 px | Texto corrido; mínimo em campos (evita zoom no iOS) |
| `--fs-sm` | 14 px | Interface do painel |
| `--fs-xs` | 12 px | Legendas, rótulos, badges |

Hierarquia: o site usa a fonte de título para nomes de serviço, profissionais e depoimentos.
O painel usa **só a Inter** (leitura rápida). Sobrelinhas (`.eyebrow`) em caixa alta
espaçada e na cor de acento abrem as seções do site.

## 5. Espaçamento

Base de **4 px**: `--space-1` (4) · `2` (8) · `3` (12) · `4` (16) · `5` (20) · `6` (24) ·
`8` (32) · `10` (40) · `12` (48) · `16` (64) · `20` (80) · `24` (96). Entre seções do site:
`--space-section` (64 → 128 px, fluido). Largura de conteúdo: 1216 px; linha de leitura 68ch.
Alvos de toque de **44 px** no mínimo.

## 6. Bordas, divisores e raios

- Borda de **1 px**; divisores decorativos em `--c-border` (baixo contraste, de propósito).
- Campos usam `--c-border-control` (≥ 3:1).
- Raios: `--radius-control` (A 6 px / B 2 px), `--radius-card` (A 10 px / B 2 px),
  `--radius-media` (A 4 px / B 0), `--radius-pill` para badges.
- No site, listas (menu de serviços, horários) usam **divisores**, não cartões.

## 7. Sombras

**Só no que flutua:** menus suspensos (`--shadow-md`), modais (`--shadow-lg`), toasts.
Cards, seções e botões **não** têm sombra: usam borda ou mudança de superfície. Isso evita a
aparência de "painel genérico" do sistema antigo e deixa a fotografia protagonista.

## 8. Ícones

- **Lucide** (licença ISC): traço 1,75, cantos arredondados, estilo linear consistente.
- Copiados como SVG local só os usados (`resources/icons`, script `scripts/copiar-icones.mjs`).
- Tamanho padrão 1,25 em, herdam a cor do texto.
- Decorativos por padrão (`aria-hidden`); com `label` viram imagem acessível.
- **Ícone não substitui foto.** No site, ícones só aparecem em informações práticas e diferenciais.

## 9. Fotografia

A fotografia é o maior diferencial possível e é **pré-requisito da Fase 11**.

| Onde | Foto | Formato |
|---|---|---|
| Hero | Ambiente com luz quente, cadeiras, espelhos | Horizontal 16:9, alta resolução |
| Equipe | Retrato de cada profissional no posto | Vertical 4:5, mesmo enquadramento para todos |
| Galeria | Trabalhos finalizados, mãos e ferramentas em ação, detalhes da bancada, fachada | Quadrado e vertical |
| Localização | Fachada / como chegar | Horizontal 3:2 |

Diretrizes: luz quente e mesma temperatura em todas as fotos; pessoas reais (com autorização
de imagem); nada de banco de imagens. Enquanto não houver fotos, o componente `x-ui.photo`
mostra um **marcador listrado que diz qual foto deve entrar ali** e "Foto real pendente".

## 10. Tom de voz

Direto, próximo e confiante. Frases curtas, verbos de ação ("Agendar horário", "Ver
serviços e preços"). Nada de superlativos vazios ("os melhores da região", "experiência
premium"). Promessas concretas e verificáveis.

## 11. O que o dono precisa decidir

1. ~~Direção A ou B~~ — **decidido: A** (início da Fase 2).
2. **Logo** em vetor (o monograma atual é provisório).
3. **Nome de exibição** e frase da marca (o "Corte, barba e boa conversa." é exemplo).
4. **Sessão de fotos** (D-08).
