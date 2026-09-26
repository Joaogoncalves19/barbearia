# 11. Proposta de design

> Nada foi implementado. Esta proposta orienta a Fase de design system e as telas.
> Cores, fontes e nome finais dependem da marca real da barbearia (**D-07**).

## 11.1 Uma marca, duas experiências

| | Site público | Sistema (cliente, profissional, admin) |
|---|---|---|
| Objetivo | Despertar desejo e levar ao agendamento | Resolver tarefas rápido |
| Tom | Editorial, quente, com pessoas e ambiente | Neutro, claro, denso na medida certa |
| Fundo | Escuro e quente (carvão/café), com fotografia em tela cheia | Claro por padrão (modo escuro opcional) |
| Tipografia | Títulos com personalidade + texto limpo | Só a fonte de texto, com números tabulares |
| Cor de destaque | Usada com parcimônia (CTAs, detalhes) | Estados e ações primárias |
| Movimento | Sutil (fade/parallax leve), respeitando `prefers-reduced-motion` | Mínimo, só feedback |
| Elemento comum | Logo, cor de destaque, ícones, tom de voz | Idem |

A área do cliente e o fluxo de agendamento ficam **no meio do caminho**: usam os
componentes do sistema, mas com cabeçalho, fotografia e cores da marca, para o cliente
não sentir que "saiu" do site.

## 11.2 Direção visual do site público

**Conceito sugerido:** *"ofício, cuidado e conversa"*. A barbearia como lugar de mãos
habilidosas, ritual (toalha quente, navalha, acabamento) e boa conversa. É uma direção
própria. Não copia nenhuma referência pesquisada; só usa os padrões que elas confirmam
(foto real, fundo escuro e quente, um acento, agendamento sempre à vista).

### Paleta proposta (ponto de partida, a validar com a marca)

| Token | Uso | Exemplo |
|---|---|---|
| `--brand-ink` | Fundo principal escuro | carvão quente `#141210` |
| `--brand-surface` | Blocos sobre o fundo | café escuro `#1E1A17` |
| `--brand-paper` | Seções claras de respiro | papel `#F4EFE7` |
| `--brand-accent` | CTAs e detalhes | cobre/latão `#C08A4B` (alternativa: vermelho de barber pole) |
| `--brand-text` | Texto sobre escuro | `#F2ECE4` |
| `--brand-muted` | Texto secundário | `#B3A99D` (contraste ≥ 4.5:1 sobre o fundo) |

Contraste calculado (WCAG): texto `#F2ECE4` sobre `#141210` = 15,9:1; texto secundário
`#B3A99D` sobre `#141210` = 8,1:1; acento `#C08A4B` sobre `#141210` = 6,2:1 (serve para botão
com texto escuro). **Atenção:** o acento sobre `--brand-paper` dá só 2,6:1. Nas seções
claras, o acento é decorativo e textos/links usam uma variante escura do acento.

Regras: um único acento; fundo nunca azul; contraste AA verificado para todo texto.
A cor configurável pelo admin continua existindo, mas limitada a uma paleta validada
(o sistema recusa combinações sem contraste).

### Tipografia proposta

- **Títulos:** uma serifada de alto contraste ou uma display condensada com caráter
  (ex.: famílias abertas como *Fraunces*, *DM Serif Display* ou *Bebas Neue*). Escolha final
  na Fase de design, conforme o logo.
- **Texto e interface:** sans-serif legível (ex.: *Inter* ou *Manrope*).
- Hospedadas localmente, só com os pesos usados.
- Escala tipográfica fluida (`clamp`) com no máximo 6 tamanhos.

### Fotografia (o fator mais importante)

| Tipo | Uso | Obrigatória? |
|---|---|---|
| Ambiente (cadeiras, espelhos, luz, fachada) | Hero, "Sobre" | **Sim** |
| Mãos e ferramentas em ação (tesoura, navalha, máquina) | Hero alternativo, divisórias | Recomendada |
| Retrato de cada profissional | Equipe | **Sim** (senão a seção fica em modo compacto) |
| Resultados (cortes e barbas finalizados) | Galeria, serviços | Recomendada |
| Clientes (com autorização de imagem) | Depoimentos | Opcional |

Diretrizes: luz quente, mesma temperatura de cor em todas, formatos 4:5 (retratos) e
3:2/16:9 (ambiente), sem banco de imagens. Enquanto não houver fotos, o site usa
**composições tipográficas** e texturas próprias (não fotos genéricas). **D-08:** sessão de
fotos profissional recomendada antes da Fase do site.

### Componentes-chave

- Cabeçalho fixo e discreto: logo, 4–5 links, botão **Agendar** sempre visível.
- No celular: barra inferior fixa com **Agendar** e **WhatsApp**, na zona do polegar.
- Cartão de serviço: nome, descrição curta, duração e preço; foto opcional.
- Cartão de profissional: retrato 4:5, nome, especialidade, botão "agendar com ele(a)".
- Depoimento: texto, nome, nota, data.
- Bloco "Informações": endereço, mapa, horários do dia destacados ("aberto agora até 20h").

## 11.3 Nova landing page — estrutura proposta

A home é uma página única com seções. Cada seção tem uma finalidade e uma
classificação: **obrigatória**, **opcional** ou **dependente de conteúdo** (só aparece se o
conteúdo existir, sem espaços vazios nem números fictícios).

| # | Seção | Finalidade | Classificação | Conteúdo necessário |
|---|---|---|---|---|
| 1 | **Cabeçalho fixo** | Navegar e agendar de qualquer ponto | Obrigatória | Logo, links, CTA |
| 2 | **Hero** | Em 3 segundos dizer *o que é, para quem, onde* e oferecer o agendamento | Obrigatória | Foto ou vídeo curto do ambiente, frase de marca (1 linha), subtítulo, CTA "Agendar horário" + secundário "Ver serviços e preços" |
| 3 | **Faixa de confiança** | Reduzir a dúvida logo abaixo do hero | Dependente de conteúdo | Nota média e nº de avaliações **reais**, "aberto hoje até X", bairro |
| 4 | **Serviços e preços** | Mostrar o menu completo com preço e duração, agrupado por categoria | Obrigatória | Catálogo (vem do sistema) |
| 5 | **Assinatura** | Apresentar os planos mensais | Dependente de conteúdo + **D-03** | Planos ativos |
| 6 | **Equipe** | Criar conexão com quem atende; agendar com um profissional | Obrigatória (modo compacto sem fotos) | Retratos, especialidades, bio curta, Instagram |
| 7 | **Sobre a barbearia** | Contar a história e o jeito da casa | Opcional | Texto curto + 1–2 fotos |
| 8 | **Galeria** | Provar a qualidade com trabalhos reais | Dependente de conteúdo | ≥ 6 fotos reais |
| 9 | **Diferenciais** | 3–4 motivos concretos (ex.: "toalha quente em toda barba", "café e cerveja", "estacionamento") | Opcional | Textos do dono |
| 10 | **Depoimentos** | Prova social | Dependente de conteúdo | Avaliações destacadas reais (≥ 3) |
| 11 | **Localização e horários** | Tirar dúvida prática e levar até a porta | Obrigatória | Endereço, mapa, horários, telefone, WhatsApp, como chegar |
| 12 | **CTA final** | Última chamada para agendar | Obrigatória | — |
| 13 | **Rodapé** | Contato, redes, links legais | Obrigatória | Redes sociais, CNPJ (opcional), termos, privacidade |

Removidos em relação ao atual:

- botões "Admin"/"Painel Admin" (o painel fica em URL própria);
- "Barbeiro em destaque" por nota (cria comparação pública entre a equipe; pode virar opção **D-09**);
- contadores animados com números padrão;
- barra de "pílulas" no meio da página (substituída pelo cabeçalho fixo);
- logo do produto como padrão (o site exige o logo da barbearia na configuração inicial).

Ordem pensada para o celular: hero → confiança → serviços → equipe → depoimentos →
localização. **Sobre**, **galeria** e **diferenciais** entram entre equipe e depoimentos
quando houver conteúdo.

### Esboço (celular)

```text
┌──────────────────────────────┐
│ [logo]              ☰        │  cabeçalho fixo
├──────────────────────────────┤
│  (foto do ambiente, escura)  │
│  CORTE, BARBA E CONVERSA     │  frase de marca
│  no coração do <bairro>      │
│  [ Agendar horário ]         │
│  Ver serviços e preços →     │
├──────────────────────────────┤
│ ★ 4,9 · 212 avaliações       │  faixa de confiança
│ Aberto hoje até 20h          │
├──────────────────────────────┤
│ SERVIÇOS                     │
│ Cabelo ▸ Corte ....45 min R$45│
│ Barba  ▸ Barba ....30 min R$35│
│ Combos ▸ Corte+Barba ...R$70 │
├──────────────────────────────┤
│ EQUIPE  [foto][foto][foto] → │  carrossel horizontal
├──────────────────────────────┤
│ “Melhor degradê da cidade…”  │  depoimentos
├──────────────────────────────┤
│ Rua X, 123 · [mapa]          │
│ Seg–Sáb 9h–20h               │
├──────────────────────────────┤
│ [ Agendar ]   [ WhatsApp ]   │  barra inferior fixa
└──────────────────────────────┘
```

## 11.4 Fluxo de agendamento (proposto)

Meta: **menos de 90 segundos no celular**, sem precisar criar conta antes.

1. **Serviços:** escolhe um ou mais (preço e duração visíveis; total atualizado).
2. **Profissional:** escolhe alguém ou "qualquer disponível" (só aparecem os que fazem
   todos os serviços escolhidos).
3. **Data e horário:** calendário mostra só dias com vaga; horários agrupados por período.
4. **Identificação e confirmação:** se não estiver logado, entra ou cria conta **nesta etapa**
   (nome, telefone, e-mail, senha) ou usa o link de acesso por e-mail [decisão]. Vê o resumo
   com o orçamento (descontos explicados) e confirma.
5. **Confirmação:** tela de sucesso com adicionar ao calendário, link do WhatsApp da
   barbearia e acesso a "Meus agendamentos".

Acesso direto também pelos cartões do site: "agendar com o Carlos" ou "agendar corte"
pré-seleciona o passo correspondente.

## 11.5 Sistema / painel

### Princípios

- **Tela "Hoje" primeiro:** agenda do dia por profissional, próximo cliente, caixa do dia e
  pendências reais. No máximo 4 indicadores no topo; o resto vai para Relatórios.
- **Uma tarefa por tela:** Catálogo dividido em Serviços, Combos, Produtos e Estoque.
- **Agenda visual:** colunas por profissional (dia) e grade semanal; arrastar para remarcar
  (validado no servidor); lista para a recepção.
- **Formulários curtos:** campos agrupados, validação no servidor com mensagens claras,
  estados de carregando/erro/vazio sempre desenhados.
- **Leitura rápida:** números tabulares, alinhamento à direita para valores, badges de status
  com cor **e** texto.
- **Responsivo de verdade:** o painel do profissional é mobile-first (usado na cadeira); o
  admin é desktop-first, mas utilizável no celular (agenda, clientes, caixa).
- **Sem marca do desenvolvedor** na interface do cliente final. Crédito, se desejado, só
  numa página "Sobre o sistema".

### Estrutura de navegação do admin

```text
Hoje · Agenda · Clientes · Caixa
─────────────
Equipe · Catálogo · Promoções · Comunicação · Avaliações · Site
─────────────
Financeiro · Relatórios · Assinaturas*
─────────────
Configurações · Auditoria
(* se aprovada)
```

Os itens aparecem conforme as permissões do usuário.

## 11.6 Design system (entregável da fase de fundação)

- Tokens: cores (marca e sistema, claro/escuro), tipografia, espaçamentos (escala de 4 px),
  raios, sombras, movimento, *breakpoints*.
- Componentes base: botão, link, campo, seleção, data/hora, checkbox/switch, badge, alerta,
  toast, modal (`<dialog>`), tabela responsiva, paginação, abas, cartão, estado vazio,
  esqueleto de carregamento, avatar, calendário de disponibilidade, grade de horários.
- Página viva de componentes (só em desenvolvimento/homologação).
- Critérios: contraste AA, foco visível, navegação por teclado, alvos de toque ≥ 44 px,
  `prefers-reduced-motion`.

## 11.7 Metas mensuráveis

| Meta | Alvo |
|---|---|
| Lighthouse (site, celular) | Performance ≥ 90, Acessibilidade ≥ 95, SEO ≥ 95 |
| LCP no 4G | < 2,5 s |
| Peso da home (sem vídeo) | < 1 MB |
| Agendamento completo (usuário novo, celular) | < 90 s em teste com 5 pessoas |
| Contraste | 100% dos textos AA |
