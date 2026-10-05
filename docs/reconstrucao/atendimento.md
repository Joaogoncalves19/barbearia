# Atendimento (Fase 6)

O atendimento é **o que aconteceu** quando o cliente chegou. O agendamento é **a reserva**. São fatos
diferentes, em tabelas diferentes, ligados quando existe relação:

```text
AGENDAMENTO (reserva)          appointments, appointment_items (preço combinado ao agendar)
    │  "cliente chegou"
    ▼
ATENDIMENTO (o que aconteceu)  attendances, attendance_items, attendance_consumptions,
    │                          attendance_discounts, attendance_events
    │  "concluir"  (uma transação)
    ├──▶ PAGAMENTO             payments (forma, valor, gorjeta; estorno = novo registro)
    │        └──▶ CAIXA        cash_movements (uma entrada por pagamento, no caixa aberto)
    └──▶ ESTOQUE               stock_movements (venda e consumo, ligados ao atendimento)
```

Documentos irmãos: [pagamentos.md](pagamentos.md) (formas, descontos, arredondamento, estorno),
[caixa.md](caixa.md), [produtos.md](produtos.md), [estoque.md](estoque.md).

## 1. Uma regra por operação

| Operação | Única porta |
|---|---|
| Abrir (do agendamento ou encaixe), iniciar, itens, consumo, desconto, profissional, cancelar, **concluir** | `App\Modules\Checkout\Services\AttendanceService` |
| Estorno de pagamento e devolução ao estoque de um atendimento concluído | `App\Modules\Checkout\Services\AttendanceCorrections` |
| Totais (subtotal, descontos, total) | `AttendancePricing` → `Shared\Pricing\PriceBreakdown` (o mesmo cálculo do agendamento) |
| Autorização por registro | `App\Modules\Checkout\Policies\AttendancePolicy` |

As telas (`Panel\Checkout\AttendanceController`) só leem a intenção, validam a entrada e chamam os serviços.

## 2. Relação com o agendamento

- **Do agendamento:** "Cliente chegou: abrir atendimento", no detalhe do agendamento. Só para agendamento
  **pendente ou confirmado** e **do dia** (fuso da barbearia). Pendente é confirmado ao abrir. Cancelado e
  "não compareceu" **não** viram atendimento.
- **Um atendimento em vigor por agendamento:** a sentinela `attendances.active_appointment_id` (única) vale o
  `appointment_id` enquanto o atendimento não é cancelado. Abrir de novo (duplo clique) devolve o mesmo
  atendimento. Cancelado, pode-se abrir outro.
- **Encaixe (cliente sem hora marcada):** permitido (decisão do dono, D-26). Cliente cadastrado ou só nome e
  telefone. **O encaixe ocupa a agenda** (correção da Fase 6, pedida pelo dono): ele é um **agendamento** com
  origem `walk_in` (`AppointmentSource::WalkIn`, "Encaixe"), criado pelo **mesmo** `BookingService::book` da
  agenda, canal equipe. Não existe uma segunda regra de conflito:

  ```text
  Agendamento
  ├── origem: online   (site)
  ├── origem: staff    (equipe, pela agenda)
  └── origem: walk_in  (encaixe, pela tela de atendimento)
  ```

  - **Quando:** começa no próximo ponto da grade de 5 min depois de agora (`BusinessTime::nextStart`: 10:02
    vira 10:05) e dura o tempo do serviço escolhido.
  - **Regras:** as da [disponibilidade](disponibilidade.md) (`Availability::check`, dentro da transação, com a
    agenda do profissional travada): serviço e profissional ativos e compatíveis, funcionamento da barbearia ∩
    expediente do profissional, folga, pausa, bloqueio (do profissional ou da barbearia), outro agendamento
    que ocupa horário, e o mesmo cliente em dois lugares. Recusado: nada é gravado e a tela diz o motivo e o
    próximo horário livre do profissional hoje (para marcar pela agenda).
  - **O atendimento nasce do agendamento de encaixe na mesma transação** (`source = walk_in`,
    `appointment_id` preenchido). Se a agenda recusar, não há atendimento; se o atendimento falhar, não fica
    agendamento.
  - **Concorrência:** encaixe e reserva pelo site no mesmo horário disputam a mesma trava
    (`professionals.schedule_version`); só um vence. Testado com processos reais.
- **Serviço a mais durante o atendimento (D-33):** entra e o valor é recalculado, mas o horário ocupado na
  agenda **não** é estendido; vale a duração reservada no agendamento ou encaixe. Uma extensão futura teria de
  passar pela regra única da agenda e ficar no histórico.
- **Conclusão:** conclui também o agendamento de origem (`BookingService::complete`, na mesma transação) e,
  desde a Fase 7, lança a comissão (por item) e a gorjeta (por pagamento) do profissional que atendeu, ainda
  na mesma transação ([comissoes.md §3](comissoes.md#3-cálculo-na-conclusão-do-atendimento)).
- **Cancelamento do atendimento:** vindo de agendamento, o agendamento não muda (a equipe decide na agenda se
  foi falta ou cancelamento). **Encaixe:** o agendamento de encaixe existia só por causa do atendimento e é
  cancelado junto ("Encaixe cancelado: motivo"), liberando o horário.
- **Com o cliente em atendimento, a agenda não se mexe por fora:** enquanto houver atendimento em vigor, o
  agendamento não pode ser cancelado, remarcado nem marcado como falta pela agenda ou pelo site
  (`BookingRuleViolation('in_attendance')`); senão o horário ficaria livre com o cliente na cadeira.
- **Profissional:** o atendimento guarda quem **efetivamente** atendeu. Pode ser trocado enquanto aberto, e
  **a agenda acompanha**: o agendamento de origem é remarcado para o novo profissional pelo
  `BookingService::reschedule` (mesma regra), no horário combinado ou, se já passou, a partir do próximo ponto
  da grade, com a duração fotografada. Novo profissional ocupado: a troca é recusada e nada muda. O histórico
  do agendamento registra de quem para quem.

## 3. Snapshot: histórico × referência

| Dado | Onde | Tipo | Por quê |
|---|---|---|---|
| Serviço/produto vendido: nome, quantidade, preço unitário, total | `attendance_items.name`, `unit_price_cents`, `total_cents` | **Snapshot** | O catálogo muda; o que foi cobrado não |
| Origem do preço | `attendance_items.price_source` | **Snapshot** | `catalog_at_booking` (combinado ao agendar), `catalog_at_attendance` (incluído no atendimento), legado |
| Custo do produto | `attendance_items.cost_cents`, `attendance_consumptions.unit_cost_cents` | **Snapshot** | CMV (Fase 7) |
| Desconto: tipo, valor antes, valor descontado, motivo, quem aplicou | `attendance_discounts` | **Snapshot** (congela na conclusão) | Nunca recalculado depois |
| Subtotal, desconto, total, gorjeta | `attendances.*_cents` | **Snapshot** gravado na conclusão | Relatórios, comissão e caixa usam o valor do dia |
| Nome do profissional e do cliente, telefone | `attendances.professional_name`, `customer_name`, `customer_phone` | **Snapshot** | Renomear não reescreve o passado |
| Profissional, cliente, agendamento, serviço, produto | `*_id` | **Referência** | Relatórios, comissão, histórico do cliente |

Não se copia o resto (descrição, foto, categoria, e-mail do cliente): não é necessário para o fato.

**Preço:** o item que veio do agendamento mantém o preço **combinado ao agendar**; o que é incluído no
atendimento usa o preço do catálogo **naquele momento**. O preço de um item **nunca é editado**: reduzir é
desconto (com permissão e motivo, auditado); cobrar mais é outro item. Não existe "alterar o valor cobrado"
sem rastro.

## 4. Estados

```text
ABERTO ──> EM ATENDIMENTO ──> CONCLUÍDO
   │              │
   └──────────────┴──> CANCELADO
```

| Estado | Significa | Pode mudar itens, consumo, desconto, profissional? |
|---|---|---|
| `open` (Aberto) | Cliente chegou | Sim |
| `in_progress` (Em atendimento) | Na cadeira | Sim |
| `completed` (Concluído) | Serviço prestado, valores congelados, pago | **Não** |
| `cancelled` (Cancelado) | Desistência antes de concluir | **Não** |

- Transições no enum `AttendanceStatus` e conferidas no model: transição proibida lança exceção.
- Concluído e cancelado são **finais**: o model recusa qualquer alteração (inclusive fora do serviço) e as
  linhas (itens, consumo, desconto) também ficam travadas (`FrozenWithAttendance`).
- **"Aguardando pagamento" não existe:** decisão do dono, só se conclui **pago** (pagamentos fecham exatamente
  com o total).
- Só se conclui a partir de "em atendimento" (iniciar é um passo explícito).

## 5. Conclusão (tudo ou nada)

`AttendanceService::complete(atendimento, pagamentos, chave, quem)`, numa transação:

1. **Trava** a linha do atendimento (`version + 1`, primeira escrita).
2. **Idempotência:** já concluído com a mesma chave? Devolve o atendimento, sem gravar nada.
3. **Estado:** precisa estar "em atendimento"; ao menos um item.
4. **Valores:** recalcula e **congela** os descontos (`PriceBreakdown`); item com preço desconhecido impede.
5. **Pagamentos:** a soma dos valores tem de ser **exatamente** o total (gorjeta à parte). Forma
   "não informado" é recusada.
6. **Caixa:** trava o caixa aberto; sem caixa aberto, nada é gravado (exceto total zero, sem pagamento).
7. **Estoque:** trava os produtos (id crescente); lança venda e consumo (`attendance_id`), recusando saldo
   negativo.
8. **Pagamentos e caixa:** um `payment` por forma e uma entrada no caixa por pagamento (valor + gorjeta).
   Fase 8: linha de **vale-presente** é conferida e travada aqui (uso único, valor exato, sem gorjeta), o
   vale vira "usado" e o pagamento não entra no caixa ([vale-presente.md](vale-presente.md)).
9. **Atendimento:** status concluído, totais, gorjeta, quem concluiu, chave.
10. **Agendamento de origem:** concluído.
11. **Histórico:** evento `completed` com totais e formas; auditoria (antes/depois) pelo `Auditable`.

Fase 7 e 8, na mesma transação: comissão e gorjeta ([comissoes.md](comissoes.md)); **promoção** (cupom
reservado vira usado, pontos reservados saem do saldo, reserva não usada é liberada) e **pontos ganhos** na
visita e bônus de indicação ([promocoes.md](promocoes.md), [fidelidade.md](fidelidade.md)).

**Balcão (Fase 8):** "Aplicar promoção" (cupom ou pontos, `promotions.apply`) e o desconto manual passam pelo
mesmo motor: vale um desconto só, o maior; o menor é recusado com o motivo. Atendimento concluído tem
comprovante para imprimir e enviar por e-mail ([comprovantes.md](comprovantes.md)).

Qualquer falha (estoque insuficiente, sem caixa, erro inesperado) **desfaz tudo**: o atendimento continua em
andamento, sem pagamento, sem entrada no caixa, sem baixa de estoque. Teste:
`AttendanceServiceTest::test_falha_no_meio_desfaz_pagamento_caixa_estoque_e_agendamento` (falha simulada
depois dos pagamentos) e `test_estoque_insuficiente_desfaz_a_conclusao_inteira`.

## 6. Correções depois de concluído

O atendimento concluído **não volta** a aberto e seus valores não são editados. Corrige-se com registros novos
([pagamentos.md §6](pagamentos.md#6-estorno), [estoque.md §5](estoque.md#5-estorno-e-devolução)):

- **estorno de pagamento** (parcial ou total), com motivo, saída no caixa aberto;
- **devolução ao estoque** de produto vendido ou consumido, com motivo.

O atendimento continua mostrando o valor do dia; o histórico mostra as correções.

## 7. Transações, travas e concorrência

| Operação | Trava, nesta ordem |
|---|---|
| Qualquer mudança no atendimento | atendimento |
| Conclusão | atendimento → caixa aberto → produtos (id crescente) |
| Estorno | atendimento → caixa aberto |
| Devolução ao estoque | atendimento → produto |
| Encaixe | agenda do profissional (`schedule_version`) → cria agendamento e atendimento |
| Troca de profissional | atendimento → agendas envolvidas (id crescente) |
| Cancelar encaixe | atendimento → agenda do profissional |

Trava = primeira escrita da transação na linha (`version`/`stock_version` + 1), o mesmo padrão da agenda
([agendamento.md §5](agendamento.md#5-concorrência-dupla-reserva)). No SQLite, a transação `IMMEDIATE` já
serializa as escritas; no MySQL, é bloqueio de linha. Ordem fixa = sem impasse.

**Teste de concorrência real** (`CheckoutConcurrencyTest`, processos PHP independentes num SQLite em arquivo):
4 pessoas concluindo o mesmo atendimento ao mesmo tempo, cada uma com a sua chave → **1** conclusão,
**1** pagamento, **1** entrada no caixa, **1** baixa; as outras 3 recebem "não pode mais ser alterado".

**Encaixe × site** (`BookingConcurrencyTest::test_encaixe_e_site_disputando_o_mesmo_horario`): 3 encaixes e
3 reservas pelo site, em horários que se sobrepõem, no mesmo profissional e ao mesmo tempo → **1** vence,
5 recebem conflito; sem atendimento se o encaixe perdeu; verificador de integridade limpo.

## 8. Idempotência

| Operação | Proteção |
|---|---|
| Abrir do agendamento | Sentinela única `active_appointment_id`: repetir devolve o mesmo atendimento |
| Concluir | `completion_key` (chave do formulário, única): repetir devolve o concluído; outra chave recebe erro de estado |
| Estorno | `payments.request_key` (única) |
| Suprimento/sangria | `cash_movements.request_key` (única) |
| Entrada/saída/ajuste/estorno de estoque | `stock_movements.request_key` (única) |

Testes: `test_duplo_clique_em_concluir_nao_duplica_nada`, `test_repetir_o_envio_da_conclusao_nao_duplica`
(HTTP), `test_estorno_repetido_com_a_mesma_chave_nao_duplica`, `test_suprimento_repetido_...`,
`test_repetir_a_mesma_requisicao_nao_duplica` (estoque).

## 9. Telas

| Tela | URL | Permissão |
|---|---|---|
| Atendimentos do dia (em andamento; concluídos e cancelados) | `/painel/atendimentos?data=` | `attendances.view` (todos) ou `view_own` (só os próprios) |
| Encaixe (ocupa a agenda a partir de agora; recusado se o profissional não estiver livre) | `/painel/atendimentos/novo` | `attendances.manage` ou `manage_own` (só como ele mesmo) |
| Abrir do agendamento | botão no detalhe do agendamento | `openFor` no profissional do agendamento |
| Atendimento (itens, material, valores, pagamentos, estoque, histórico) | `/painel/atendimentos/{código}` | `view` da policy (alheio = 404) |
| Iniciar, itens, material, profissional, observações | ações no atendimento | `update` da policy |
| Desconto | formulário no atendimento | `update` + `attendances.discount` |
| Concluir e receber (modal com as formas de pagamento) | ação no atendimento | `update` + `payments.receive` |
| Cancelar (modal com motivo) | ação no atendimento | `attendances.cancel` ou `manage_own` no próprio |
| Estornar (modal) | pagamentos do atendimento | `payments.refund` |
| Devolver ao estoque (modal) | estoque do atendimento | `stock.adjust` |
| Comprovante do cliente | `/minha-conta/atendimentos/{código}` | só o próprio cliente, só concluído (alheio = 404) |

Ações irreversíveis (concluir, cancelar, estornar, devolver) pedem confirmação num modal; se o envio volta com
erro, o modal reabre com a mensagem ao lado do campo.

## 10. Auditoria e histórico

- `attendance_events`: aberto, iniciado, item incluído/retirado, consumo, desconto (antes, desconto, depois,
  motivo), profissional trocado, concluído (totais e formas), cancelado (motivo), estorno, devolução.
- `Auditable` em atendimento, itens, consumo e descontos (antes/depois), além de `payment.refunded`,
  `cash.opened`, `cash.closed`, `cash.supply`, `cash.withdrawal` pela `AuditTrail`.
