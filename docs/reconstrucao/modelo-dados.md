# Modelo de dados do novo sistema (Fases 2 a 6)

> Status: **definitivo para a Fase 2**. Implementado em `novo-sistema/database/migrations`
> (2026_09_*). A Fase 3 acrescentou a migration `2026_09_29_000100_add_account_security_tables`
> (seção 2.1); a Fase 4, `2026_09_30_000100_add_catalog_and_team_admin_columns` (seções 2.3 e 2.4);
> a Fase 5, `2026_10_01_000100_create_agenda_tables` (seções 2.3 e 2.5); a Fase 6,
> `2026_10_02_000100_create_checkout_tables` (seções 2.4, 2.5a e 2.6).
> Mudanças posteriores entram por novas migrations, nunca editando as existentes
> depois da primeira implantação.
>
> Documentos irmãos: [regras-dados.md](regras-dados.md) (regras e onde cada uma é garantida),
> [mapa-banco-antigo-novo.md](mapa-banco-antigo-novo.md) (origem de cada campo),
> [estrategia-historico.md](estrategia-historico.md), [estrategia-duplicidades.md](estrategia-duplicidades.md)
> e [importador.md](importador.md).

## 1. Modelagem conceitual

A modelagem foi feita **antes** das migrations, a partir do banco atual
([banco-atual.md](banco-atual.md)), das funcionalidades ([funcionalidades.md](funcionalidades.md))
e dos princípios da [arquitetura nova](arquitetura-nova.md). As perguntas que guiaram cada entidade:
o que ela representa no negócio, quem é o dono, o que pode mudar, o que **nunca** pode mudar
depois de acontecer, e como ela nasce a partir do sistema atual.

### 1.1 Domínios e entidades

| Domínio (módulo) | Entidades | Representa |
|---|---|---|
| **Identity** | `users` | Equipe que entra no painel (dono, gerente, recepção, financeiro, profissional) |
| **Customers** | `customers`, `customer_notes`, `customer_favorite_professionals`, `customer_merge_candidates`, `consent_records`, `email_suppressions`, `customer_notifications` | Cliente final, anotações da equipe, favoritos, suspeitas de duplicidade, consentimentos (LGPD), lista de supressão de e-mail e avisos no app |
| **Team** | `professionals`, `professional_service`, `working_hours`, `schedule_breaks`, `time_off`, `blocked_slots` | Profissional que atende (pode ou não ter login), o que ele faz, expediente, pausas, ausências e bloqueios pontuais |
| **Catalog** | `service_categories`, `services`, `packages`, `package_items`, `products`, `stock_movements` | O que a barbearia vende: serviços, combos, produtos e o razão de estoque |
| **Checkout** (Fase 6) | `attendances`, `attendance_items`, `attendance_consumptions`, `attendance_discounts`, `attendance_events` | O atendimento: o que aconteceu quando o cliente chegou (itens vendidos, material usado, descontos, linha do tempo) |
| **Scheduling** | `appointments`, `appointment_items`, `appointment_adjustments`, `appointment_events`, `appointment_reminders` | A reserva: quando, com quem, o que foi combinado (com preço congelado), descontos, linha do tempo e lembretes |
| **Finance** | `payments`, `cash_sessions`, `cash_movements`, `commission_rules`, `commission_entries`, `tip_entries`, `commission_payouts`, `advances`, `expenses`, `financial_goals` | Dinheiro que entrou (pagamentos do atendimento), caixa, regras de comissão (versionadas), comissões e gorjetas do profissional, repasses, vales, despesas e metas |
| **Loyalty** | `loyalty_entries`, `coupons`, `coupon_redemptions`, `gift_cards` | Pontos (razão), cupons e seus usos, vales-presente |
| **Subscriptions** | `plans`, `plan_services`, `subscriptions`, `subscription_payments`, `gateway_events` | Planos mensais, assinaturas (com histórico), pagamentos e idempotência de webhooks |
| **Reviews** | `reviews`, `review_replies` | Avaliação do atendimento e resposta da barbearia |
| **Marketing** | `campaigns` | Resumo das campanhas de e-mail já enviadas |
| **System** | `settings`, `audit_logs` | Configurações não sensíveis e trilha de auditoria |
| **LegacyImport** | `import_runs`, `import_issues`, `legacy_references` | Execuções do importador, pendências e o vínculo "registro antigo → registro novo" |

### 1.2 Mapa de relacionamentos

```text
users 1───0..1 professionals ─┬─< professional_service >── services ──< service_categories
                              ├─< working_hours / schedule_breaks / time_off / blocked_slots
                              ├─< commission_entries >── commission_payouts ──< advances
                              └─< financial_goals (meta do profissional)

customers ─┬─< appointments ─┬─< appointment_items ──> services | packages | products (referência opcional)
           │                 ├─< appointment_adjustments ──> coupons | gift_cards | subscriptions (origem)
           │                 ├─< appointment_events / appointment_reminders
           │                 ├─< attendances (0..1 em vigor; Fase 6) ─┬─< attendance_items / attendance_consumptions
           │                 │                                        ├─< attendance_discounts / attendance_events
           │                 │                                        ├─< payments ──> cash_sessions ──< cash_movements
           │                 │                                        └─< stock_movements (venda e consumo)
           │                 └── 0..1 reviews ──< review_replies
           ├─< attendances sem agendamento (encaixe)
           ├─< subscriptions ──> plans ──< plan_services >── services
           │        └─< subscription_payments
           ├─< loyalty_entries (saldo = soma)
           ├─< coupon_redemptions >── coupons
           ├─< customer_notes / customer_favorite_professionals / customer_notifications
           ├─< consent_records ; email_suppressions (também sem cliente)
           ├── referred_by ──> customers (indicação)
           └─< customer_merge_candidates (pares suspeitos)

products ──< stock_movements (saldo = soma; estorno aponta o movimento)       packages ──< package_items >── services
plans / services / products / professionals: nunca apagados fisicamente (soft delete / inativo)
legacy_references: (tabela antiga, id antigo) ──> (entidade nova, id novo)
```

### 1.3 Decisões de modelagem

1. **Equipe × cliente separados** (T-03): `users` é só equipe; `customers` tem guard próprio
   (Fase 3). Um profissional pode existir **sem login** (`professionals.user_id` nulo).
2. **Profissional ≠ usuário**: no sistema atual o barbeiro é as duas coisas na mesma tabela. Agora
   `professionals` guarda o que é do atendimento (nome de exibição, comissão, agenda) e `users`
   guarda o acesso (login, perfil, ativo).
3. **Chaves**: inteiros autoincrementais internos. Identificadores externos ficam em colunas próprias:
   `appointments.code` (código público curto, preserva `AG-XXXXXX`), `customers.public_id` (ULID,
   para URLs sem expor sequência), `gateway_*` (Stripe). O id antigo **nunca** é a chave nova; o vínculo
   fica em `legacy_references` (ver 1.4).
4. **Dinheiro em centavos inteiros** (`*_cents`, `integer`), nunca `float`/`decimal`. Percentuais em
   **pontos-base** (`*_bp`, 10000 = 100 %). Conversões só pelo value object `Money` (inclui `percentOf` em pontos-base) e pelo conversor `Decimal` (texto → inteiro sem float).
5. **Datas**: instantes em UTC (`*_at`, `timestamp`); datas civis sem hora (`*_on`, `date`) e horas de
   parede (`time`) quando o conceito é local (expediente, data de vencimento, aniversário).
6. **Histórico imutável** ([estrategia-historico.md](estrategia-historico.md)): o que aconteceu
   (item vendido, preço, desconto, pagamento, pontos, movimento de estoque, comissão, pagamento de
   assinatura, evento de webhook) é **fotografado** e não se edita; corrige-se com lançamento de
   estorno/ajuste. Registros de catálogo e cadastro usam **soft delete** ou `is_active`.
7. **Saldos derivados**: pontos e estoque são a **soma de um razão** (`loyalty_entries`,
   `stock_movements`). Não existe coluna de saldo que possa divergir.
8. **Unicidade condicional portável**: onde a regra é "único só quando ativo" (uma assinatura ativa
   por cliente, um vínculo ativo), usamos uma coluna-sentinela anulável com índice único
   (ex.: `subscriptions.active_customer_id`), que funciona igual em SQLite e MySQL.
9. **Valores desconhecidos ficam nulos**, nunca inventados: preço de item antigo sem catálogo, data
   de envio de lembrete antigo, data de uso de cupom antigo. A origem do valor é explícita
   (`price_source`, `amount_source`).
10. **Consentimento**: ausência de informação = **desconhecido** (`marketing_email_consent = unknown`),
    nunca "aceito". Opt-out é preservado em dois lugares: cliente e lista de supressão (vale também para
    quem não tem cadastro).

### 1.4 Rastreabilidade da migração

Cada registro criado pelo importador tem uma linha em `legacy_references`:
`(source_table, source_id)` único → `(entity_type, entity_id)` + `checksum` do registro de origem.
É isso que torna o importador **idempotente** (a segunda execução reconhece o que já importou),
permite **auditar** qualquer registro novo até a origem e detectar se a origem mudou entre dois ensaios.
Linhas agregadas (ex.: um agendamento antigo gera agendamento + itens + ajuste + pagamento) são
rastreadas pela entidade principal; as filhas carregam a FK da principal.

## 2. Entidades

Legenda: **PK** chave · **FK** chave estrangeira (ação ao apagar o pai) · **U** único · **I** índice ·
**N** anulável · **SD** soft delete.

### 2.1 Identity

**users** (equipe) — `id`, `name`, `email` (U, N), `username` (U, N), `password`, `role` (enum
`StaffRole`), `is_active`, `last_login_at` (N), `must_change_password` (Fase 3), `password_changed_at`
(N, Fase 3), `remember_token`, timestamps.
- Login da equipe por **usuário ou e-mail** (decisão da Fase 3). O usuário é o identificador da conta;
  o `email` é opcional e serve para entrar, recuperar a senha e receber avisos. Regra: pelo menos um de
  `email`/`username` preenchido (garantido no model e testado).
- `must_change_password`: senha provisória definida pelo proprietário (conta nova ou redefinição sem
  e-mail); a troca é obrigatória no primeiro acesso.
- Senha: hash bcrypt/argon aceito como está; rehash transparente no login (ver
  [importador.md](importador.md#senhas) e [autenticacao.md](autenticacao.md)).

**customer_login_tokens** (Fase 3) — `id`, `customer_id` (FK customers, cascade), `token_hash` (U, SHA-256
do token do link mágico), `expires_at`, `used_at` (N, uso único), `requested_ip` (N), `created_at`.

Clientes autenticam pela própria tabela `customers` (guard `customer`), ver 2.2.

### 2.2 Customers

**customers** — `id`, `public_id` (U, ULID), `name`, `email` (U, N, minúsculo), `email_verified_at` (N),
`phone` (U, N, E.164 `+55…`), `cpf` (U, N, 11 dígitos), `password` (N), `remember_token`,
`birth_date` (N), `photo_path` (N), `status` (`active`|`inactive`), `referral_code` (U, N),
`referred_by_customer_id` (FK customers, **null on delete**, N), `marketing_email_consent`
(`unknown`|`granted`|`revoked`), `marketing_consent_updated_at` (N), `merged_into_customer_id`
(FK customers, N), `anonymized_at` (N), `last_login_at` (N, Fase 3), `password_changed_at` (N, Fase 3),
timestamps, **SD**.
- **CPF é obrigatório para o cliente** (decisão da Fase 3). A coluna segue anulável só para registros
  vindos do importador ou de um cadastro incompleto: sem CPF, o cliente é levado a informá-lo antes de
  usar a conta (middleware `customer.complete`). Todo cadastro pelo site exige CPF válido.

**customer_notes** — `customer_id` (FK cascade), `author_user_id` (FK users, null on delete, N),
`author_label` (N, snapshot do autor), `visibility` (`team`|`professionals`), `body`, `created_at`.

**customer_favorite_professionals** — PK composta (`customer_id` FK cascade, `professional_id` FK cascade), `created_at` (N).

**customer_merge_candidates** — `customer_id` (FK cascade), `duplicate_customer_id` (FK cascade, N),
`match_field` (`email`|`phone`|`cpf`|`referral_code`), `match_value`, `status`
(`pending`|`merged`|`dismissed`), `import_run_id` (FK import_runs, null on delete, N), `notes` (N),
`resolved_by_user_id` (N), `resolved_at` (N), timestamps. U(`customer_id`,`duplicate_customer_id`,`match_field`).

**consent_records** (só inclusão) — `customer_id` (FK, null on delete, N), `email` (N), `purpose`
(`marketing_email`), `action` (`granted`|`revoked`), `source` (`legacy_import`|`customer`|`staff`|`unsubscribe_link`),
`occurred_at` (N: desconhecido no legado), `evidence` (N), `created_at`.

**email_suppressions** — `email` (U, minúsculo), `reason` (`marketing_opt_out`|`bounce`|`complaint`),
`customer_id` (FK null on delete, N), `suppressed_at` (N), timestamps.

**customer_notifications** — `customer_id` (FK cascade), `message`, `read_at` (N), `created_at`.

### 2.3 Team

**professionals** — `user_id` (FK users, null on delete, U, N), `display_name`, `photo_path` (N),
`is_active`, `is_bookable`, `sort_order`, ~~`commission_rate_bp`, `commission_on_products`~~ (Fase 7: viraram
`commission_rules`; a coluna saiu), `ledger_version` (Fase 7: trava do saldo do profissional),
`subscription_commission_mode` (`default`|`percent`|`fixed`|`none`), `subscription_commission_rate_bp` (N),
`subscription_commission_amount_cents` (N), timestamps, **SD**.
**Fase 4:** `slug` (U, estável), `headline` (N, especialidade), `bio` (N), `is_public`, `is_featured`,
`lock_version` (concorrência otimista); I(`is_active`,`sort_order`). Regras em [profissionais.md](profissionais.md).

**professional_service** — PK (`professional_id` FK cascade, `service_id` FK cascade). Combos que
o profissional atende: `professional_package` com a mesma forma.

**working_hours** — `professional_id` (FK cascade), `weekday` (0=domingo … 6), `starts_at`/`ends_at`
(`time`), U(`professional_id`,`weekday`,`starts_at`). Vários intervalos por dia são permitidos.

**schedule_breaks** (pausa recorrente, ex.: almoço) — `professional_id`, `weekday` (N = todos os dias),
`starts_at`, `ends_at`, `is_active`, `label`.

**time_off** (folga, férias, atestado) — `professional_id`, `starts_on`, `ends_on`, `kind`, `reason` (N), timestamps.
Check: `ends_on >= starts_on`.

**blocked_slots** (bloqueio pontual) — `professional_id`, `starts_at`, `ends_at` (UTC), `reason` (N).
U(`professional_id`,`starts_at`). **Fase 5:** `professional_id` anulável (**nulo = barbearia inteira**:
feriado, evento), `created_by_user_id` (N), I(`starts_at`,`ends_at`). `time_off` ganhou `created_by_user_id`.
`professionals.schedule_version`: linha de bloqueio da agenda (proteção contra dupla reserva,
[agendamento.md §5](agendamento.md#5-concorrência-dupla-reserva)).

**business_hours** (Fase 5) — horário de funcionamento da barbearia: `weekday` (0–6), `starts_at`/`ends_at`
(`time`, hora de parede no fuso da barbearia). Vários períodos por dia; dia sem linha = fechado.
U(`weekday`,`starts_at`). Ver [horarios.md](horarios.md).

### 2.4 Catalog

**service_categories** — `name`, `sort_order`, SD. **Fase 4:** `slug` (U), `description` (N), `is_active`,
`lock_version`.
**services** — `category_id` (FK null on delete, N), `name`, `description` (N), `duration_minutes` (>0),
`price_cents` (≥0), `is_active`, `sort_order`, timestamps, **SD**. **Fase 4:** `slug` (U), `is_public`,
`is_featured`, `image_path` (N), `lock_version`. Duração: múltiplo de 5 entre 5 e 480 min; preço atual entre
R$ 1,00 e R$ 10.000,00 (regras no model, valem sempre que o valor muda). Ver [servicos.md](servicos.md) e
[precos.md](precos.md).
**packages** (combos) — `category_id` (N), `name`, `price_cents`, `is_active`, SD.
**package_items** — `package_id` (FK cascade), `service_id` (FK restrict), `quantity`. U(`package_id`,`service_id`).
Duração do combo = soma das durações dos serviços (regra do sistema atual).
**products** — `category_id` (N), `name`, `price_cents`, `cost_cents` (N), `min_stock` (N), `is_active`, SD.
**Sem coluna de saldo.** **Fase 6:** `price_cents` anulável (insumo, não vendido), `sku` (N, U), `description` (N),
`unit`, `lock_version`, `stock_version` (linha de trava do estoque). Ver [produtos.md](produtos.md).
**stock_movements** (só inclusão) — `product_id` (FK restrict), `quantity` (com sinal), `kind`
(`purchase`|`sale`|`consumption`|`usage`|`loss`|`adjustment`|`reversal`|`legacy_opening`), `reason` (N),
`actor_label` (N), `occurred_at` (N), `created_at`. **Fase 6:** `appointment_id` trocado por `attendance_id`
(FK restrict, N; obrigatório em venda e consumo), `reverses_movement_id` (FK, N, U: estorno uma vez),
`balance_after` (N), `unit_cost_cents` (N), `created_by_user_id` (N), `request_key` (N, U). Ver [estoque.md](estoque.md).

### 2.5 Scheduling

**appointments** — `code` (U, ex.: `AG-7F3K2Q`), `customer_id` (FK null on delete, N),
`professional_id` (FK **restrict**, N), `professional_name` (snapshot), `customer_name`,
`customer_email` (N), `customer_phone` (N) (snapshot do contato **no momento** do agendamento),
`starts_at`, `ends_at` (UTC, `ends_at > starts_at`), `status` (enum abaixo), `source`
(`online`|`staff`|`walk_in`|`chatbot`|`legacy`; `walk_in` = encaixe, ocupa a agenda como qualquer outro), `notes` (N), `subtotal_cents`/`discount_cents`/`total_cents` (N:
nulos quando algum preço é desconhecido), `cancelled_at`, `cancelled_by` (`customer`|`staff`|`system`),
`cancellation_reason` (N), `confirmation_requested_at` (N), `confirmed_at` (N), `completed_at` (N),
`payment_gateway` (N), `payment_gateway_reference` (N), `created_at`, `updated_at`. I(`professional_id`,`starts_at`),
I(`customer_id`,`starts_at`), I(`status`,`starts_at`). **Fase 5:** `customer_reschedules` (quantas vezes
o cliente remarcou, padrão 0; limite em `agenda.policy`) e `created_by_user_id` (N, quem da equipe criou).
Criação, remarcação e cancelamento só pelo `BookingService` ([agendamento.md](agendamento.md)).

Status (`AppointmentStatus`): `pending`, `awaiting_payment`, `confirmed`, `completed`, `cancelled`, `no_show`.
Transições permitidas ficam no enum (`canTransitionTo`) e são testadas.

**appointment_items** — `appointment_id` (FK cascade), `item_type` (`service`|`package`|`product`),
`service_id`/`package_id`/`product_id` (FK null on delete, N: o item continua existindo se o
catálogo sumir), `name` (snapshot), `quantity`, `unit_price_cents` (N), `total_cents` (N),
`duration_minutes` (N), `price_source` (`catalog_at_booking`|`recorded`|`legacy_catalog_estimate`|`legacy_unknown`),
`cost_cents` (N, produtos), `created_at`. Check: preço nulo só com `price_source = legacy_unknown`.

**appointment_adjustments** — `appointment_id` (FK cascade), `kind` (`coupon`|`gift_card`|`loyalty`|`birthday`|
`referral`|`subscription`|`plan_signup`|`manual`|`legacy_unknown`), `amount_cents` (desconto, ≥0),
`coupon_id`/`gift_card_id`/`subscription_id` (N), `description` (N), `created_at`.

**appointment_events** (linha do tempo, só inclusão) — `appointment_id` (FK cascade), `type`, `description` (N),
`actor_label` (N), `data` (json, N), `occurred_at`.

**appointment_reminders** — `appointment_id` (FK cascade), `kind` (`day_before`|`hours_before`),
`status` (`sent`|`failed`), `sent_at` (N). U(`appointment_id`,`kind`).

### 2.5a Checkout (Fase 6)

**attendances** — `code` (U, ex.: `AT-9MX4RB`), `source` (`appointment`|`walk_in`|`legacy`), `appointment_id` (FK
restrict, N; preenchido também no encaixe, que aponta o agendamento de origem `walk_in`; verificador R33), `active_appointment_id` (N, U: sentinela "um atendimento em vigor por agendamento"),
`customer_id` (FK null on delete, N), `customer_name`, `customer_phone` (N) (snapshots), `professional_id` (FK
restrict; N só no legado), `professional_name` (snapshot), `status` (`open`|`in_progress`|`completed`|`cancelled`),
`opened_at`, `started_at`/`completed_at`/`cancelled_at` (N), `cancellation_reason` (N), `subtotal_cents`/
`discount_cents`/`total_cents`/`tip_cents` (N; gravados na conclusão), `notes` (N), `opened_by_user_id`/
`completed_by_user_id`/`cancelled_by_user_id` (N), `completion_key` (N, U: idempotência), `version` (linha de trava),
timestamps. I(`status`,`opened_at`), I(`professional_id`,`opened_at`), I(`completed_at`).

**attendance_items** — `attendance_id` (FK cascade), `item_type` (`service`|`package`|`product`), `service_id`/
`package_id` (null on delete, N), `product_id` (restrict, N), `appointment_item_id` (N, origem), `name`, `quantity`,
`unit_price_cents`/`total_cents` (N só no legado desconhecido), `duration_minutes` (N), `price_source`
(`catalog_at_booking`|`catalog_at_attendance`|legado), `cost_cents` (N), `added_by_user_id` (N), timestamps.
**attendance_consumptions** — `attendance_id`, `product_id` (restrict), `product_name`, `quantity`, `unit_cost_cents` (N),
`added_by_user_id` (N), timestamps.
**attendance_discounts** — `attendance_id`, `kind` (`AdjustmentKind`), `type` (`percent`|`fixed`), `percent_bp`/`fixed_cents`
(um dos dois), `base_cents` (valor antes), `amount_cents` (valor descontado ≤ base), `reason` (N), `applied_by_user_id` (N).
**attendance_events** (só inclusão) — `attendance_id`, `type`, `description`, `actor_label` (N), `data` (json, N), `occurred_at`.

Itens, consumo e descontos mudam só enquanto o atendimento está aberto ou em andamento; depois são histórico.
Ver [atendimento.md](atendimento.md) e [pagamentos.md](pagamentos.md).

### 2.6 Finance

**payments** (só inclusão) — `customer_id` (N), `kind` (`payment`|`refund`),
`refunds_payment_id` (FK payments, N), `method` (`cash`|`pix`|`debit_card`|`credit_card`|`other`|`unknown`),
`amount_cents` (>0), `tip_cents` (≥0), `amount_source` (`recorded`|`legacy_estimated`), `paid_at` (N),
`received_by_label` (N), `created_at`. **Fase 6:** `appointment_id` trocado por `attendance_id` (FK restrict, N: o
pagamento pertence ao atendimento, não à reserva), `cash_session_id` (FK restrict, N; nulo só no legado),
`received_by_user_id` (N), `reason` (N, motivo do estorno), `request_key` (N, U).

**cash_sessions** — `open_marker` (N, U: 1 enquanto aberto = um caixa por barbearia), `status` (`open`|`closed`),
`opened_by_user_id`, `opened_at`, `opening_float_cents`, `opening_notes` (N), `closed_by_user_id`/`closed_at` (N),
`expected_cash_cents`/`counted_cash_cents`/`difference_cents` (N; no fechamento), `closing_notes` (N), `version`.
**cash_movements** (só inclusão) — `cash_session_id` (restrict), `type` (`payment`|`refund`|`supply`|`withdrawal`;
Fase 7: `payout`|`payout_reversal`|`advance`|`advance_reversal`), `method`, `amount_cents` (com sinal),
`payment_id` (FK restrict, N, U), `commission_payout_id`/`advance_id` (Fase 7, FK restrict, N: origem do
repasse/vale em dinheiro), `description`, `request_key` (N, U), `created_by_user_id` (N), `occurred_at`. Cada
tipo aponta exatamente a sua origem. Ver [caixa.md](caixa.md).

**commission_rules** (Fase 7; versionada, só inclusão) — `target` (`service`|`product`), `professional_id`
(N = todos), `service_id` (N = todos; sempre nulo em produto), `type` (`percent`|`fixed`|`none`), `rate_bp`
(N, 0–10000), `amount_cents` (N, ≥0, só serviço), `scope_key`, `current_scope` (N, U: sentinela "uma regra em
vigor por escopo"), `starts_at`, `ends_at` (N), `reason` (N), `created_by_user_id`/`ended_by_user_id` (N),
timestamps. Só `ends_at`/`current_scope`/`ended_by_user_id` mudam, uma vez. Ver [comissoes.md](comissoes.md).

**commission_entries** (só inclusão) — `professional_id` (restrict), `kind` (`earned`|`refund`|`adjustment`),
`attendance_id` (Fase 7, FK restrict, N; `appointment_id` saiu, como em `payments` na Fase 6),
`attendance_item_id` (FK restrict, N, **U**: uma comissão por item), `payment_id` (N: o estorno de origem),
`commission_rule_id` (N), `item_name`, `quantity` (N), `base_cents`, `rate_bp` (N), `amount_cents` (com sinal),
`rule` (fotografia da regra), `reason` (N), `created_by_user_id` (N), `request_key` (N, U), `occurred_at`,
`commission_payout_id` (N; a única coluna que muda), timestamps. **Não** há lançamentos para atendimentos
antigos (o sistema atual não guardava a comissão; ver [importador.md](importador.md)).

**tip_entries** (Fase 7; só inclusão) — `professional_id` (restrict), `attendance_id` (N), `payment_id` (N, U:
o pagamento com gorjeta, ou o estorno), `kind` (`earned`|`refund`|`adjustment`), `amount_cents` (com sinal),
`reason` (N), `created_by_user_id` (N), `request_key` (N, U), `commission_payout_id` (N), `occurred_at`,
timestamps. Ver [repasses.md](repasses.md).

**commission_payouts** (repasse) — `professional_id` (restrict), `amount_cents` (líquido), `tip_cents` (N),
`services_total_cents` (N), `period_start`/`period_end` (N), `reference_month` (N, `YYYY-MM`), `paid_on` (N),
`notes` (N), timestamps. **Fase 7:** `commission_cents`/`advances_cents` (N), `cutoff_at` (N), `method` (N),
`cash_session_id` (N), `snapshot` (N: lançamentos incluídos; nulo = repasse do sistema antigo),
`created_by_user_id` (N), `request_key` (N, U), `reversed_at`/`reversed_by_user_id`/`reversal_reason`/
`reversal_cash_session_id`/`reversal_request_key` (estorno, uma vez). Imutável fora o estorno.

**advances** (vales) — `professional_id` (restrict), `amount_cents` (vale > 0; estorno < 0), `issued_on` (N),
`reference_month` (N), `description` (N), `commission_payout_id` (N), timestamps. **Fase 7:** `kind`
(`advance`|`reversal`), `reverses_advance_id` (N, U), `method` (N), `cash_session_id` (N),
`created_by_user_id` (N), `request_key` (N, U), `occurred_at` (N), `is_legacy` (vale do sistema antigo:
histórico, nunca abatido de novo). Só inclusão.

**expenses** — `description`, `category` (N), `amount_cents`, `due_on` (N), `paid_on` (N), `status`
(`pending`|`paid`|`cancelled`), `is_recurring`, `recurrence_parent_id` (FK expenses, N), timestamps, SD.

**financial_goals** — `professional_id` (N = meta da barbearia), `period` (`daily`|`monthly`),
`amount_cents`, `effective_from` (N), timestamps.

### 2.7 Loyalty

**loyalty_entries** (razão, só inclusão) — `customer_id` (FK restrict), `points` (com sinal), `kind`
(`earned`|`redeemed`|`referral_bonus`|`adjustment`|`legacy_history`|`legacy_opening`), `description` (N),
`appointment_id` (N), `occurred_at` (N), `created_at`. Saldo = `SUM(points)`.

**coupons** — `code` (U, maiúsculo), `discount_type` (`percent`|`fixed`), `percent_bp` (N), `amount_cents` (N),
`max_uses` (N = ilimitado), `uses_count`, `expires_on` (N), `is_active`, timestamps, SD.
Check: `percent` exige `percent_bp` 1–10000; `fixed` exige `amount_cents` > 0.

**coupon_redemptions** — `coupon_id` (FK restrict), `customer_id` (N), `appointment_id` (N),
`redeemed_at` (N), `created_at`. U(`coupon_id`,`customer_id`) (regra atual: um uso por cliente).

**gift_cards** — `code` (U), `amount_cents`, `status` (`available`|`redeemed`|`expired`|`cancelled`),
`issued_at` (N), `expires_on` (N), `redeemed_at` (N), `redeemed_appointment_id` (N), `purchaser_name` (N), timestamps.

### 2.8 Subscriptions

**plans** — `name`, `price_cents`, `is_active`, `gateway_price_id` (N), timestamps, SD.
**plan_services** — PK (`plan_id`, `service_id`).
**subscriptions** — `customer_id` (restrict), `plan_id` (restrict, N), `status`
(`active`|`cancel_scheduled`|`expired`|`cancelled`), `starts_on`/`ends_on` (N), `cancelled_at` (N),
`gateway` (`manual`|`stripe`), `gateway_customer_id` (N, I), `gateway_subscription_id` (U, N),
`gateway_status` (N), `last_gateway_payment_id` (N), `active_customer_id` (U, N: sentinela de "uma ativa por cliente"), timestamps.
**subscription_payments** (só inclusão) — `subscription_id` (N), `customer_id` (restrict), `plan_id` (N),
`gateway`, `gateway_payment_id` (U, N), `gateway_subscription_id` (N), `amount_cents`, `currency`,
`status`, `kind`, `paid_at` (N), `created_at`.
**gateway_events** — `gateway`, `event_id`, `type` (N), `processed_at` (N). U(`gateway`,`event_id`).

### 2.9 Reviews, Marketing, System

**reviews** — `appointment_id` (U, N), `customer_id` (N), `professional_id` (N), `rating` (1–5), `comment` (N),
`is_featured`, `reviewed_at` (N), timestamps. **review_replies** — `review_id` (cascade), `author_user_id` (N), `body`, `replied_at` (N).

**campaigns** — `channel`, `template` (N), `subject` (N), `segment` (N), `status`, `total_recipients`,
`sent_count`, `failed_count`, `created_by_label` (N), `started_at` (N), `completed_at` (N), timestamps.
Só o **resumo** (D-20). Destinatários ficam no arquivo morto do importador.

**settings** — `key` (U), `value` (json), timestamps. **Nunca** guarda segredo (teste de varredura).

**audit_logs** (só inclusão) — `actor_type` (N), `actor_id` (N), `actor_label` (N), `action`,
`auditable_type`/`auditable_id` (N), `old_values`/`new_values` (json, N), `description` (N),
`ip_address` (N), `created_at`. Campos sensíveis (senha, token, CPF) são mascarados pela trait `Auditable`.

### 2.10 LegacyImport

**import_runs** — `mode` (`dry_run`|`import`), `status` (`running`|`completed`|`failed`|`rolled_back`),
`source_path`, `source_sha256`, `started_at`, `finished_at` (N), `counters` (json), `report_path` (N).
**import_issues** — `import_run_id` (cascade), `source_table`, `source_id` (N), `classification`
(`valid`|`potentially_valid`|`inconsistent`|`duplicate`|`orphan`|`legacy`|`unknown`), `severity`
(`info`|`warning`|`error`), `code`, `message`, `context` (json, N), `needs_decision`, `created_at`.
**legacy_references** — `source_table` + `source_id` (U), `entity_type`, `entity_id`, `checksum`,
`import_run_id` (N), timestamps. I(`entity_type`,`entity_id`).

## 3. Tabelas técnicas do Laravel

`password_reset_tokens` (equipe), `customer_password_reset_tokens` (clientes, Fase 3), `sessions`, `cache`,
`cache_locks`, `jobs`, `job_batches`, `failed_jobs`. Nenhum dado antigo é importado para elas. Os tokens de
redefinição ficam em tabelas separadas porque um cliente e alguém da equipe podem ter o mesmo e-mail.

## 4. Esquema físico

A lista completa de colunas, tipos, índices e FKs é gerada do banco migrado por
`php artisan app:schema-doc` e está no apêndice abaixo (regenerar sempre que houver migration nova).

## Apêndice — esquema físico (gerado)

Gerado por `php artisan app:schema-doc` (60 tabelas de dominio; tabelas tecnicas do Laravel omitidas).
### `advances`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `professional_id` | integer | nao | | professionals.id (restrict) |
| `amount_cents` | integer | nao | | |
| `issued_on` | date | sim | | |
| `reference_month` | varchar | sim | | |
| `description` | varchar | sim | | |
| `commission_payout_id` | integer | sim | | commission_payouts.id (set null) |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
| `kind` | varchar | nao | `advance` | |
| `reverses_advance_id` | integer | sim | | advances.id (restrict) |
| `method` | varchar | sim | | |
| `cash_session_id` | integer | sim | | cash_sessions.id (restrict) |
| `created_by_user_id` | integer | sim | | users.id (set null) |
| `request_key` | varchar | sim | | |
| `occurred_at` | datetime | sim | | |
| `is_legacy` | tinyint | nao | `0` | |
Unicos: (request_key) · (reverses_advance_id)
Indices: (professional_id, commission_payout_id)
### `appointment_adjustments`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `appointment_id` | integer | nao | | appointments.id (cascade) |
| `kind` | varchar | nao | | |
| `amount_cents` | integer | nao | | |
| `coupon_id` | integer | sim | | coupons.id (set null) |
| `gift_card_id` | integer | sim | | gift_cards.id (set null) |
| `subscription_id` | integer | sim | | subscriptions.id (set null) |
| `description` | varchar | sim | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
### `appointment_events`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `appointment_id` | integer | nao | | appointments.id (cascade) |
| `type` | varchar | nao | | |
| `description` | text | sim | | |
| `actor_label` | varchar | sim | | |
| `data` | text | sim | | |
| `occurred_at` | datetime | sim | | |
| `created_at` | datetime | sim | | |
Indices: (appointment_id, occurred_at)
### `appointment_items`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `appointment_id` | integer | nao | | appointments.id (cascade) |
| `item_type` | varchar | nao | | |
| `service_id` | integer | sim | | services.id (set null) |
| `package_id` | integer | sim | | packages.id (set null) |
| `product_id` | integer | sim | | products.id (set null) |
| `name` | varchar | nao | | |
| `quantity` | integer | nao | `1` | |
| `unit_price_cents` | integer | sim | | |
| `total_cents` | integer | sim | | |
| `cost_cents` | integer | sim | | |
| `duration_minutes` | integer | sim | | |
| `price_source` | varchar | nao | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
### `appointment_reminders`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `appointment_id` | integer | nao | | appointments.id (cascade) |
| `kind` | varchar | nao | | |
| `status` | varchar | nao | | |
| `sent_at` | datetime | sim | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
Unicos: (appointment_id, kind)
### `appointments`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `code` | varchar | nao | | |
| `customer_id` | integer | sim | | customers.id (set null) |
| `professional_id` | integer | sim | | professionals.id (restrict) |
| `professional_name` | varchar | sim | | |
| `customer_name` | varchar | nao | | |
| `customer_email` | varchar | sim | | |
| `customer_phone` | varchar | sim | | |
| `starts_at` | datetime | nao | | |
| `ends_at` | datetime | nao | | |
| `status` | varchar | nao | | |
| `source` | varchar | nao | | |
| `notes` | text | sim | | |
| `subtotal_cents` | integer | sim | | |
| `discount_cents` | integer | sim | | |
| `total_cents` | integer | sim | | |
| `cancelled_at` | datetime | sim | | |
| `cancelled_by` | varchar | sim | | |
| `cancellation_reason` | varchar | sim | | |
| `confirmation_requested_at` | datetime | sim | | |
| `confirmed_at` | datetime | sim | | |
| `completed_at` | datetime | sim | | |
| `payment_gateway` | varchar | sim | | |
| `payment_gateway_reference` | varchar | sim | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
| `customer_reschedules` | integer | nao | `0` | |
| `created_by_user_id` | integer | sim | | users.id (set null) |
Unicos: (code)
Indices: (customer_id, starts_at) · (payment_gateway_reference) · (professional_id, starts_at) · (status, starts_at)
### `attendance_consumptions`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `attendance_id` | integer | nao | | attendances.id (cascade) |
| `product_id` | integer | nao | | products.id (restrict) |
| `product_name` | varchar | nao | | |
| `quantity` | integer | nao | | |
| `unit_cost_cents` | integer | sim | | |
| `added_by_user_id` | integer | sim | | users.id (set null) |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
### `attendance_discounts`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `attendance_id` | integer | nao | | attendances.id (cascade) |
| `kind` | varchar | nao | | |
| `type` | varchar | nao | | |
| `percent_bp` | integer | sim | | |
| `fixed_cents` | integer | sim | | |
| `base_cents` | integer | nao | | |
| `amount_cents` | integer | nao | | |
| `reason` | varchar | sim | | |
| `applied_by_user_id` | integer | sim | | users.id (set null) |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
### `attendance_events`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `attendance_id` | integer | nao | | attendances.id (cascade) |
| `type` | varchar | nao | | |
| `description` | varchar | nao | | |
| `actor_label` | varchar | sim | | |
| `data` | text | sim | | |
| `occurred_at` | datetime | nao | | |
### `attendance_items`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `attendance_id` | integer | nao | | attendances.id (cascade) |
| `item_type` | varchar | nao | | |
| `service_id` | integer | sim | | services.id (set null) |
| `package_id` | integer | sim | | packages.id (set null) |
| `product_id` | integer | sim | | products.id (restrict) |
| `appointment_item_id` | integer | sim | | appointment_items.id (set null) |
| `name` | varchar | nao | | |
| `quantity` | integer | nao | `1` | |
| `unit_price_cents` | integer | sim | | |
| `total_cents` | integer | sim | | |
| `duration_minutes` | integer | sim | | |
| `price_source` | varchar | nao | | |
| `cost_cents` | integer | sim | | |
| `added_by_user_id` | integer | sim | | users.id (set null) |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
### `attendances`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `code` | varchar | nao | | |
| `source` | varchar | nao | | |
| `appointment_id` | integer | sim | | appointments.id (restrict) |
| `active_appointment_id` | integer | sim | | |
| `customer_id` | integer | sim | | customers.id (set null) |
| `customer_name` | varchar | nao | | |
| `customer_phone` | varchar | sim | | |
| `professional_id` | integer | sim | | professionals.id (restrict) |
| `professional_name` | varchar | sim | | |
| `status` | varchar | nao | | |
| `opened_at` | datetime | nao | | |
| `started_at` | datetime | sim | | |
| `completed_at` | datetime | sim | | |
| `cancelled_at` | datetime | sim | | |
| `cancellation_reason` | varchar | sim | | |
| `subtotal_cents` | integer | sim | | |
| `discount_cents` | integer | sim | | |
| `total_cents` | integer | sim | | |
| `tip_cents` | integer | sim | | |
| `notes` | text | sim | | |
| `opened_by_user_id` | integer | sim | | users.id (set null) |
| `completed_by_user_id` | integer | sim | | users.id (set null) |
| `cancelled_by_user_id` | integer | sim | | users.id (set null) |
| `completion_key` | varchar | sim | | |
| `version` | integer | nao | `0` | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
Unicos: (active_appointment_id) · (code) · (completion_key)
Indices: (completed_at) · (professional_id, opened_at) · (status, opened_at)
### `audit_logs`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `actor_type` | varchar | sim | | |
| `actor_id` | integer | sim | | |
| `actor_label` | varchar | sim | | |
| `action` | varchar | nao | | |
| `auditable_type` | varchar | sim | | |
| `auditable_id` | integer | sim | | |
| `old_values` | text | sim | | |
| `new_values` | text | sim | | |
| `description` | text | sim | | |
| `ip_address` | varchar | sim | | |
| `created_at` | datetime | sim | | |
Indices: (auditable_type, auditable_id) · (created_at)
### `blocked_slots`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `professional_id` | integer | sim | | professionals.id (cascade) |
| `starts_at` | datetime | nao | | |
| `ends_at` | datetime | nao | | |
| `reason` | varchar | sim | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
| `created_by_user_id` | integer | sim | | users.id (set null) |
Unicos: (professional_id, starts_at)
Indices: (starts_at, ends_at)
### `business_hours`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `weekday` | integer | nao | | |
| `starts_at` | time | nao | | |
| `ends_at` | time | nao | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
Unicos: (weekday, starts_at)
### `campaigns`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `channel` | varchar | nao | | |
| `template` | varchar | sim | | |
| `subject` | varchar | sim | | |
| `segment` | varchar | sim | | |
| `status` | varchar | nao | | |
| `total_recipients` | integer | nao | `0` | |
| `sent_count` | integer | nao | `0` | |
| `failed_count` | integer | nao | `0` | |
| `created_by_label` | varchar | sim | | |
| `started_at` | datetime | sim | | |
| `completed_at` | datetime | sim | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
### `cash_movements`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `cash_session_id` | integer | nao | | cash_sessions.id (restrict) |
| `type` | varchar | nao | | |
| `method` | varchar | nao | | |
| `amount_cents` | integer | nao | | |
| `payment_id` | integer | sim | | payments.id (restrict) |
| `description` | varchar | nao | | |
| `request_key` | varchar | sim | | |
| `created_by_user_id` | integer | sim | | users.id (set null) |
| `occurred_at` | datetime | nao | | |
| `created_at` | datetime | sim | | |
| `commission_payout_id` | integer | sim | | commission_payouts.id (restrict) |
| `advance_id` | integer | sim | | advances.id (restrict) |
Unicos: (payment_id) · (request_key)
Indices: (cash_session_id, method)
### `cash_sessions`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `open_marker` | integer | sim | | |
| `status` | varchar | nao | | |
| `opened_by_user_id` | integer | sim | | users.id (set null) |
| `opened_at` | datetime | nao | | |
| `opening_float_cents` | integer | nao | | |
| `opening_notes` | varchar | sim | | |
| `closed_by_user_id` | integer | sim | | users.id (set null) |
| `closed_at` | datetime | sim | | |
| `expected_cash_cents` | integer | sim | | |
| `counted_cash_cents` | integer | sim | | |
| `difference_cents` | integer | sim | | |
| `closing_notes` | varchar | sim | | |
| `version` | integer | nao | `0` | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
Unicos: (open_marker)
Indices: (opened_at)
### `commission_entries`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `professional_id` | integer | nao | | professionals.id (restrict) |
| `base_cents` | integer | nao | | |
| `rate_bp` | integer | sim | | |
| `amount_cents` | integer | nao | | |
| `rule` | text | sim | | |
| `commission_payout_id` | integer | sim | | commission_payouts.id (set null) |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
| `kind` | varchar | nao | `earned` | |
| `attendance_id` | integer | sim | | attendances.id (restrict) |
| `attendance_item_id` | integer | sim | | attendance_items.id (restrict) |
| `payment_id` | integer | sim | | payments.id (restrict) |
| `commission_rule_id` | integer | sim | | commission_rules.id (restrict) |
| `item_name` | varchar | sim | | |
| `quantity` | integer | sim | | |
| `reason` | varchar | sim | | |
| `created_by_user_id` | integer | sim | | users.id (set null) |
| `request_key` | varchar | sim | | |
| `occurred_at` | datetime | sim | | |
Unicos: (attendance_item_id) · (request_key)
Indices: (professional_id, commission_payout_id)
### `commission_payouts`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `professional_id` | integer | nao | | professionals.id (restrict) |
| `amount_cents` | integer | nao | | |
| `tip_cents` | integer | sim | | |
| `services_total_cents` | integer | sim | | |
| `period_start` | date | sim | | |
| `period_end` | date | sim | | |
| `reference_month` | varchar | sim | | |
| `paid_on` | date | sim | | |
| `notes` | text | sim | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
| `commission_cents` | integer | sim | | |
| `advances_cents` | integer | sim | | |
| `cutoff_at` | datetime | sim | | |
| `method` | varchar | sim | | |
| `cash_session_id` | integer | sim | | cash_sessions.id (restrict) |
| `snapshot` | text | sim | | |
| `created_by_user_id` | integer | sim | | users.id (set null) |
| `request_key` | varchar | sim | | |
| `reversed_at` | datetime | sim | | |
| `reversed_by_user_id` | integer | sim | | users.id (set null) |
| `reversal_reason` | varchar | sim | | |
| `reversal_cash_session_id` | integer | sim | | cash_sessions.id (restrict) |
| `reversal_request_key` | varchar | sim | | |
Unicos: (request_key) · (reversal_request_key)
Indices: (professional_id, paid_on)
### `commission_rules`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `target` | varchar | nao | | |
| `professional_id` | integer | sim | | professionals.id (restrict) |
| `service_id` | integer | sim | | services.id (restrict) |
| `type` | varchar | nao | | |
| `rate_bp` | integer | sim | | |
| `amount_cents` | integer | sim | | |
| `scope_key` | varchar | nao | | |
| `current_scope` | varchar | sim | | |
| `starts_at` | datetime | nao | | |
| `ends_at` | datetime | sim | | |
| `reason` | varchar | sim | | |
| `created_by_user_id` | integer | sim | | users.id (set null) |
| `ended_by_user_id` | integer | sim | | users.id (set null) |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
Unicos: (current_scope)
Indices: (scope_key, starts_at)
### `consent_records`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `customer_id` | integer | sim | | customers.id (set null) |
| `email` | varchar | sim | | |
| `purpose` | varchar | nao | | |
| `action` | varchar | nao | | |
| `source` | varchar | nao | | |
| `occurred_at` | datetime | sim | | |
| `evidence` | varchar | sim | | |
| `created_at` | datetime | sim | | |
Indices: (customer_id, purpose) · (email)
### `coupon_redemptions`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `coupon_id` | integer | nao | | coupons.id (restrict) |
| `customer_id` | integer | sim | | customers.id (set null) |
| `appointment_id` | integer | sim | | appointments.id (set null) |
| `redeemed_at` | datetime | sim | | |
| `created_at` | datetime | sim | | |
Unicos: (coupon_id, customer_id)
### `coupons`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `code` | varchar | nao | | |
| `discount_type` | varchar | nao | | |
| `percent_bp` | integer | sim | | |
| `amount_cents` | integer | sim | | |
| `max_uses` | integer | sim | | |
| `uses_count` | integer | nao | `0` | |
| `expires_on` | date | sim | | |
| `is_active` | tinyint | nao | `1` | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
| `deleted_at` | datetime | sim | | |
Unicos: (code)
### `customer_favorite_professionals`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `customer_id` | integer | nao | | customers.id (cascade) |
| `professional_id` | integer | nao | | professionals.id (cascade) |
| `created_at` | datetime | sim | | |
### `customer_login_tokens`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `customer_id` | integer | nao | | customers.id (cascade) |
| `token_hash` | varchar | nao | | |
| `expires_at` | datetime | nao | | |
| `used_at` | datetime | sim | | |
| `requested_ip` | varchar | sim | | |
| `created_at` | datetime | sim | | |
Unicos: (token_hash)
Indices: (customer_id, used_at)
### `customer_merge_candidates`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `customer_id` | integer | nao | | customers.id (cascade) |
| `duplicate_customer_id` | integer | sim | | customers.id (cascade) |
| `match_field` | varchar | nao | | |
| `match_value` | varchar | nao | | |
| `status` | varchar | nao | `pending` | |
| `import_run_id` | integer | sim | | import_runs.id (set null) |
| `notes` | text | sim | | |
| `resolved_by_user_id` | integer | sim | | users.id (set null) |
| `resolved_at` | datetime | sim | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
Unicos: (customer_id, duplicate_customer_id, match_field)
Indices: (status)
### `customer_notes`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `customer_id` | integer | nao | | customers.id (cascade) |
| `author_user_id` | integer | sim | | users.id (set null) |
| `author_label` | varchar | sim | | |
| `visibility` | varchar | nao | `team` | |
| `body` | text | nao | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
### `customer_notifications`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `customer_id` | integer | nao | | customers.id (cascade) |
| `message` | text | nao | | |
| `read_at` | datetime | sim | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
Indices: (customer_id, read_at)
### `customers`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `public_id` | varchar | nao | | |
| `name` | varchar | nao | | |
| `email` | varchar | sim | | |
| `email_verified_at` | datetime | sim | | |
| `phone` | varchar | sim | | |
| `cpf` | varchar | sim | | |
| `password` | varchar | sim | | |
| `remember_token` | varchar | sim | | |
| `birth_date` | date | sim | | |
| `photo_path` | varchar | sim | | |
| `status` | varchar | nao | `active` | |
| `referral_code` | varchar | sim | | |
| `referred_by_customer_id` | integer | sim | | customers.id (set null) |
| `marketing_email_consent` | varchar | nao | `unknown` | |
| `marketing_consent_updated_at` | datetime | sim | | |
| `merged_into_customer_id` | integer | sim | | customers.id (set null) |
| `anonymized_at` | datetime | sim | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
| `deleted_at` | datetime | sim | | |
| `password_changed_at` | datetime | sim | | |
| `last_login_at` | datetime | sim | | |
Unicos: (cpf) · (email) · (phone) · (public_id) · (referral_code)
Indices: (name)
### `email_suppressions`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `email` | varchar | nao | | |
| `reason` | varchar | nao | | |
| `customer_id` | integer | sim | | customers.id (set null) |
| `suppressed_at` | datetime | sim | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
Unicos: (email)
### `expenses`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `description` | varchar | nao | | |
| `category` | varchar | sim | | |
| `amount_cents` | integer | nao | | |
| `due_on` | date | sim | | |
| `paid_on` | date | sim | | |
| `status` | varchar | nao | | |
| `is_recurring` | tinyint | nao | `0` | |
| `recurrence_parent_id` | integer | sim | | expenses.id (set null) |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
| `deleted_at` | datetime | sim | | |
Indices: (status, due_on)
### `financial_goals`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `professional_id` | integer | sim | | professionals.id (cascade) |
| `period` | varchar | nao | | |
| `amount_cents` | integer | nao | | |
| `effective_from` | date | sim | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
### `gateway_events`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `gateway` | varchar | nao | | |
| `event_id` | varchar | nao | | |
| `type` | varchar | sim | | |
| `processed_at` | datetime | sim | | |
Unicos: (gateway, event_id)
### `gift_cards`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `code` | varchar | nao | | |
| `amount_cents` | integer | nao | | |
| `status` | varchar | nao | | |
| `issued_at` | datetime | sim | | |
| `expires_on` | date | sim | | |
| `redeemed_at` | datetime | sim | | |
| `purchaser_name` | varchar | sim | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
| `redeemed_appointment_id` | integer | sim | | appointments.id (set null) |
Unicos: (code)
### `import_issues`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `import_run_id` | integer | nao | | import_runs.id (cascade) |
| `source_table` | varchar | nao | | |
| `source_id` | varchar | sim | | |
| `classification` | varchar | nao | | |
| `severity` | varchar | nao | | |
| `code` | varchar | nao | | |
| `message` | text | nao | | |
| `context` | text | sim | | |
| `needs_decision` | tinyint | nao | `0` | |
| `created_at` | datetime | sim | | |
Indices: (import_run_id, code) · (source_table, source_id)
### `import_runs`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `mode` | varchar | nao | | |
| `status` | varchar | nao | | |
| `source_path` | varchar | nao | | |
| `source_sha256` | varchar | nao | | |
| `started_at` | datetime | nao | | |
| `finished_at` | datetime | sim | | |
| `counters` | text | sim | | |
| `report_path` | varchar | sim | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
### `legacy_references`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `source_table` | varchar | nao | | |
| `source_id` | varchar | nao | | |
| `entity_type` | varchar | nao | | |
| `entity_id` | integer | nao | | |
| `checksum` | varchar | nao | | |
| `import_run_id` | integer | sim | | import_runs.id (set null) |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
Unicos: (source_table, source_id)
Indices: (entity_type, entity_id)
### `loyalty_entries`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `customer_id` | integer | nao | | customers.id (restrict) |
| `points` | integer | nao | | |
| `kind` | varchar | nao | | |
| `description` | varchar | sim | | |
| `appointment_id` | integer | sim | | appointments.id (set null) |
| `occurred_at` | datetime | sim | | |
| `created_at` | datetime | sim | | |
Indices: (customer_id, occurred_at)
### `package_items`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `package_id` | integer | nao | | packages.id (cascade) |
| `service_id` | integer | nao | | services.id (restrict) |
| `quantity` | integer | nao | `1` | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
Unicos: (package_id, service_id)
### `packages`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `category_id` | integer | sim | | service_categories.id (set null) |
| `name` | varchar | nao | | |
| `price_cents` | integer | nao | | |
| `is_active` | tinyint | nao | `1` | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
| `deleted_at` | datetime | sim | | |
### `payments`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `customer_id` | integer | sim | | customers.id (set null) |
| `kind` | varchar | nao | `payment` | |
| `refunds_payment_id` | integer | sim | | payments.id (restrict) |
| `method` | varchar | nao | | |
| `amount_cents` | integer | nao | | |
| `tip_cents` | integer | nao | `0` | |
| `amount_source` | varchar | nao | | |
| `paid_at` | datetime | sim | | |
| `received_by_label` | varchar | sim | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
| `attendance_id` | integer | sim | | attendances.id (restrict) |
| `cash_session_id` | integer | sim | | cash_sessions.id (restrict) |
| `received_by_user_id` | integer | sim | | users.id (set null) |
| `reason` | varchar | sim | | |
| `request_key` | varchar | sim | | |
Unicos: (request_key)
Indices: (attendance_id) · (paid_at)
### `plan_services`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `plan_id` | integer | nao | | plans.id (cascade) |
| `service_id` | integer | nao | | services.id (restrict) |
### `plans`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `name` | varchar | nao | | |
| `price_cents` | integer | nao | | |
| `is_active` | tinyint | nao | `1` | |
| `gateway_price_id` | varchar | sim | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
| `deleted_at` | datetime | sim | | |
### `products`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `category_id` | integer | sim | | service_categories.id (set null) |
| `name` | varchar | nao | | |
| `price_cents` | integer | sim | | |
| `cost_cents` | integer | sim | | |
| `min_stock` | integer | sim | | |
| `is_active` | tinyint | nao | `1` | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
| `deleted_at` | datetime | sim | | |
| `sku` | varchar | sim | | |
| `description` | text | sim | | |
| `unit` | varchar | nao | `un` | |
| `lock_version` | integer | nao | `0` | |
| `stock_version` | integer | nao | `0` | |
Unicos: (sku)
### `professional_package`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `professional_id` | integer | nao | | professionals.id (cascade) |
| `package_id` | integer | nao | | packages.id (cascade) |
### `professional_service`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `professional_id` | integer | nao | | professionals.id (cascade) |
| `service_id` | integer | nao | | services.id (cascade) |
### `professionals`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `user_id` | integer | sim | | users.id (set null) |
| `display_name` | varchar | nao | | |
| `photo_path` | varchar | sim | | |
| `is_active` | tinyint | nao | `1` | |
| `is_bookable` | tinyint | nao | `1` | |
| `sort_order` | integer | nao | `0` | |
| `subscription_commission_mode` | varchar | nao | `default` | |
| `subscription_commission_rate_bp` | integer | sim | | |
| `subscription_commission_amount_cents` | integer | sim | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
| `deleted_at` | datetime | sim | | |
| `slug` | varchar | sim | | |
| `headline` | varchar | sim | | |
| `bio` | text | sim | | |
| `is_public` | tinyint | nao | `1` | |
| `is_featured` | tinyint | nao | `0` | |
| `lock_version` | integer | nao | `0` | |
| `schedule_version` | integer | nao | `0` | |
| `ledger_version` | integer | nao | `0` | |
Unicos: (slug) · (user_id)
Indices: (is_active, sort_order)
### `review_replies`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `review_id` | integer | nao | | reviews.id (cascade) |
| `author_user_id` | integer | sim | | users.id (set null) |
| `body` | text | nao | | |
| `replied_at` | datetime | sim | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
### `reviews`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `appointment_id` | integer | sim | | appointments.id (set null) |
| `customer_id` | integer | sim | | customers.id (set null) |
| `professional_id` | integer | sim | | professionals.id (set null) |
| `rating` | integer | nao | | |
| `comment` | text | sim | | |
| `is_featured` | tinyint | nao | `0` | |
| `reviewed_at` | datetime | sim | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
Unicos: (appointment_id)
Indices: (professional_id, reviewed_at)
### `schedule_breaks`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `professional_id` | integer | nao | | professionals.id (cascade) |
| `weekday` | integer | sim | | |
| `starts_at` | time | nao | | |
| `ends_at` | time | nao | | |
| `label` | varchar | sim | | |
| `is_active` | tinyint | nao | `1` | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
### `service_categories`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `name` | varchar | nao | | |
| `sort_order` | integer | nao | `0` | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
| `deleted_at` | datetime | sim | | |
| `slug` | varchar | sim | | |
| `description` | text | sim | | |
| `is_active` | tinyint | nao | `1` | |
| `lock_version` | integer | nao | `0` | |
Unicos: (slug)
### `services`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `category_id` | integer | sim | | service_categories.id (set null) |
| `name` | varchar | nao | | |
| `description` | text | sim | | |
| `duration_minutes` | integer | nao | | |
| `price_cents` | integer | nao | | |
| `is_active` | tinyint | nao | `1` | |
| `sort_order` | integer | nao | `0` | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
| `deleted_at` | datetime | sim | | |
| `slug` | varchar | sim | | |
| `is_public` | tinyint | nao | `1` | |
| `is_featured` | tinyint | nao | `0` | |
| `image_path` | varchar | sim | | |
| `lock_version` | integer | nao | `0` | |
Unicos: (slug)
Indices: (is_active, sort_order)
### `settings`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `key` | varchar | nao | | |
| `value` | text | sim | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
Unicos: (key)
### `stock_movements`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `product_id` | integer | nao | | products.id (restrict) |
| `quantity` | integer | nao | | |
| `kind` | varchar | nao | | |
| `reason` | varchar | sim | | |
| `actor_label` | varchar | sim | | |
| `occurred_at` | datetime | sim | | |
| `created_at` | datetime | sim | | |
| `attendance_id` | integer | sim | | attendances.id (restrict) |
| `reverses_movement_id` | integer | sim | | stock_movements.id (restrict) |
| `balance_after` | integer | sim | | |
| `unit_cost_cents` | integer | sim | | |
| `created_by_user_id` | integer | sim | | users.id (set null) |
| `request_key` | varchar | sim | | |
Unicos: (request_key) · (reverses_movement_id)
Indices: (attendance_id) · (product_id, occurred_at)
### `subscription_payments`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `subscription_id` | integer | sim | | subscriptions.id (restrict) |
| `customer_id` | integer | nao | | customers.id (restrict) |
| `plan_id` | integer | sim | | plans.id (restrict) |
| `gateway` | varchar | nao | | |
| `gateway_payment_id` | varchar | sim | | |
| `gateway_subscription_id` | varchar | sim | | |
| `amount_cents` | integer | nao | | |
| `currency` | varchar | nao | `BRL` | |
| `status` | varchar | nao | | |
| `kind` | varchar | nao | | |
| `paid_at` | datetime | sim | | |
| `created_at` | datetime | sim | | |
Unicos: (gateway_payment_id)
Indices: (customer_id, paid_at)
### `subscriptions`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `customer_id` | integer | nao | | customers.id (restrict) |
| `plan_id` | integer | sim | | plans.id (restrict) |
| `status` | varchar | nao | | |
| `starts_on` | date | sim | | |
| `ends_on` | date | sim | | |
| `cancelled_at` | datetime | sim | | |
| `gateway` | varchar | nao | `manual` | |
| `gateway_customer_id` | varchar | sim | | |
| `gateway_subscription_id` | varchar | sim | | |
| `gateway_status` | varchar | sim | | |
| `last_gateway_payment_id` | varchar | sim | | |
| `active_customer_id` | integer | sim | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
Unicos: (active_customer_id) · (gateway_subscription_id)
Indices: (customer_id, status) · (gateway_customer_id)
### `time_off`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `professional_id` | integer | nao | | professionals.id (cascade) |
| `starts_on` | date | nao | | |
| `ends_on` | date | nao | | |
| `kind` | varchar | nao | | |
| `reason` | varchar | sim | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
| `created_by_user_id` | integer | sim | | users.id (set null) |
Indices: (professional_id, starts_on)
### `tip_entries`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `professional_id` | integer | nao | | professionals.id (restrict) |
| `attendance_id` | integer | sim | | attendances.id (restrict) |
| `payment_id` | integer | sim | | payments.id (restrict) |
| `kind` | varchar | nao | | |
| `amount_cents` | integer | nao | | |
| `reason` | varchar | sim | | |
| `created_by_user_id` | integer | sim | | users.id (set null) |
| `request_key` | varchar | sim | | |
| `commission_payout_id` | integer | sim | | commission_payouts.id (set null) |
| `occurred_at` | datetime | nao | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
Unicos: (payment_id) · (request_key)
Indices: (professional_id, commission_payout_id)
### `users`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `name` | varchar | nao | | |
| `email` | varchar | sim | | |
| `username` | varchar | sim | | |
| `email_verified_at` | datetime | sim | | |
| `password` | varchar | sim | | |
| `role` | varchar | nao | | |
| `is_active` | tinyint | nao | `1` | |
| `last_login_at` | datetime | sim | | |
| `remember_token` | varchar | sim | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
| `must_change_password` | tinyint | nao | `0` | |
| `password_changed_at` | datetime | sim | | |
Unicos: (email) · (username)
### `working_hours`
| Coluna | Tipo | Nulo | Padrao | FK |
|---|---|---|---|---|
| `id` | integer | nao | | |
| `professional_id` | integer | nao | | professionals.id (cascade) |
| `weekday` | integer | nao | | |
| `starts_at` | time | nao | | |
| `ends_at` | time | nao | | |
| `created_at` | datetime | sim | | |
| `updated_at` | datetime | sim | | |
Unicos: (professional_id, weekday, starts_at)
