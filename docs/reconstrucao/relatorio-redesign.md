# Relatório — Redesign visual (fase extraordinária)

> Fase extraordinária entre a Fase 12 e a Fase 13, só de interface: direção de arte, composição, hierarquia,
> tipografia, navegação, componentes, estados e experiência. Nenhuma regra de negócio, permissão, cálculo,
> modelo de dados ou fluxo de LGPD foi mudado (ver §6). Branch `claude/redesign-visual`, a partir de
> `claude/fase-12-area-cliente`. Linguagem final em [redesign-visual.md](redesign-visual.md).
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

AUDITORIA_RESTO
