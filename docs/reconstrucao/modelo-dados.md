# Modelo de dados do novo sistema (Fase 2)

> Status: **definitivo para a Fase 2**. Implementado em `novo-sistema/database/migrations`
> (2026_09_*). Mudanças posteriores entram por novas migrations, nunca editando as existentes
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
| **Scheduling** | `appointments`, `appointment_items`, `appointment_adjustments`, `appointment_events`, `appointment_reminders` | O atendimento: quando, com quem, o que foi feito (com preço congelado), descontos, linha do tempo e lembretes |
| **Finance** | `payments`, `commission_entries`, `commission_payouts`, `advances`, `expenses`, `financial_goals` | Dinheiro que entrou, comissões calculadas e pagas, vales, despesas e metas |
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
           │                 ├─< payments
           │                 └── 0..1 reviews ──< review_replies
           ├─< subscriptions ──> plans ──< plan_services >── services
           │        └─< subscription_payments
           ├─< loyalty_entries (saldo = soma)
           ├─< coupon_redemptions >── coupons
           ├─< customer_notes / customer_favorite_professionals / customer_notifications
           ├─< consent_records ; email_suppressions (também sem cliente)
           ├── referred_by ──> customers (indicação)
           └─< customer_merge_candidates (pares suspeitos)

products ──< stock_movements (saldo = soma)       packages ──< package_items >── services
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
   **pontos-base** (`*_bp`, 10000 = 100 %). Conversões só pelo value object `Money`/`BasisPoints`.
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
`StaffRole`), `is_active`, `last_login_at` (N), `remember_token`, timestamps.
- `email` passou a ser anulável porque barbeiros antigos entram por **usuário** (D-10 continua
  pendente). Regra: pelo menos um de `email`/`username` preenchido (garantido no model e testado).
- Senha: hash bcrypt/argon aceito como está; rehash transparente no login (ver
  [importador.md](importador.md#senhas)).

### 2.2 Customers

**customers** — `id`, `public_id` (U, ULID), `name`, `email` (U, N, minúsculo), `email_verified_at` (N),
`phone` (U, N, E.164 `+55…`), `cpf` (U, N, 11 dígitos), `password` (N), `remember_token`,
`birth_date` (N), `photo_path` (N), `status` (`active`|`inactive`), `referral_code` (U, N),
`referred_by_customer_id` (FK customers, **null on delete**, N), `marketing_email_consent`
(`unknown`|`granted`|`revoked`), `marketing_consent_updated_at` (N), `merged_into_customer_id`
(FK customers, N), `anonymized_at` (N), timestamps, **SD**.

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
`is_active`, `is_bookable`, `sort_order`, `commission_rate_bp` (0–10000), `commission_on_products`,
`subscription_commission_mode` (`default`|`percent`|`fixed`|`none`), `subscription_commission_rate_bp` (N),
`subscription_commission_amount_cents` (N), timestamps, **SD**.

**professional_service** — PK (`professional_id` FK cascade, `service_id` FK cascade). Combos que
o profissional atende: `professional_package` com a mesma forma.

**working_hours** — `professional_id` (FK cascade), `weekday` (0=domingo … 6), `starts_at`/`ends_at`
(`time`), U(`professional_id`,`weekday`,`starts_at`). Vários intervalos por dia são permitidos.

**schedule_breaks** (pausa recorrente, ex.: almoço) — `professional_id`, `weekday` (N = todos os dias),
`starts_at`, `ends_at`, `is_active`, `label`.

**time_off** (folga, férias, atestado) — `professional_id`, `starts_on`, `ends_on`, `kind`, `reason` (N), timestamps.
Check: `ends_on >= starts_on`.

**blocked_slots** (bloqueio pontual) — `professional_id`, `starts_at`, `ends_at` (UTC), `reason` (N).
U(`professional_id`,`starts_at`).

### 2.4 Catalog

**service_categories** — `name`, `sort_order`, SD.
**services** — `category_id` (FK null on delete, N), `name`, `description` (N), `duration_minutes` (>0),
`price_cents` (≥0), `is_active`, `sort_order`, timestamps, **SD**.
**packages** (combos) — `category_id` (N), `name`, `price_cents`, `is_active`, SD.
**package_items** — `package_id` (FK cascade), `service_id` (FK restrict), `quantity`. U(`package_id`,`service_id`).
Duração do combo = soma das durações dos serviços (regra do sistema atual).
**products** — `category_id` (N), `name`, `price_cents`, `cost_cents` (N), `min_stock` (N), `is_active`, SD.
**Sem coluna de saldo.**
**stock_movements** (só inclusão) — `product_id` (FK restrict), `quantity` (com sinal), `kind`
(`purchase`|`sale`|`adjustment`|`loss`|`legacy_opening`), `reason` (N), `appointment_id` (N),
`actor_label` (N), `occurred_at` (N), `created_at`.

### 2.5 Scheduling

**appointments** — `code` (U, ex.: `AG-7F3K2Q`), `customer_id` (FK null on delete, N),
`professional_id` (FK **restrict**, N), `professional_name` (snapshot), `customer_name`,
`customer_email` (N), `customer_phone` (N) (snapshot do contato **no momento** do agendamento),
`starts_at`, `ends_at` (UTC, `ends_at > starts_at`), `status` (enum abaixo), `source`
(`online`|`staff`|`chatbot`|`legacy`), `notes` (N), `subtotal_cents`/`discount_cents`/`total_cents` (N:
nulos quando algum preço é desconhecido), `cancelled_at`, `cancelled_by` (`customer`|`staff`|`system`),
`cancellation_reason` (N), `confirmation_requested_at` (N), `confirmed_at` (N), `completed_at` (N),
`payment_gateway` (N), `payment_gateway_reference` (N), `created_at`, `updated_at`. I(`professional_id`,`starts_at`),
I(`customer_id`,`starts_at`), I(`status`,`starts_at`).

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

### 2.6 Finance

**payments** (só inclusão) — `appointment_id` (FK restrict, N), `customer_id` (N), `kind` (`payment`|`refund`),
`refunds_payment_id` (FK payments, N), `method` (`cash`|`pix`|`debit_card`|`credit_card`|`other`|`unknown`),
`amount_cents` (>0), `tip_cents` (≥0), `amount_source` (`recorded`|`legacy_estimated`), `paid_at` (N),
`received_by_label` (N), `created_at`.

**commission_entries** (só inclusão) — `professional_id` (restrict), `appointment_id` (restrict, N),
`base_cents`, `rate_bp` (N), `amount_cents` (com sinal: estorno negativo), `rule` (snapshot da regra),
`commission_payout_id` (N), `created_at`. **Não** há lançamentos para atendimentos antigos (o
sistema atual não guardava a comissão; ver [importador.md](importador.md)).

**commission_payouts** — `professional_id` (restrict), `amount_cents`, `tip_cents` (N), `services_total_cents` (N),
`period_start`/`period_end` (N), `reference_month` (N, `YYYY-MM`), `paid_on` (N), `notes` (N), timestamps.

**advances** (vales) — `professional_id` (restrict), `amount_cents`, `issued_on` (N), `reference_month` (N),
`description` (N), `commission_payout_id` (N), timestamps.

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

`password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`
(criadas na Fase 1). Nenhum dado antigo é importado para elas.

## 4. Esquema físico

A lista completa de colunas, tipos, índices e FKs é gerada do banco real por
`php artisan legacy:schema-doc` e está no [apêndice](#apendice-esquema-fisico-gerado) deste documento.
