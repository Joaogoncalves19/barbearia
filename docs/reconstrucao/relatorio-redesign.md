# Relatório — Redesign visual (fase extraordinária)

> Fase extraordinária entre a Fase 12 e a Fase 13, só de interface: direção de arte, composição, hierarquia,
> tipografia, navegação, componentes, estados e experiência. Nenhuma regra de negócio, permissão, cálculo,
> modelo de dados ou fluxo de LGPD foi mudado (ver §6). Branch `claude/redesign-visual`, a partir de
> `claude/fase-12-area-cliente`. Linguagem final em [redesign-visual.md](redesign-visual.md).
>
> **Status: concluída, aguardando a avaliação visual e a aprovação explícita do dono.** Não inicia a Fase 13.
>
> Legenda: **PASSOU** · **NÃO EXECUTADO** · **PENDENTE** · **FALHOU** · **DECISÕES NECESSÁRIAS**.

## 1. Auditoria visual (antes de qualquer mudança)

Base: capturas de **todas as telas principais** no desktop (1440 px) e no celular (Pixel 7), geradas sobre um
banco de **demonstração** com dados fictícios (`php artisan app:demo-data`, novo nesta fase: agenda do dia em
volta da hora atual, duas semanas de atendimentos, caixa, comissões, avaliações, assinaturas). As capturas
"antes" estão em [img/redesign/antes/](img/redesign/antes/), por área.

Classificação: **boa** · **aceitável** · **precisa de melhoria** · **precisa ser redesenhada**.

### 1.1 Problemas transversais (valem para quase tudo)

| Problema | Onde aparece |
|---|---|
| Tipografia sem caráter: Fraunces suave (peso 520) lembra confeitaria/casamento, não barbearia urbana; no painel tudo é Inter do mesmo tamanho, sem hierarquia entre número, nome e rótulo | Site, painel, conta |
| Painel genérico: barra lateral creme com 25 itens agrupados por fase do projeto, topo com **busca e sino que não fazem nada** (controles de protótipo), cards com borda em toda parte | Todo o painel |
| Cor sem função: cobre em botões, ícones, bordas, faixas e números ao mesmo tempo; o painel é bege sobre bege, sem um contraste que guie o olho | Site, painel |
| Elementos de barbearia como decoração: faixa de barber pole em várias seções, tesoura e pente desenhados soltos — o que o próprio briefing pede para evitar | Site |
| Números sem destaque: preços, horários e valores financeiros no mesmo peso do texto | Agenda, caixa, financeiro, conta |
| Estados vazios com o mesmo desenho genérico (ícone num círculo + frase) | Todas as áreas |
| Formulários e tabelas "CRUD padrão": cada seção num card, ações espalhadas, colunas de ordem ocupando espaço | Cadastros, financeiro |
| Celular: telas do painel empilham filtros e botões sem prioridade; a agenda vira uma coluna longa de cards | Painel no celular |

### 1.2 Telas

| Área | Tela | Classificação | Por quê |
|---|---|---|---|
| Site | Início | **Redesenhar** | Composição de seções iguais (rótulo + título + lista) do começo ao fim, tudo escuro; hero sem lugar para fotografia; ilustração de tesoura fraca; faixas de barber pole; não conta uma história |
| Site | Serviços | Precisa de melhoria | Quadro de preços correto, mas pequeno, sem ritmo entre categorias, preço sem destaque |
| Site | Equipe | Precisa de melhoria | Monogramas sobre hachura parecem "foto faltando" |
| Site | Profissional | Precisa de melhoria | Mesma questão; serviços do profissional como lista simples |
| Site | Assinatura | Precisa de melhoria | Cards de plano genéricos |
| Site | Agendamento (serviço, profissional, horário) | Precisa de melhoria | Fluxo correto e claro; visual cru (dias e horários como botões genéricos), resumo do pedido fraco |
| Site | Páginas legais | Aceitável | Leitura boa; tipografia herdada |
| Site | Erros (404 etc.) | Precisa de melhoria | Página branca genérica, fora da marca |
| Acesso | Login, cadastro, esqueci/redefinir senha, link de acesso, confirmação de senha | Precisa de melhoria | Formulário centralizado sobre creme, sem marca; igual para equipe e cliente |
| Painel | Início | **Redesenhar** | Texto de placeholder da Fase 1 ("os módulos serão construídos a partir da Fase 4") e um aviso técnico; nenhuma informação do dia |
| Painel | Navegação (barra lateral e topo) | **Redesenhar** | Ver 1.1 |
| Painel | Agenda do dia | **Redesenhar** | Um card por profissional com lista; não mostra o dia como linha do tempo, nem "agora", nem buracos; atendimento em andamento aparece como "Confirmado" |
| Painel | Agendamento, novo, remarcar | Precisa de melhoria | Formulários corretos, visual cru |
| Painel | Atendimento (comanda) | **Redesenhar** | Formulários empilhados em sete cards; total a pagar escondido no meio; "Concluir e receber" longe dos valores |
| Painel | Atendimentos (lista), novo encaixe | Precisa de melhoria | Tabela genérica |
| Painel | Caixa | Precisa de melhoria | Quatro cards de número + tabelas longas; o número que importa (dinheiro esperado) não se destaca |
| Painel | Serviços, categorias, produtos, estoque | Precisa de melhoria | Tabelas com coluna de ordem, botões "Desativar" grandes em toda linha; sem imagem do serviço; não conversam com o site |
| Painel | Profissionais, ficha | Precisa de melhoria | Lista sem rosto; ficha em cards |
| Painel | Comissões, repasses, regras, histórico | Precisa de melhoria | Quatro cards de número no topo + tabela; valores sem hierarquia |
| Painel | Cupons, vales, fidelidade, pontos | Aceitável | CRUD simples, herdam componentes |
| Painel | Assinaturas, planos, eventos | Aceitável | Idem |
| Painel | Campanhas, e-mails, avaliações, comunicação | Aceitável | Idem |
| Painel | Usuários, auditoria, minha conta, senha | Aceitável | Idem |
| Painel | Site (conteúdo e imagens) | Aceitável | Formulário longo, mas organizado |
| Painel | Configurações da agenda, folgas, bloqueios | Aceitável | Idem |
| Conta | Início | Precisa de melhoria | Responde "próximo horário" e resumo (Fase 12), mas com cards genéricos; não responde "quanto vou pagar" |
| Conta | Agendamentos, horário, remarcar, comprovantes, comprovante | Precisa de melhoria | Tabelas e listas de painel dentro de um produto de consumo |
| Conta | Benefícios, assinatura, avaliações, avisos, dados, privacidade, senha, e-mail, exclusão | Aceitável | Claros e recentes (Fase 12); herdam a tipografia e os cards |
| Componentes | Botão, campo, seleção, caixa de marcação, rádio, interruptor | Aceitável | Corretos e acessíveis; tamanho e peso genéricos |
| Componentes | Card, badge, tabela, abas, paginação | Precisa de melhoria | Card em tudo; badge com bolinha em tudo; cabeçalho de tabela pesado |
| Componentes | Modal, dropdown, alerta, estado vazio, skeleton | Precisa de melhoria | Corretos; visual genérico |
| E-mails e comprovantes | Modelos de e-mail, comprovantes para imprimir | Aceitável | Fora do escopo visual principal; herdam a marca (ver §7) |

## 2. Linguagem visual definida

Resumo, com detalhes em [redesign-visual.md](redesign-visual.md):

- **Paleta: grafite e osso, latão só onde tem função.** O latão marca a ação que converte, o item ativo, o
  "agora" da agenda e o foco. Contraste AA conferido.
- **Tipografia:** Archivo condensada (títulos e números; caixa alta no site) + Inter (interface). A Fraunces
  saiu.
- **Assinatura gráfica:**
  - a régua;
  - o índice "01 —" nos capítulos;
  - o selo da marca;
  - o letreiro de preços.

  Saíram o barber pole e os desenhos de tesoura e pente.
- **Forma:** cantos mais retos, sombra só no que flutua e menos cards.
- **Painel:** barra grafite com o menu por tarefa. Site e conta: escuros, editoriais.

## 3. Telas redesenhadas e melhorias

| Área | Tela | O que mudou |
|---|---|---|
| Site | Início | Composição nova (história em capítulos), título de impacto, letreiro de preços reais sem foto ou foto 4:5 com moldura, ficha rápida (aberto agora, endereço, nota real), serviços como linhas com preço em destaque, equipe em retratos (placa de grafite sem foto), a casa em seção clara com diferenciais numerados, avaliações com nota grande, assinatura, visite, chamada final; rodapé com a frase da casa |
| Site | Serviços, equipe, profissional, assinatura | Cabeça de página em display, mesma linha de serviço do início, perfil com retrato fixo e serviços do profissional |
| Site | Agendamento (3 etapas) | Etapas numeradas, resumo da escolha com fios, profissional com retrato, dias e horários em números condensados, "ver o próximo dia aberto" quando o dia está cheio |
| Site | Erros (403, 404, 419, 429, 500, 503) | Página na marca: número grande em contorno, título, volta ao início (sem banco, sem detalhe técnico) |
| Acesso | Login, cadastro, senha, link de acesso, confirmação (equipe e cliente) | Painel da marca (grafite, para que serve a área, régua) + formulário; no celular, faixa curta |
| Painel | Navegação | Barra grafite, menu por tarefa, topo com o dia e o menu da pessoa; busca e sino de enfeite removidos |
| Painel | Início "Hoje" (antes: texto de espera da Fase 1) | O dia em números (faixa), na cadeira agora, próximos horários, caixa com o dinheiro esperado, pendências (avaliações para revisar, estoque baixo, assinatura em atraso), por permissão |
| Painel | Agenda | Linha do tempo por profissional: altura = duração, régua de horas, linha do agora, em atendimento (grafite), a confirmar, concluído, falta, bloqueio, folga e conflito; legenda; no celular, rola de lado só dentro do quadro |
| Painel | Comanda (atendimento) | Trabalho à esquerda, conta à direita: total grande e "Concluir e receber" ao lado do valor; no celular, a conta vem primeiro |
| Painel | Atendimentos | Em andamento no mesmo bloco grafite do "Hoje"; troca de dia igual à agenda |
| Painel | Caixa, comissões | Faixa de números com o número-chave em destaque (dinheiro na gaveta, líquido a repassar) |
| Painel | Serviços | Miniatura da foto (ou marca tracejada), preço condensado, ativar/desativar discreto |
| Painel | Demais telas (cadastros, financeiro, promoções, assinaturas, comunicação, configurações, usuários, auditoria) | Herdam a linguagem: título condensado, faixa de números, tabela leve, badges, estados vazios, botão de arquivo |
| Conta | Início | Próximo horário em destaque (dia, hora grande, serviço, profissional, **valor**, situação), "Para você" (avaliar, avisos), benefícios de hoje, resumo da conta |
| Conta | Demais telas | Títulos em display, blocos na linguagem do site; cabeçalho do celular numa linha |

## 4. Testes

| Item | Resultado |
|---|---|
| PHP (PHPUnit) | **PASSOU** — 789 testes, 0 falhas, 0 pulados. Dois ajustes de teste por mudança de tela, sem mudar a regra: `StaffAgendaTest` (a agenda mostra "14:00–14:30" em vez de "até 14:30") e `ReferenceScreensTest` (a tela de login agora lê o nome e o logo da marca, então a classe usa o banco de teste) |
| PHPStan (nível 6) | **PASSOU** — 0 erros |
| Pint | **PASSOU** |
| Build | **PASSOU** |
| Playwright + axe | **PASSOU** — 125 passaram e 3 pulados, nas duas rodadas seguidas em banco novo (15,5 e 15,7 min, 3 workers). Os 3 pulados são os mesmos de antes do redesign: testes que só valem para um dos tamanhos de tela (ex.: "site no celular" no projeto desktop). Antes de chegar lá, rodadas intermediárias mostraram 2 violações de contraste intermitentes na barra grafite e um defeito real de acessibilidade: no celular, a barra lateral fechada continuava recebendo foco pelo teclado. Corrigido no CSS (barra fechada fica `visibility: hidden`; itens com fundo explícito), sem desligar regra do axe |
| Teste novo | `tests/e2e/redesign.spec.js` (celular e desktop): início com capítulos e sem barber pole, serviços, equipe, agendamento, erro 404 na marca; painel (acesso, "Hoje" sem o texto da Fase 1, agenda em quadro, 13 telas, menu por tarefa, sem a busca de enfeite); conta (acesso, início, agendamentos, comprovantes, benefícios, dados). Em cada tela: axe sem violação grave, sem rolagem lateral, sem erro de console/CSP |
| Testes ajustados (tela mudou, regra não) | `identidade.spec`: o link "Minha ficha" agora é procurado pelo seletor do menu, porque no celular o menu fechado deixou de ser acessível (era o defeito acima); `agenda.spec` e `caixa.spec` passaram sem mudança depois de a agenda voltar a ter o título "Agenda", o nome de cada profissional como título e a ordem de leitura natural nos horários |
| Desempenho | **PASSOU** — início sem fotos: ~185 KB comprimidos (antes ~157 KB; +~23 KB da Archivo com o eixo de largura e +~4 KB de CSS); meta < 1 MB. Duas famílias de fonte (igual a antes), sem JavaScript novo além de 15 linhas (faixa da conta), sem biblioteca nova |
| Lighthouse | **NÃO EXECUTADO** (ferramenta indisponível; pendência de homologação) |

## 5. Antes e depois

As capturas estão em `img/redesign/antes/` e `img/redesign/depois/`, com 144 imagens de cada lado.

- **Mesmo roteiro:** `tests/visual/capturas.spec.js`, desktop 1440 px e celular Pixel 7, página inteira, JPEG.
- **Dados:** um banco de demonstração fictício (`php artisan app:demo-data`). A agenda de hoje é montada em
  volta da hora da captura, então "antes" e "depois" mostram horários diferentes do mesmo tipo de dia.

| Área | Antes | Depois |
|---|---|---|
| Início do site | [desktop](img/redesign/antes/publico/01-inicio-desktop.jpg) · [celular](img/redesign/antes/publico/01-inicio-celular.jpg) | [desktop](img/redesign/depois/publico/01-inicio-desktop.jpg) · [celular](img/redesign/depois/publico/01-inicio-celular.jpg) |
| Serviços | [desktop](img/redesign/antes/publico/02-servicos-desktop.jpg) | [desktop](img/redesign/depois/publico/02-servicos-desktop.jpg) |
| Profissional | [desktop](img/redesign/antes/publico/04-profissional-desktop.jpg) | [desktop](img/redesign/depois/publico/04-profissional-desktop.jpg) |
| Agendamento (horário) | [celular](img/redesign/antes/publico/08-agendar-horario-celular.jpg) | [celular](img/redesign/depois/publico/08-agendar-horario-celular.jpg) |
| Erro 404 | [desktop](img/redesign/antes/publico/09-erro-404-desktop.jpg) | [desktop](img/redesign/depois/publico/09-erro-404-desktop.jpg) |
| Acesso da equipe | [desktop](img/redesign/antes/acesso/06-entrar-equipe-desktop.jpg) | [desktop](img/redesign/depois/acesso/06-entrar-equipe-desktop.jpg) |
| Painel: início | [desktop](img/redesign/antes/painel/01-inicio-desktop.jpg) · [celular](img/redesign/antes/painel/01-inicio-celular.jpg) | [desktop](img/redesign/depois/painel/01-inicio-desktop.jpg) · [celular](img/redesign/depois/painel/01-inicio-celular.jpg) |
| Agenda | [desktop](img/redesign/antes/agenda/01-agenda-dia-desktop.jpg) · [celular](img/redesign/antes/agenda/01-agenda-dia-celular.jpg) | [desktop](img/redesign/depois/agenda/01-agenda-dia-desktop.jpg) · [celular](img/redesign/depois/agenda/01-agenda-dia-celular.jpg) |
| Comanda | [desktop](img/redesign/antes/caixa/02-atendimento-desktop.jpg) | [desktop](img/redesign/depois/caixa/02-atendimento-desktop.jpg) |
| Caixa | [desktop](img/redesign/antes/caixa/04-caixa-desktop.jpg) | [desktop](img/redesign/depois/caixa/04-caixa-desktop.jpg) |
| Serviços (painel) | [desktop](img/redesign/antes/catalogo/01-servicos-desktop.jpg) | [desktop](img/redesign/depois/catalogo/01-servicos-desktop.jpg) |
| Comissões | [desktop](img/redesign/antes/financeiro/01-comissoes-desktop.jpg) | [desktop](img/redesign/depois/financeiro/01-comissoes-desktop.jpg) |
| Conta: início | [desktop](img/redesign/antes/cliente/01-inicio-desktop.jpg) · [celular](img/redesign/antes/cliente/01-inicio-celular.jpg) | [desktop](img/redesign/depois/cliente/01-inicio-desktop.jpg) · [celular](img/redesign/depois/cliente/01-inicio-celular.jpg) |

## 6. Problemas encontrados

Registrados à parte. Os funcionais foram corrigidos só na interface, sem mexer em regra de negócio:

| Problema | Tipo | O que foi feito |
|---|---|---|
| Busca e sino no topo do painel não faziam nada (controles de protótipo da Fase 1) | Funcional (interface) | Removidos. Busca real fica como decisão (R-04) |
| Início do painel mostrava texto de espera da Fase 1 ("os módulos serão construídos a partir da Fase 4") | Funcional (interface) | Substituído pelo "Hoje" |
| Benefícios da conta mostravam Blade cru ("…atendimento@if (…)") quando a indicação estava ligada | Defeito de tela (desde a Fase 8) | Corrigido |
| Agenda mostrava atendimento em andamento como "Confirmado" | Informação incompleta | A linha do tempo mostra "Em atendimento" (lê o atendimento já existente) |
| Blocos do painel e da conta (`.board`, pendências) estavam só no CSS do painel | Organização | Viraram componente compartilhado (`components/board.css`) |
| Painel e conta usavam o nome técnico do app em vez do nome configurado da barbearia | Consistência | Usam o nome da marca (`Brand`) |
| Diretiva Blade grudada numa palavra (`Agenda@if`) quebra a página | Armadilha | Corrigida; padrão anotado para a equipe |
| Quadro largo alargava a página inteira (item de grid com `min-width: auto`) | Layout | `min-width: 0` nos filhos do conteúdo do painel |
| Menu do painel no celular, fechado, só saía da tela pela posição: os links continuavam alcançáveis pelo teclado e pelo leitor de tela (desde a Fase 1) | Acessibilidade | Fechado fica oculto de verdade (`visibility: hidden` depois da animação). Apareceu porque o axe acusou, numa rodada, contraste do texto claro da barra contra o fundo claro da página; os itens da barra passaram a ter o fundo grafite neles próprios |

## 7. Não resolvido / pendências

- **Fotos e logo reais (P11-02):** o design está pronto para recebê-los (topo 4:5 com moldura, retratos 4:5,
  galeria, logo no topo, rodapé e acesso). A revisão visual com as fotos reais fica para quando chegarem.
- **Modelos de e-mail e comprovantes impressos:** mantêm o visual atual, dentro da marca. Um redesign deles
  precisa de teste em leitores de e-mail reais (homologação).
- **Páginas de referência dos protótipos** (`/prototipos`, só com a flag ligada): herdam os tokens novos, mas
  mantêm a estrutura antiga. São histórico, não produto.
- **Telas de cadastro do painel:** herdam a linguagem (título, campos, tabelas, estados), sem reestruturação
  uma a uma. A agenda, a comanda, o "Hoje" e o caixa foram as prioridades de uso.
- **Celular no painel:** os filtros da agenda e dos atendimentos ainda ocupam a primeira tela. Um filtro
  recolhível fica como melhoria.
- **Lighthouse e teste com usuários reais:** homologação.

## 8. Dependências

- **Adicionada:** `@fontsource-variable/archivo` 5.3.0 (OFL, auto-hospedada). Justificativa: a família de
  títulos e números da nova linguagem, com o eixo de largura condensada.
- **Removida:** `@fontsource-variable/fraunces`.
- **Nenhuma biblioteca de JavaScript nova.** `npm audit`: 0 vulnerabilidades.

## 9. Segurança, permissões e dados

- **Regras de negócio, permissões, autenticação, LGPD, cálculos, importador e modelo de dados:** nada mudou.
- **Início "Hoje" e agenda:** só leitura, com as mesmas permissões das telas de origem.
  - Agenda e atendimentos: todos ou só os próprios.
  - Caixa: `cash.view`.
  - Pendências: `reviews.moderate`, `products.view`, `subscriptions.view`.
- **Posições da agenda:** num `<style>` com nonce, porque a CSP continua sem `style=""`.
- **`app:demo-data`:**
  - só roda em local/testing;
  - só roda em banco sem agendamentos;
  - usa os serviços do domínio;
  - todos os dados são fictícios e o texto do site vem marcado como "dados fictícios de demonstração";
  - a senha é aleatória, ou informada só no comando.

## PASSOU

Testes PHP, PHPStan, Pint, build, testes de navegador (celular e desktop, com o teste novo do redesign),
acessibilidade (axe em todas as telas testadas), rolagem lateral (nenhuma), desempenho (dentro da meta),
revisão de segurança e permissões (nada mudou), capturas antes/depois, documentação.

## NÃO EXECUTADO

Lighthouse; teste com usuários reais; revisão visual com fotos e logo reais (ainda não fornecidos).

## PENDENTE

Avaliação visual e aprovação do dono (pedido do briefing); pendências de homologação da Fase 11/12 continuam
(Resend real, aprovação dos e-mails, Stripe em modo teste, webhook real, usuários reais, Lighthouse).

## FALHOU

Nada na entrega final.

Durante o trabalho falharam e foram corrigidos (ver §6):
- a agenda (título, nome dos profissionais, ordem de leitura) nos testes antigos de navegador;
- o login no teste de protótipos sem banco;
- a variável de nulo no "Hoje", apontada pelo PHPStan.
- o contraste da barra grafite (intermitente no axe) e a barra lateral fechada que ainda recebia foco no celular.

Uma rodada intermediária de navegador rodou com o build trocado no meio e foi descartada; as rodadas que
valem estão em §4.

## DECISÕES NECESSÁRIAS

| ID | Ponto | Recomendação |
|---|---|---|
| R-01 | Aprovar a direção: Archivo condensada + latão restrito + painel com barra grafite | Aprovar (é a base de todo o resto) |
| R-02 | Frases dos painéis de acesso ("Agenda, comanda e caixa do dia." / "Horários, comprovantes e benefícios.") e do topo do rodapé (usa a frase de marca do painel) | Manter (são textos de interface, não dados da barbearia); trocar se o dono preferir outro tom |
| R-03 | Indicadores financeiros no início do painel (faturamento do dia, ticket médio) | Não agora: dependem da parte de relatórios do roadmap ainda não aprovada |
| R-04 | Busca global no painel (clientes, agendamentos) | Fazer numa fase futura, de verdade (a de enfeite saiu) |
| R-05 | Letreiro de preços no topo quando não há foto (até 5 serviços em destaque) | Manter até a foto real chegar; com foto, o topo mostra a foto |
