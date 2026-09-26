# Estratégia de histórico (dados que não podem mudar depois de acontecer)

> Princípio da [arquitetura nova](arquitetura-nova.md): o que **aconteceu** é fotografado e não se edita;
> corrige-se com um novo lançamento. O que é **cadastro** pode mudar, mas não é apagado enquanto houver
> histórico apontando para ele.

## 1. O problema no sistema atual

| Problema | Consequência |
|---|---|
| Agendamento não guarda preço nem duração (B-02) | Mudar o preço de um serviço muda o faturamento de meses passados e as comissões já pagas |
| Excluir serviço/barbeiro apaga de verdade (B-05) | Relatórios perdem linhas; agendamentos ficam órfãos |
| Editar o perfil do cliente reescreve nome/telefone dos agendamentos antigos (B-10) | O histórico não mostra o que valia no dia |
| Saldo de pontos e de estoque são números soltos (B-09) | O saldo diverge do histórico sem ninguém perceber |
| Pagamento não existe como registro: forma e gorjeta são colunas do agendamento | Não há estorno, nem mais de uma forma de pagamento |
| Uma assinatura por cliente | Renovar ou trocar de plano apaga a anterior |

## 2. Classificação das entidades

| Tipo | Entidades | Regra |
|---|---|---|
| **Fotografia imutável** (só inclusão) | `appointment_items`, `payments`, `commission_entries`, `loyalty_entries`, `stock_movements`, `subscription_payments`, `appointment_events`, `consent_records`, `audit_logs` | Trait `AppendOnly`: editar ou apagar lança `DomainRuleViolation` (R-HIST). Correção = novo registro (estorno, ajuste). Exceção declarada: `commission_entries.commission_payout_id` (vincula o lançamento ao pagamento da comissão) |
| **Snapshot dentro do registro** | `appointments.customer_name/email/phone`, `appointments.professional_name`, `appointments.subtotal/discount/total_cents` | Gravados na criação/fechamento; o cadastro de origem pode mudar sem afetá-los |
| **Ciclo de vida controlado** | `appointments.status`, `subscriptions.status` | Só transições permitidas; estados finais não voltam |
| **Cadastro com soft delete** | `services`, `packages`, `products`, `professionals`, `customers`, `plans`, `coupons`, `expenses`, `service_categories` | "Excluir" = soft delete. Com histórico financeiro a exclusão física é bloqueada por FK `restrict` |
| **Cadastro editável** | `working_hours`, `schedule_breaks`, `time_off`, `blocked_slots`, `settings`, `customer_notes` | Editável; mudanças relevantes vão para a auditoria (Fase 3 em diante) |

## 3. Regras por assunto

**Preço.** O item guarda nome, preço unitário, quantidade, total, duração e a origem do preço
(`price_source`: `catalog_at_booking`, `recorded`, `legacy_catalog_estimate`, `legacy_unknown`). A FK para o
catálogo é `set null`: o item sobrevive mesmo se o serviço for apagado fisicamente.

**Desconto.** `appointment_adjustments` guarda o valor original e a origem (cupom, vale, fidelidade…); o total
fotografado aplica o desconto limitado aos serviços (regra atual). O desconto original nunca é reescrito.

**Pagamento.** Só inclusão. Estorno é um pagamento `kind = refund` que aponta o original; a soma com sinal
(`signedAmount`) dá o valor líquido. Gorjeta separada do valor.

**Comissão.** Calculada no fechamento do atendimento (Fase 8) com a regra fotografada em `rule` (percentual,
modo de assinatura). Mudar o percentual do profissional depois não altera lançamentos antigos. Pagamento de
comissão (`commission_payouts`) agrupa lançamentos e vales. As comissões **antigas** entram só como pagamentos
(`comissoes_pagas`), sem lançamentos por atendimento, porque o sistema antigo nunca as registrou.

**Pontos e estoque.** Razões: o saldo é sempre a soma. Na migração, a diferença entre o saldo antigo e a soma
do histórico antigo vira **um** lançamento `legacy_opening` ("ajuste de migração"), para o saldo novo ser
idêntico ao que o cliente/dono via.

**Assinatura.** Histórico completo (várias por cliente), com no máximo uma vigente (sentinela
`active_customer_id`). Pagamentos e eventos de webhook preservam os IDs do gateway.

**Agendamento.** Contato e profissional fotografados. Status só pelas transições do enum. Concluído é final:
corrigir valor de um atendimento concluído = estorno/ajuste, nunca reabrir.

**Cliente.** Soft delete; anonimização LGPD (Fase 3) apaga dados pessoais do cadastro e preserva os
agendamentos como "sem conta" (FK `set null`), mantendo valores para a contabilidade.

**Auditoria.** `Auditable` registra criação, alteração e exclusão de clientes e agendamentos, com antes/depois,
sem senha/token e com CPF mascarado. A trilha é só inclusão.

## 4. Onde isso é garantido

Model (eventos), banco (FKs, tipos) e `IntegrityChecker` (consultas que conferem o banco inteiro, inclusive o
que o importador gravou pelo query builder). Detalhe e testes: [regras-dados.md](regras-dados.md), regras 15 a 27 e 33 a 35.

## 5. Limite conhecido

A proteção de imutabilidade vale para o caminho Eloquent. Consultas SQL diretas (usadas só pelo importador,
que apenas insere) não passam pelos models. Para reforçar no futuro, dá para adicionar triggers no banco que
recusem `UPDATE`/`DELETE` nas tabelas só-inclusão. Não foi feito agora porque exige SQL específico por banco
(SQLite × MySQL) e a D-02 ainda é provisória.
