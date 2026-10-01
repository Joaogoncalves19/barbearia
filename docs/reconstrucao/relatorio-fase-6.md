# Relatório da Fase 6 — Atendimento, caixa, produtos e estoque

> **Status: concluída em 2026-09-30, aguardando aprovação explícita do dono para iniciar a Fase 7.**
> Branch `claude/fase-6-atendimento-caixa`, criada a partir de `claude/fase-5-agenda` (as Fases 3 a 5 ainda não
> estão na `main`). Só dados fictícios; nenhum banco de produção acessado; nenhuma migração real executada;
> sistema antigo não alterado; nenhum segredo no repositório.
>
> Legenda: **PASSOU** · **NÃO EXECUTADO** · **PENDENTE** · **FALHOU**.

## 1. Critérios de aceite (item 45 do briefing)

| Critério | Situação | Evidência |
|---|---|---|
| Atendimento implementado | PASSOU | `attendances` + `AttendanceService`; telas de lista, encaixe e atendimento |
| Relação com agendamento definida | PASSOU | abrir do agendamento (pendente/confirmado, do dia) ou encaixe; um em vigor por agendamento; conclusão conclui a reserva ([atendimento.md §2](atendimento.md#2-relação-com-o-agendamento)) |
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
encaixe (sem agendamento) ───────▶ attendances
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

**PRECISA DE DECISÃO (não bloqueou; implementado na forma mais conservadora, muda numa linha de configuração):**

- **D-29 — desconto manual:** hoje só proprietário e gerente, sem limite além do valor dos serviços, com motivo
  obrigatório. A recepção deve dar desconto? Deve haver limite por papel (ex.: até 10% sem gerente)?
- **D-30 — estorno:** hoje só proprietário e financeiro (estorno é lançamento financeiro, e o gerente já não
  lançava finanças na Fase 3). O gerente deve estornar?
- **D-31 — gorjeta:** registrada por pagamento; o repasse ao profissional (com a comissão) fica para a Fase 7.

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

1. **D-29, D-30, D-31** (§4).
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
| Encaixe não reserva horário: alguém agenda online por cima | Aviso na tela de encaixe; para reservar, criar agendamento. Avaliar com o dono se o encaixe deve ocupar a agenda |
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

---

**Aguardando aprovação explícita para iniciar a Fase 7.** Silêncio não é autorização.
