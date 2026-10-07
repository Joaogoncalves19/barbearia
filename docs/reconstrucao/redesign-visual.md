# Redesign visual — linguagem "Ofício"

> Fase extraordinária de redesign (entre as Fases 12 e 13). Evolução da Direção A ("Ofício contemporâneo"),
> não uma troca de tema. Relatório, auditoria e antes/depois: [relatorio-redesign.md](relatorio-redesign.md).
> Código: `resources/css/tokens.css` (tokens), `resources/css/components/*` (componentes),
> `resources/css/areas/site-marca.css` (site e conta), `resources/css/areas/panel.css` (painel).

## 1. Direção

**Uma barbearia real, moderna e sofisticada que também tem um ótimo produto digital.** O site, a conta do
cliente e o painel da equipe parecem a mesma casa:

- **Grafite e osso**, como o preto da cadeira e o branco da toalha. Contraste alto, pouca cor.
- **Latão** (o cobre refinado) é a única cor de marca e só aparece onde tem função.
- **Precisão** em vez de enfeite. Títulos e números condensados, fios finos e a **régua** como assinatura
  gráfica, como a escala de um pente ou de uma fita de alfaiate.
- **Fotografia como protagonista** quando existir; quando não existir, uma alternativa que não parece falha.

**Fora de propósito:** barber pole em toda parte, tesoura e navalha desenhadas, madeira, bigode, vintage de
filme, marrom em excesso, cara de template ou de SaaS genérico. As faixas listradas e os desenhos de tesoura
e pente da versão anterior **saíram**.

## 2. Tipografia

Duas famílias, auto-hospedadas (sem Google Fonts em tempo de execução):

| Uso | Família | Como |
|---|---|---|
| Títulos de página e de seção, nomes de serviço e de profissional, marca | **Archivo** variável, condensada (`font-stretch: 72%`, peso 640–700) | No site, em **caixa alta** (`.caps`); no painel, em caixa normal |
| Números: preços, horários, valores financeiros, indicadores | Archivo condensada, **tabular** (`.figure`, `.figure--sm`, `.figure--lg`, `.cell-figure`) | Sempre alinhados em coluna |
| Interface e texto | **Inter** | 14 px no painel, 16 px no site e nos campos |
| Rótulos e "eyebrows" | Inter 600, 11–12 px, caixa alta espaçada | Com o fio à esquerda (`.eyebrow`) |

A Fraunces, que deixava tudo "suave" (mais confeitaria que barbearia), saiu do projeto. A Archivo substitui a
Fraunces, mantendo o total em duas famílias.

Escala (`tokens.css`):
- **Títulos:** `--fs-display` (hero), `--fs-3xl`, `--fs-2xl`, `--fs-xl`.
- **Números:** `--fs-figure-sm`, `--fs-figure`, `--fs-figure-lg`.
- **Texto:** `--fs-xs` a `--fs-lg`.

As telas não têm a mesma escala:
- o site usa títulos enormes;
- o painel usa títulos médios e números grandes só onde importam;
- a conta fica no meio-termo.

## 3. Paleta (cada cor tem função)

| Token | Claro (painel) | Escuro (site, barra do painel) | Função |
|---|---|---|---|
| `--c-bg` | osso `#f5f2ec` | grafite `#121211` | Fundo |
| `--c-surface` / `-raised` / `-sunken` | `#fcfbf8` / `#fcfbf8` / `#ede8e0` | `#1b1a19` / `#252321` / `#121211` | Superfícies |
| `--c-text` | `#121211` | `#f5f2ec` | Texto |
| `--c-text-muted` | `#625c55` (6,4:1) | `#a8a095` (7,3:1) | Texto secundário |
| `--c-border` / `--c-border-strong` | `#ddd6cb` / `#8e867c` | `#34312e` / `#46423d` | Fios e divisórias |
| `--c-primary` | grafite | osso | Ação principal do painel, "número-chave", comanda |
| `--c-accent` (latão) | `#b5814a` | `#b5814a` | Só: ação que converte (Agendar, Concluir e receber), item ativo, "agora" da agenda, foco, índices |
| `--c-accent-text` | `#7a4f25` (6,8:1) | `#ddae72` (9,3:1) | Latão como texto |
| Estados | sucesso `#2e6a4e`, alerta `#8a5a12`, erro `#a23a2d`, informação `#2e5d77` | versões claras para fundo escuro | Sempre com texto e ícone, nunca só cor |

Todos os pares de texto passam do contraste AA (4,5:1); bordas de controle passam de 3:1 (conferido com script
antes de usar).

## 4. Espaço, forma e movimento

- **Espaçamento:** base de 4 px (`--space-*`). Respiro entre capítulos do site: `--space-section`, de 72 a 144 px.
- **Cantos mais retos:** 4 px nos controles, 6 px nos cartões, 2 px nas mídias. É o lado preciso da marca.
- **Sombra:** só no que flutua (menu, modal, toast). Cartões e seções usam fio ou troca de superfície.
- **Movimento:**
  - curto: 120–360 ms;
  - só em hover, foco, seleção, troca de estado, o ponto "ao vivo" e a seta que avança;
  - `prefers-reduced-motion` zera todas as durações.

## 5. Elementos gráficos (assinatura)

| Elemento | Onde |
|---|---|
| **Régua** (marcas finas de medida; `.ruler`, `.ruler--accent`) | Pé do topo do site, letreiro, placa dos profissionais sem foto, painel de acesso, chamada final, estado vazio, eixo da agenda |
| **Fio + índice** ("01 —", `.chapter__index`, numeração automática pelo CSS: nunca pula número quando uma seção não aparece) | Capítulos do site |
| **Selo** (inicial num quadrado de fio de latão; `.brand__mark`) | Marca enquanto o logo real não chega |
| **Letreiro** (`.signboard`: serviços e preços reais com pontilhado) | Topo do site sem foto |
| **Ponto ao vivo** (`.live-dot`) | Em atendimento, caixa aberto |

## 6. Imagens

- Fotografia é protagonista: retrato 4:5 na equipe e no perfil, foto alta 4:5 com moldura de fio no topo, 3:2
  na casa, quadrada na galeria. Sempre `object-fit: cover`, largura e altura no `<img>` (sem salto de layout),
  `srcset` das variantes WebP geradas no envio, `loading="lazy"` (exceto a do topo, que é `eager`),
  tratamento leve (`saturate(0.9)`).
- **Sem foto** (fotos e logo reais ainda não chegaram, P11-02): nada de imagem de banco nem placeholder com
  cara de erro.
  - **Topo:** vira o "letreiro" de preços reais.
  - **Profissional:** vira uma placa de grafite com as iniciais grandes e a régua.
  - **Serviço no painel:** miniatura tracejada com o ícone.
  - **Logo:** o selo com a inicial.

## 7. Componentes (o que mudou)

| Componente | Mudança |
|---|---|
| Botão | Mesmas variantes; `secondary` com fio mais forte, novo `btn--link`; `accent` reservado para converter |
| Card | Menos usado; padding maior; tabela dentro do card sem moldura dupla |
| Badge | Pequeno, retangular, caixa alta espaçada; cor só para estado |
| Tabela | Cabeçalho leve (rótulo pequeno + fio forte), linhas com fio fino, célula numérica condensada (`.cell-figure`), miniatura (`.item-cell`) |
| Indicadores (`.stats`) | Uma faixa com divisórias, não quatro cards; `.stat--key` destaca o número que manda na tela (dinheiro na gaveta, líquido a repassar) |
| Estado vazio | Alinhado à esquerda, régua em cima, título condensado, ação quando faz sentido |
| Alerta | Fio fino e barra lateral de 2 px |
| Abas, menu | Indicador em grafite (abas) e em latão (menu) |
| Campo de arquivo | Botão nativo substituído pelo padrão da casa |
| Novos | `.board` (bloco com título e fio), `.todo-list` (pendências), `.live-dot` |

## 8. Navegação

- **Site:**
  - topo fixo com o menu em caixa alta espaçada e "Agendar horário" sempre à mão;
  - no celular, barra inferior com Agendar e WhatsApp;
  - rodapé com a frase da casa e as informações reais.
- **Painel:**
  - barra lateral **grafite**, com o menu agrupado pelo trabalho (Hoje; Clientes e vendas; Equipe e
    catálogo; Financeiro; Configurações), não pela fase em que cada tela foi feita;
  - topo só com o dia e o menu da pessoa (minha conta, senha, sair);
  - a busca e o sino de protótipo, que não faziam nada, saíram.
- **Conta do cliente:** menu lateral no desktop; no celular, uma faixa que rola dentro dela e abre no item atual.

## 9. Padrões por tamanho de tela

| Padrão | Desktop | Celular |
|---|---|---|
| Topo do site | Título enorme + letreiro/foto lado a lado | Empilhado; título continua dominante |
| Capítulos | Título fixo à esquerda, conteúdo à direita | Empilhado |
| Agenda | Colunas por profissional lado a lado | Uma coluna ocupa 72% da tela; o quadro rola de lado **só dentro dele**, régua presa |
| Comanda | Trabalho à esquerda, conta à direita | Conta (total e concluir) **primeiro** |
| Tabelas | Colunas | `stacked`: cada linha vira um cartão com rótulos |
| Acesso | Painel da marca + formulário | Marca em faixa curta + formulário |

Nenhuma tela pode rolar a página de lado; os testes conferem isso em todas as telas.

## 10. Telas redesenhadas

Profundamente:
- **Site:** início, serviços, equipe, profissional, assinatura, agendamento (serviço, profissional, dia e
  horário).
- **Acesso:** login e cadastro, de equipe e de cliente.
- **Erros.**
- **Painel:** navegação, início "Hoje" (novo), agenda (linha do tempo), comanda.
- **Conta:** início.

As demais telas herdam a linguagem pelos tokens e componentes (tipografia, tabelas, indicadores, estados vazios,
formulários, badges) sem mudar estrutura. Lista e classificação em [relatorio-redesign.md](relatorio-redesign.md).

## 11. Decisões importantes

1. **Archivo condensada no lugar da Fraunces.** A serifa suave puxava para "delicado"; a grotesca condensada
   dá o urbano e o preciso pedidos, e serve para os números.
2. **Latão restrito.** Antes, o cobre aparecia em tudo; agora é a cor da ação e do "agora".
3. **Painel com barra grafite.** É o contraste que faz o painel parecer da mesma casa do site, sem
   transformar o trabalho do dia em tela escura.
4. **Início do painel = o dia.** A tela era um texto de espera; agora mostra:
   - quem está na cadeira;
   - o que vem a seguir;
   - o caixa;
   - o que pede atenção.

   Não traz nenhum relatório financeiro novo: isso depende da parte do roadmap ainda não aprovada.
5. **Agenda como linha do tempo.** A altura de cada horário é a duração dele, então os buracos do dia ficam
   visíveis.
6. **Sem foto não é falha.** O letreiro e a placa de grafite usam só dados reais.
7. **Numeração automática dos capítulos**, para nunca exibir "01, 03" quando um bloco não tem conteúdo.

## 12. Problemas encontrados e o que ficou para depois

Ver [relatorio-redesign.md](relatorio-redesign.md) §6 (problemas encontrados) e §7 (pendências).
