# Relatório da Fase 6 — Atendimento, caixa, produtos e estoque

> **Status: encerrada e aprovada pelo dono em 2026-10-01.** D-29 a D-33 decididos; a correção exigida antes
> da Fase 7 (encaixe ocupa a agenda, §15) foi aprovada; início da Fase 7 autorizado.
> Branch `claude/fase-6-atendimento-caixa`, criada a partir de `claude/fase-5-agenda` (as Fases 3 a 5 ainda não
> estão na `main`). Só dados fictícios; nenhum banco de produção acessado; nenhuma migração real executada;
> sistema antigo não alterado; nenhum segredo no repositório.
>
> Legenda: **PASSOU** · **NÃO EXECUTADO** · **PENDENTE** · **FALHOU**.

## 1. Critérios de aceite (item 45 do briefing)

| Critério | Situação | Evidência |
|---|---|---|
| Atendimento implementado | PASSOU | `attendances` + `AttendanceService`; telas de lista, encaixe e atendimento |
| Relação com agendamento definida | PASSOU | abrir do agendamento (pendente/confirmado, do dia) ou encaixe (que também é agendamento e ocupa a agenda, §15); um em vigor por agendamento; conclusão conclui a reserva ([atendimento.md §2](atendimento.md#2-relação-com-o-agendamento)) |
| Snapshot definido | PASSOU | tabela histórico × referência em [atendimento.md §3](atendimento.md#3-snapshot-histórico--referência) |
| Preço preservado | PASSOU | `test_preco_profissional_e_cliente_ficam_congelados_depois_de_concluir`, `test_abre_do_agendamento_com_o_preco_combinado...` |
| Descontos centralizados | PASSOU | `Discount` + `PriceBreakdown` (o agendamento passou a usar o mesmo cálculo); `DiscountTest` (19) |
| Estados definidos | PASSOU | `AttendanceStatus` (aberto, em atendimento, concluído, cancelado); `test_estados_e_transicoes` |
| Conclusão transacional | PASSOU | `test_falha_no_meio_desfaz_pagamento_caixa_estoque_e_agendamento`, `test_estoque_insuficiente_desfaz_a_conclusao_inteira` |
| Formas de pagamento | PASSOU | dinheiro, Pix, débito, crédito, outro; pagamento dividido e gorjeta |
| Valor validado | PASSOU | soma exata com o total (`test_pagamentos_precisam_fechar_exatamente_com_o_total`, HTTP com valor adulterado) |
| Estorno preserva histórico | PASSOU | `CorrectionsTest` (6): original intacto, estorno aponta, saída no caixa |
| Duplicidade impedida | PASSOU | chaves únicas; duplo clique (serviço e HTTP); concorrência real com 4 processos |
| Caixa: abertura, movimentação, fechamento, diferenças, auditoria | PASSOU | `CashRegisterTest` (12), `CashAndStockPanelTest`, E2E |
| Produtos: cadastro, ativação/desativação, histórico | PASSOU | `ProductAdmin`, `CashAndStockPanelTest`, E2E |
| Estoque: entradas, saídas, consumo, ajustes, histórico, saldo consistente | PASSOU | `StockLedgerTest` (10), concorrência real (saldos 3, 2, 1, 0), E2E |
| Permissões / IDOR / concorrência / idempotência / auditoria | PASSOU | 20 habilidades, `AttendancePolicy`, `CheckoutPanelTest` (19), `CheckoutConcurrencyTest` (3) |
| Testes passando / PHPStan sem erros / build | PASSOU | §8 |
| CI verde | PASSOU | run nº 26, os dois jobs (§9) |
| Nenhum secret / nenhum dado real | PASSOU | contas de teste com senha aleatória; só dados fictícios |
| Desktop, celular, acessibilidade, sem rolagem horizontal | PASSOU | axe sem violação séria/crítica em 10 telas novas, nos dois tamanhos; teclado nos modais |
| Documentação, roadmap, relatório | PASSOU | 5 documentos novos + atualizações (§12) |
| Simulação de um dia inteiro em homologação (roadmap) | NÃO EXECUTADO | não há ambiente de homologação (D-01) |
| Comissão gravada no fechamento (roadmap) | NÃO EXECUTADO | o briefing da Fase 6 excluiu comissão; vai para a Fase 7 |

## 2. Arquitetura

```text
Agendamento (reserva)              Atendimento (fato)                       Efeitos (na conclusão, uma transação)
appointments ──"cliente chegou"──▶ attendances ──────────── concluir ──┬──▶ payments ──▶ cash_movements (caixa aberto)
appointment_items                  attendance_items (vendido)          ├──▶ stock_movements (venda e consumo)
(preço combinado)                  attendance_consumptions (usado)     └──▶ appointments.status = concluído
                                   attendance_discounts / _events
encaixe ──▶ appointments (origem walk_in, mesma agenda) ──▶ attendances   (correção, §15)
```

| Peça | Arquivo |
|---|---|
| Regras do atendimento (abrir, iniciar, itens, consumo, desconto, profissional, cancelar, concluir) | `app/Modules/Checkout/Services/AttendanceService.php` |
| Estorno e devolução ao estoque | `app/Modules/Checkout/Services/AttendanceCorrections.php` |
| Totais e descontos (um cálculo para agendamento e atendimento) | `app/Modules/Shared/Pricing/{Discount,PriceBreakdown}.php`, `AttendancePricing`, `AppointmentPricing` |
| Caixa | `app/Modules/Finance/Services/CashRegister.php`, `Models/{CashSession,CashMovement}.php` |
| Estoque | `app/Modules/Catalog/Services/StockLedger.php` |
| Produtos | `app/Modules/Catalog/Services/ProductAdmin.php` |
| Autorização por registro | `app/Modules/Checkout/Policies/AttendancePolicy.php` |
| Telas | `Panel/Checkout/{AttendanceController,CashController}`, `Panel/Catalog/{ProductController,StockController}`, `Account/AppointmentController@receipt` |

**Modelo** (migration `2026_10_02_000100_create_checkout_tables`, sem editar as anteriores): 7 tabelas novas
(`attendances`, `attendance_items`, `attendance_consumptions`, `attendance_discounts`, `attendance_events`,
`cash_sessions`, `cash_movements`) e colunas novas em `payments`, `stock_movements` e `products`.

**O modelo existente foi validado contra as regras e corrigido** (o briefing pediu para não assumir que estava
certo):

- `payments.appointment_id` → `payments.attendance_id`: o pagamento é do **atendimento**, não da reserva.
- `stock_movements.appointment_id` → `attendance_id`, mais origem do estorno, saldo depois, quem lançou e chave.
- `products.price_cents` passou a aceitar nulo (insumo não vendido).
- O **importador** passou a criar o atendimento (`source = legacy`) dos agendamentos concluídos do sistema
  antigo, com itens, desconto e gorjeta, e a apontar o pagamento e as vendas de estoque para ele. Os 23 testes
  do importador continuam passando, com asserções novas.

## 3. Estratégias (resumo; detalhes nos documentos)

- **Snapshots, preço e descontos:** [atendimento.md §3](atendimento.md), [pagamentos.md §4](pagamentos.md).
  O preço do item nunca é editado: reduzir = desconto (permissão + motivo), cobrar mais = outro item.
- **Arredondamento:** percentual meio centavo para cima, uma vez sobre o total dos serviços, descontos em
  ordem ([pagamentos.md §5](pagamentos.md#5-arredondamento)).
- **Pagamentos:** dividido, gorjeta por forma, soma exata, sem "pagar depois", estorno como registro novo.
- **Caixa:** um aberto por barbearia; esperado em dinheiro = inicial + movimentos em dinheiro; diferença
  registrada e justificada ([caixa.md](caixa.md)).
- **Estoque:** razão; nunca negativo por lançamento novo; venda e consumo só na conclusão; estorno uma vez
  ([estoque.md](estoque.md)).
- **Transações e travas:** atendimento → caixa → produtos (id crescente); trava = primeira escrita na linha
  (`version`/`stock_version`), o mesmo padrão da agenda.
- **Idempotência:** `completion_key`, `request_key` (únicos) e a sentinela `active_appointment_id`.

## 4. Decisões

| # | Decisão | Quem / motivo |
|---|---|---|
| D-25 | Um caixa aberto por vez na barbearia | **Dono** (perguntado no início da fase; o sistema antigo não tinha caixa) |
| D-26 | Encaixe permitido, com cliente cadastrado ou só nome e telefone | **Dono** |
| D-27 | Produtos no atendimento: venda (cobrada) e consumo (não cobrado), ambos baixam o estoque | **Dono** |
| D-28 | Não existe "pagar depois": só se conclui pago | **Dono** |
| T6-01 | Atendimento em tabela própria; pagamento e estoque apontam o atendimento | Briefing (não misturar fatos) |
| T6-02 | Abrir só agendamento pendente/confirmado **do dia**; pendente é confirmado ao abrir | Atendimento é presença; falta e cancelado não viram atendimento |
| T6-03 | Item do agendamento mantém o preço combinado; item incluído usa o catálogo do momento | Orçamento = cobrado (critério do roadmap) |
| T6-04 | Concluir exige "iniciar" antes | Estados claros, sem pular etapa |
| T6-05 | Um desconto manual por atendimento (aplicar de novo substitui) | Precedência de descontos é da Fase 8 |
| T6-06 | Gorjeta por forma de pagamento, à parte do total, entra no caixa | Sistema antigo tinha gorjeta; repasse na Fase 7 |
| T6-07 | Pagamento exige caixa aberto (total zero dispensa) | Toda entrada tem origem e caixa |
| T6-08 | Estorno sai do caixa **aberto** (não do caixa original, que pode estar fechado) | Caixa fechado não muda |
| T6-09 | Cancelar o atendimento não muda o agendamento | A equipe decide se foi falta ou cancelamento |
| T6-10 | Produto: exclusão física só sem histórico; categoria sem tela | Briefing (só o necessário) |
| T6-11 | Venda/consumo de atendimento é devolvida **pelo atendimento**, não pela tela de estoque | Fica no histórico do atendimento |
| T6-12 | Comprovante do cliente = tela na conta (sem impressão) | Base para a Fase 11 |

**Decididas pelo dono na aprovação (2026-09-30):**

| # | Decisão |
|---|---|
| D-29 | **Desconto:** mantida a regra. Proprietário e gerente aplicam; recepção **não**; motivo obrigatório; auditado. Sem permissão genérica que deixe a recepção contornar a regra. |
| D-30 | **Estorno:** mantida a regra. Proprietário e financeiro estornam; gerente, recepção e profissional **não**. Estorno continua sendo movimentação nova, sem apagar nem editar o pagamento original. |
| D-31 | **Gorjeta:** repasse ao profissional na Fase 7, junto de comissão e repasses, **com os conceitos separados**: gorjeta é o valor que o cliente destina ao profissional; comissão é a remuneração calculada por uma regra de comissão. Gorjeta não é tratada como comissão. |
| D-33 | **Serviço adicional no atendimento não estende a agenda** automaticamente (§15.6). |
| D-32 | **Encaixe ocupa a agenda** como um agendamento normal, com a mesma infraestrutura de disponibilidade e conflito da Fase 5; origem diferente, mesma ocupação (§15). |

## 5. Permissões

20 habilidades novas, substituindo `checkout.operate` (declarada na Fase 3, sem tela). Matriz em
[papeis-permissoes.md](papeis-permissoes.md).

| Papel | Atendimento | Caixa | Produtos e estoque |
|---|---|---|---|
| Proprietário | tudo, inclusive desconto e estorno | tudo | tudo |
| Gerente | tudo, inclusive desconto; **sem** estorno | tudo | tudo |
| Recepção | abrir, editar, concluir, cancelar; **sem** desconto e estorno | abrir, suprimento, sangria, fechar | consulta; entrada e saída, **sem** ajuste |
| Financeiro | consulta; estorno | consulta | consulta |
| Profissional | só os próprios: abrir (como ele mesmo), editar, concluir com pagamento | **não** acessa | **não** acessa |
| Cliente | comprovante dos próprios concluídos (alheio = 404) | — | — |

Três camadas: rota (habilidade ou policy), policy do registro (alheio = 404) e serviço (estado e valores).
Rotas de dinheiro e estoque com limite `throttle:money` (60/min por conta + IP).

## 6. Auditoria

- `attendance_events`: linha do tempo completa do atendimento (inclusive desconto com antes/desconto/depois e
  motivo, pagamentos, estorno, devolução).
- `Auditable` (antes/depois): atendimento, itens, consumo, descontos, produto, sessão de caixa.
- `AuditTrail`: `cash.opened`, `cash.supply`, `cash.withdrawal`, `cash.closed`, `payment.refunded`, e todo
  lançamento manual de estoque (`stock.purchase`, `stock.usage`, `stock.loss`, `stock.adjustment`,
  `stock.reversal`) com quantidade, saldo depois e motivo.
- Razões só de inclusão: `payments`, `cash_movements`, `stock_movements`, `attendance_events`.
- Verificador de integridade: 8 regras novas (R25 a R32), conferidas também no fluxo completo e no estorno.

## 7. Concorrência

`CheckoutConcurrencyTest`, com **processos PHP reais** num SQLite em arquivo, cada um segurando a transação
aberta na janela de corrida:

| Cenário | Resultado |
|---|---|
| 4 usuários concluindo o mesmo atendimento (chaves diferentes) | 1 conclui; 3 recebem "não pode mais ser alterado"; 1 pagamento, 1 entrada no caixa, 1 baixa |
| 5 saídas de 1 unidade num produto com 3 | 3 conseguem, 2 "estoque insuficiente"; saldo 0; saldos gravados 3, 2, 1, 0 |
| 3 fechamentos simultâneos do mesmo caixa | 1 fecha, 2 "caixa já fechado" |

Também: duas aberturas de caixa (índice único), dois cliques em "abrir atendimento" (sentinela única), edição
simultânea de produto (`lock_version`).

## 8. Testes

| Verificação | Resultado |
|---|---|
| PHPUnit | **PASSOU** — 492 testes (eram 382), 0 falhas, 0 pulados |
| — novos na Fase 6 | `Unit/DiscountTest` (19), `Checkout/AttendanceServiceTest` (25), `CheckoutPanelTest` (19), `CashAndStockPanelTest` (16), `CashRegisterTest` (12), `StockLedgerTest` (10), `CorrectionsTest` (6), `CheckoutConcurrencyTest` (3) |
| — ajustados (regra mudou, nenhum desativado) | `PermissionMatrixTest` (matriz nova); `RouteAuthorizationTest` (rotas novas, `can:metodo,Classe`, limites `throttle:money`); `SchemaTest` (FKs novas); `DomainRulesTest` e `HistoryAndMoneyTest` (pagamento aponta o atendimento; API nova do estoque); `ImportScenariosTest` (pagamento do legado aponta o atendimento criado; agendamento não concluído continua sem atendimento) |
| Larastan nível 6 | **PASSOU** — 0 erros |
| Pint | **PASSOU** |
| Build (Vite) | **PASSOU** |
| Importador com banco fictício | **PASSOU** (testes do importador; no CI, o passo próprio) |
| Playwright + axe | **PASSOU** — 85 passando, 3 ignorados de propósito (os mesmos das fases anteriores), em **duas execuções seguidas** |
| — novos na Fase 6 | `caixa.spec.js` (celular e desktop): atendimento do agendamento de hoje (iniciar, vender produto, material, desconto com erro de validação, modal com Esc e foco, valor errado recusado, pagamento dividido, histórico); caixa (abrir, suprimento, sangria com erro, fechar com justificativa; no celular, o modal é cancelado pelo teclado); estoque (cadastrar, entrada, saída acima do saldo recusada, perda, ajuste, estorno com confirmação). Axe e rolagem lateral em 10 telas |
| Contraprova do teste de concorrência (proteções desligadas) | **NÃO EXECUTADO** — no SQLite a transação `IMMEDIATE` já serializa as escritas; a trava de linha é o que protege no MySQL, que não foi testado (D-02) |

## 9. CI

**PASSOU.** Run nº 26 (commit `f15018b`,
https://github.com/Joaogoncalves19/barbearia/actions/runs/36795640201), PHP 8.4, os dois jobs verdes em todos
os passos:

- **Novo sistema (Laravel):** dependências, Pint, Larastan nível 6, auditoria de dependências, build, testes
  PHP (inclusive os de concorrência com processos reais), importador com banco fictício (simulação,
  importação e reexecução, já criando os atendimentos do legado) e Playwright + axe.
- **Sistema atual:** regressão de segurança S-01 a S-04.

O commit seguinte só atualiza este relatório (documentação).

## 10. Problemas encontrados

1. **Leitura de status com cast:** `value('status')` devolve o enum, não o texto; a trava das linhas do
   atendimento quebrava. Corrigido lendo o valor cru. Pego pelos testes de domínio.
2. **Pagamento e estoque presos ao agendamento** no modelo da Fase 2. Corrigido no modelo e no importador (§2).
3. **Validação do navegador escondia a mensagem do servidor** (desconto sem motivo): formulários com
   `novalidate`, como os das fases anteriores. Pego pelo E2E.
4. **Erro de campo dentro de modal fechado:** depois do envio, a página recarregava com o modal fechado e a
   mensagem invisível; nos dois modais do caixa a mesma mensagem aparecia duas vezes. Agora o modal enviado
   **reabre sozinho** com a mensagem ao lado do campo (`data-dialog-autoopen`), e o erro aparece só nele.
   Pego pelo E2E.
5. **E2E com um caixa só para dois projetos em paralelo:** só o desktop fecha (e reabre) o caixa; quem conclui
   e encontra o caixa fechado abre e tenta de novo.
6. **Sobras de execuções interrompidas** (agendamento de hoje com atendimento aberto): as contas de teste
   cancelam as sobras antes de criar a chegada do dia.
7. **Limite de login** compartilhado com a spec do catálogo: conta própria para a Fase 6.
8. **Timeout de um teste da Fase 4 sob carga:** 6 navegadores disputando o servidor embutido do PHP (uma
   requisição por vez). Workers locais limitados a 4; o teste passa isolado e na suíte. Nenhum teste
   desativado ou com tempo aumentado.
9. **Banco local de desenvolvimento** precisou da migration nova para o E2E (dados fictícios).

## 11. Pendências

1. ~~D-29 a D-33~~ decididos (§4, §15.6).
2. **Comissão** (cálculo e gravação no fechamento): Fase 7, a partir do **atendimento** (valores congelados).
3. **Rateio do desconto por item/profissional:** necessário para comissão; recomendação em
   [pagamentos.md §5](pagamentos.md#5-arredondamento).
4. **Impressão do comprovante e do fechamento de caixa:** Fase 11 (ou 7, se o dono pedir).
5. **Despesa paga com dinheiro do caixa:** hoje é sangria com motivo; o lançamento como despesa é da Fase 7.
6. **Alertas de estoque mínimo:** a base existe (situação e filtro); notificação fica para depois.
7. **Concorrência no MySQL** e **simulação de um dia inteiro:** dependem de D-01/D-02 e de homologação.

## 12. Documentação

Novos: [atendimento.md](atendimento.md), [pagamentos.md](pagamentos.md), [caixa.md](caixa.md),
[produtos.md](produtos.md), [estoque.md](estoque.md). Atualizados: [roadmap.md](roadmap.md),
[README.md](README.md), [modelo-dados.md](modelo-dados.md) (seções 1.1, 1.2, 2.4, 2.5a, 2.6 e apêndice com 58
tabelas), [regras-dados.md](regras-dados.md) (regras 52 a 63), [papeis-permissoes.md](papeis-permissoes.md),
[decisoes-pendentes.md](decisoes-pendentes.md), [mapa-banco-antigo-novo.md](mapa-banco-antigo-novo.md).

## 13. Riscos

| Risco | Mitigação |
|---|---|
| ~~Encaixe não reserva horário: alguém agenda online por cima~~ | **Resolvido** (§15): o encaixe é agendamento de origem `walk_in` e ocupa a agenda |
| Serviço incluído no atendimento não estica o horário na agenda | **Decidido (D-33):** é a regra. A reserva ocupa a duração do que foi reservado; extensão do horário, se um dia existir, passa pela regra única da agenda (§15.6) |
| Caixa esquecido aberto de um dia para o outro | O caixa mostra desde quando está aberto; relatório diário na Fase 7 deve olhar a sessão, não a data |
| SQLite × MySQL | Trava por escrita na linha funciona nos dois; repetir o teste de concorrência no banco escolhido (D-02) |
| Matriz de permissões proposta pela equipe técnica | Conservadora; dono revisa D-29/D-30 |
| Atendimentos legados sem profissional (`professional_id` nulo) | Só o importador grava assim; o serviço sempre exige profissional |
| Servidor embutido do PHP lento nos testes de navegador | Workers limitados; em homologação/produção usar servidor web real |

## 14. Recomendações para a Fase 7 (financeiro e relatórios)

1. **Calcular comissão a partir de `attendances` concluídos** (totais congelados, profissional que
   efetivamente atendeu), nunca do agendamento nem do preço atual; gravar em `commission_entries` apontando
   o atendimento (hoje a coluna aponta o agendamento: migrar como foi feito com `payments`).
2. **Ratear o desconto por item** com o método do maior resto, num lugar só (junto do `PriceBreakdown`).
3. **Estorno gera estorno de comissão** (lançamento negativo), sem editar o lançamento original.
4. **Relatórios por `attendances.completed_at`** e por sessão de caixa; receita = pagamentos − estornos.
5. **Repasse de gorjeta** (D-31) junto com o pagamento de comissões.
6. Decidir D-02 (banco) antes de relatórios pesados.

## 15. Correção antes da Fase 7: o encaixe ocupa a agenda

**Problema** (risco do §13, que o dono considerou inaceitável): o encaixe criava só o atendimento. Às 14:00 a
recepção encaixava João (14:00–14:40) e, ao mesmo tempo, um cliente via 14:00 livre no site e reservava.

**Regra pedida:** o encaixe ocupa a agenda como um agendamento normal (profissional, duração, serviço,
conflito, expediente, folgas, bloqueios, demais regras da Fase 5), **sem** segunda lógica de conflito e
**sem** nova modalidade de agenda: um agendamento com origem diferente.

### 15.1 O que mudou

```text
Agendamento (appointments)                          Atendimento (attendances)
├── origem: online   (site)
├── origem: staff    (equipe, pela agenda)
└── origem: walk_in  (encaixe)  ──mesma transação──▶ source = walk_in, appointment_id = o encaixe
```

| Peça | Mudança |
|---|---|
| `AppointmentSource::WalkIn` (`walk_in`, "Encaixe") | Origem nova. A coluna já era texto: sem migration |
| `AttendanceService::openWalkIn` | Em vez de gravar só o atendimento, chama **`BookingService::book`** (canal equipe, origem encaixe, início `BusinessTime::nextStart()` = próximo ponto da grade de 5 min, duração do serviço) e cria o atendimento a partir desse agendamento **na mesma transação** (`createFromAppointment`, o mesmo caminho do "cliente chegou"). A checagem própria de profissional/serviço que o encaixe tinha foi **removida**: quem decide é a `Availability` |
| `AttendanceService::changeProfessional` | Trocar quem atende **remarca a agenda** pelo `BookingService::reschedule` (mesma regra), no horário combinado ou, se já passou, a partir do próximo ponto da grade. Profissional ocupado: recusado, nada muda. Antes, a agenda ficava presa no profissional antigo e a do novo ficava livre (furo do mesmo tipo, achado na revisão) |
| `AttendanceService::cancel` | Cancelar um **encaixe** cancela o agendamento de encaixe e libera o horário. Vindo de agendamento, nada muda (T6-09) |
| `BookingService::cancel/reschedule/markNoShow` | Com atendimento em vigor, recusa (`in_attendance`): a agenda não libera o horário com o cliente na cadeira (outro furo do mesmo tipo, achado na revisão). O atendimento remarca com `byAttendance: true` |
| `IntegrityChecker` R33 | Atendimento de encaixe sem agendamento `walk_in`, ou agendamento `walk_in` sem atendimento = violação |
| Tela de encaixe | O texto explica que o encaixe entra na agenda; a recusa mostra o motivo e o **próximo horário livre** do profissional hoje |

### 15.2 Revisão: nenhuma regra de disponibilidade duplicada

- O encaixe e a troca de profissional passam por `BookingService::book`/`reschedule`, que chamam
  `Availability::check` dentro da transação, com a agenda do profissional travada. Nenhum código novo compara
  intervalos, expediente, folga, pausa ou bloqueio.
- Busca no código por comparação de intervalos (`starts_at <`, `overlaps`, `blockingSlot`) fora da
  `Availability`: só os usos que já existiam (lista da agenda, cliente em dois lugares, aviso de bloqueio).
- A única regra própria do encaixe é **quando** ele começa (`BusinessTime::nextStart`); se esse horário pode
  ser ocupado, quem julga é a regra única.

### 15.3 Testes

`WalkInAgendaTest` (16) e `BookingConcurrencyTest::test_encaixe_e_site_disputando_o_mesmo_horario`:

| # | Pedido | Teste | Resultado |
|---|---|---|---|
| 1 | Encaixe em horário livre | `test_1_encaixe_em_horario_livre_ocupa_a_agenda` (10:02 vira 10:05–10:35; a mesma regra passa a ver o horário ocupado) | PASSOU |
| 2 | Impedir encaixe sobre outro agendamento | `test_2_encaixe_sobre_outro_agendamento_e_recusado` | PASSOU |
| 3 | Impedir online sobre encaixe | `test_3_agendamento_online_sobre_encaixe_e_recusado` (10:15 e 10:30 recusados; 10:45 aceito) | PASSOU |
| 4 | Impedir encaixe sobre online | `test_4_encaixe_sobre_agendamento_online_e_recusado` | PASSOU |
| 5 | Duração do serviço | `test_5_respeita_a_duracao_do_servico` (60 min invade o próximo; 30 min cabe; atravessar o fechamento é recusado) | PASSOU |
| 6 | Profissional | `test_6_respeita_o_profissional` (agenda de um não afeta a do outro; não executa o serviço; inativo) | PASSOU |
| 7 | Expediente | `test_7_respeita_o_expediente` (antes de abrir; fora do expediente do profissional; dentro) | PASSOU |
| 8 | Bloqueios (e pausas) | `test_8_respeita_bloqueios_e_pausas` (bloqueio do profissional, da barbearia inteira, pausa) | PASSOU |
| 9 | Folgas | `test_9_respeita_folgas` | PASSOU |
| 10 | Duplicidade em concorrência | `test_encaixe_e_site_disputando_o_mesmo_horario`: 6 processos PHP reais (3 encaixes e 3 reservas pelo site, horários sobrepostos, relógio parado em 10:02:20) → 1 vence, 5 "conflito", 1 agendamento, atendimento só se o encaixe venceu, verificador limpo | PASSOU |
| — | Encaixe criado → cliente tenta o mesmo horário → servidor recusa | `test_encaixe_criado_cliente_tenta_reservar_o_mesmo_horario_e_o_servidor_recusa` (HTTP, requisição montada à mão) | PASSOU |
| — | Online criado → recepção tenta encaixe → servidor recusa | `test_agendamento_online_criado_recepcao_tenta_encaixe_e_o_servidor_recusa` (HTTP; mensagem com o próximo horário livre, 10:45) | PASSOU |
| — | Cancelar encaixe libera o horário | `test_cancelar_o_encaixe_libera_o_horario` | PASSOU |
| — | Agenda não libera horário com cliente em atendimento | `test_agenda_nao_libera_o_horario_com_o_cliente_em_atendimento` (cancelar, falta, remarcar; serviço e HTTP) | PASSOU |
| — | Trocar profissional move a agenda ou é recusado | `test_trocar_o_profissional_move_a_agenda_ou_e_recusado` | PASSOU |
| — | Mesmo cliente em dois lugares | `test_o_mesmo_cliente_nao_fica_em_dois_lugares` | PASSOU |
| — | Integridade | `test_integridade_encaixe_sempre_na_agenda` (R33 limpo; encaixe solto é acusado) | PASSOU |
| — | Navegador | `caixa.spec.js` › "encaixe: a agenda recusa quando o profissional está ocupado" (celular e desktop; recusa do servidor, motivo na tela, o que foi digitado não se perde, axe) | PASSOU |

**Ajustados (a regra mudou; nenhum desativado):** os testes antigos de encaixe abriam às 08:00, antes de a
barbearia abrir; agora isso é recusado (correto), então o relógio desses testes passou para 09:02.
`test_registra_o_profissional_que_efetivamente_atendeu` dizia que a reserva continuava com o profissional
antigo; agora confere que a agenda acompanha e que o histórico registra a troca. `CheckoutConcurrencyTest`
abre o encaixe com a barbearia funcionando.

**Contraprova da concorrência:** executada desta vez (trava da agenda comentada temporariamente e depois
restaurada). O teste **continua passando**, porque no SQLite a transação `IMMEDIATE` já serializa as
escritas. A trava de linha é o que protege no MySQL; lá a contraprova faz sentido, mas está **NÃO EXECUTADO**
(D-02).

### 15.4 Validação

| Verificação | Resultado |
|---|---|
| PHPUnit | **PASSOU** — 509 testes (eram 492), 0 falhas, 0 pulados |
| Larastan nível 6 | **PASSOU** — 0 erros |
| Pint | **PASSOU** |
| Build (Vite) | **PASSOU** |
| Playwright + axe | **PASSOU** — 87 passando (eram 85; +2 do encaixe, celular e desktop), 3 ignorados de propósito, em **duas execuções seguidas** num banco SQLite novo, como no CI. Antes delas, duas rodadas anteriores tiveram falhas de tempo esgotado sob carga (2 e 4 testes, de agenda, atendimento e o novo do encaixe), todas passando ao repetir; nenhum teste desativado nem com tempo aumentado. O banco local de desenvolvimento acumulou 129 categorias de execuções anteriores e deixava a tela de categorias lenta para o axe: por isso as rodadas usam banco novo |
| CI | **PASSOU** — run nº 28 (commit `6ed03c4`, https://github.com/Joaogoncalves19/barbearia/actions/runs/36888097964), os dois jobs verdes em todos os passos (Pint, Larastan, auditoria, build, testes PHP com concorrência real, importador fictício, Playwright + axe; regressão S-01 a S-04 do sistema atual) |
| Nenhuma regra de disponibilidade duplicada | **PASSOU** (§15.2) |

### 15.5 Documentação

[atendimento.md §2, §7 e §9](atendimento.md#2-relação-com-o-agendamento), [agendamento.md §2](agendamento.md#2-canais)
(origens), [disponibilidade.md §1](disponibilidade.md#1-uma-regra-só), [regras-dados.md](regras-dados.md)
(regra 64), [modelo-dados.md](modelo-dados.md) (`appointments.source`, `attendances.appointment_id`),
[decisoes-pendentes.md](decisoes-pendentes.md) (D-29 a D-33), [roadmap.md](roadmap.md), [README.md](README.md).

### 15.6 D-33: serviço adicional durante o atendimento

**Decisão do dono (2026-10-01):** não estender automaticamente o horário ocupado na agenda. Regra mantida:

- um serviço adicional pode ser incluído durante o atendimento;
- o valor do atendimento é recalculado normalmente;
- a duração originalmente reservada não muda automaticamente;
- o horário ocupado continua o definido pelo agendamento ou encaixe.

Não implementado: extensão automática. Se um dia existir uma função para estender o horário, ela deverá
(1) verificar a disponibilidade pela mesma regra única da agenda, (2) considerar o profissional, (3) verificar
conflito com o próximo compromisso, (4) só alterar o horário se houver disponibilidade e (5) registrar a
alteração no histórico e na auditoria.

---

**Fase 6 encerrada e aprovada (2026-10-01). Fase 7 autorizada.**
