# Assinaturas (Fase 9)

Planos mensais pagos pelo Stripe. O sistema guarda o **próprio histórico** (assinatura, eventos, pagamentos,
reembolsos); o Stripe é o meio de pagamento, não a fonte única de verdade.

Documentos da fase: [planos.md](planos.md), [beneficios.md](beneficios.md), [stripe.md](stripe.md),
[webhooks.md](webhooks.md), [cancelamentos.md](cancelamentos.md), [reembolsos.md](reembolsos.md).

## 1. Decisões do dono (início da Fase 9)

| # | Decisão |
|---|---|
| D-03 | Assinaturas **mantidas**, com Stripe (aprovação da Fase 9) |
| D-44 | Benefício como hoje: os serviços incluídos no plano saem de graça, **sem limite de uso** |
| D-45 | Benefício combina com os outros descontos pela regra da Fase 8: **um desconto só, vale o maior** |
| D-46 | Comissão de serviço coberto como hoje: sobre o **preço de tabela**, com a regra de assinante do profissional (percentual, valor fixo por atendimento ou sem comissão); a mensalidade nunca gera comissão |
| D-47 | Adesão como hoje, **no agendamento**, pagando no Stripe Checkout; e a equipe pode gerar no painel um **link de pagamento** para o cliente; a assinatura ativa quando o Stripe confirma o pagamento |

## 2. Modelo

| Tabela | Conteúdo |
|---|---|
| `plans` / `plan_versions` / `plan_version_services` | Plano e versões (preço, mensal, serviços incluídos). Ver [planos.md](planos.md) |
| `subscriptions` | Uma por adesão (histórico completo; **uma vigente por cliente**, sentinela `active_customer_id`). Versão contratada, origem, IDs do Stripe (cliente, assinatura, sessão), estado, **direito até** (`ends_on`), cancelamento (quem, quando, por quê, efetivo), última fotografia do Stripe aplicada, trava (`version`) |
| `subscription_events` | Histórico só de inclusão: criada, pagamento iniciado, ativada, renovada, pagamento recusado/pendente, recuperada, cancelamento agendado, reativada, cancelada, expirada, reembolso, benefício aplicado |
| `subscription_payments` | Pagamentos **recebidos** (um por fatura paga; só inclusão) |
| `subscription_refunds` | Reembolsos (só inclusão; apontam o pagamento) |
| `gateway_events` | Evento do Stripe guardado inteiro, uma vez ([webhooks.md](webhooks.md)) |

## 3. Estados e transições

O **estado** diz em que ponto da cobrança a assinatura está. O **direito ao benefício** é outra coisa (§4).

| Estado | Significa | Vem do Stripe |
|---|---|---|
| `pending` Aguardando pagamento | Link/checkout aberto; nada pago | `incomplete` |
| `active` Ativa | Paga e renovando | `active`, `trialing` (sem cancelamento) |
| `past_due` Pagamento em atraso | Renovação recusada; o Stripe tenta de novo | `past_due`, `unpaid`, `paused` |
| `cancel_scheduled` Cancelamento agendado | Não renova; benefício até o fim pago | `active` com `cancel_at_period_end` |
| `cancelled` Cancelada | Encerrada (imediata, no fim do período ou por inadimplência) | `canceled` |
| `expired` Expirada | Manual vencida sem renovação, ou adesão nunca paga | `incomplete_expired`; rotina diária |

Transições permitidas (`SubscriptionStatus::canTransitionTo`; o model recusa as outras):

```text
pending ──▶ active | past_due | cancel_scheduled | cancelled | expired
active ───▶ past_due | cancel_scheduled | cancelled | expired
past_due ─▶ active (recuperada) | cancel_scheduled | cancelled | expired
cancel_scheduled ─▶ active (reativada) | past_due | cancelled | expired
cancelled, expired: finais (nova adesão = nova assinatura)
```

**Nenhuma requisição HTTP muda o estado direto.** O estado muda só por: evento do Stripe consolidado
(`SubscriptionLifecycle`), ação da equipe ou do cliente (`SubscriptionManager`, que vai primeiro ao Stripe e
consolida a resposta) e a rotina diária (`app:subscriptions-expire`).

## 4. Direito ao benefício

`SubscriptionBenefits` decide, separado do estado:

- Vem da **data paga** (`ends_on`, inclusive). Só pagamento confirmado (fatura paga) estende essa data; o estado
  "ativa" sozinho não dá benefício.
- Vale em uma data D se D ≤ `ends_on`. Assinatura **ativa** tem **1 dia de tolerância** (R-28, tempo de a
  renovação chegar). Em atraso, com cancelamento agendado ou encerrada: sem tolerância.
- **Aguardando pagamento** nunca tem direito.
- Cancelamento **imediato** encurta `ends_on` para o dia anterior; no fim do período, nada muda.
- Evento intermediário fora de ordem nunca revoga: a fotografia mais antiga é ignorada e o direito só
  aumenta com fatura paga.
- Detalhes do benefício (o que sai de graça, combinação com cupom, pontos, aniversário, vale-presente):
  [beneficios.md](beneficios.md).

## 5. Adesão (D-47)

1. **No agendamento (site):** a confirmação mostra os planos abertos (só com o Stripe configurado, cliente
   sem assinatura vigente e com e-mail; R-25). Escolhido um plano, o agendamento é gravado **pelo valor
   normal** e a assinatura nasce "aguardando pagamento", ligada ao agendamento. O cliente vai para
   **Minha conta › Assinatura** e paga pelo botão do Stripe.
2. **Pelo painel:** "Gerar link de assinatura" (`subscriptions.create`): busca o cliente, escolhe o plano; o
   link fica na tela para copiar e na conta do cliente.
3. Um novo link expira o anterior (no Stripe e aqui): nunca há dois links pagáveis.
4. Quando o Stripe confirma (fatura paga), a assinatura **ativa**, o direito vai até o fim do período pago e,
   se veio do agendamento, o **benefício entra no agendamento** (vale o maior), com registro no histórico do
   agendamento e da assinatura.
5. Link não pago: expira no Stripe (`checkout.session.expired`) ou na rotina diária.

## 6. Rotina diária

`app:subscriptions-expire` (agendada 03:10, idempotente): expira links vencidos e assinaturas **manuais**
(importadas) que passaram do fim pago + tolerância; cancelamento agendado manual vira cancelada no fim. As do
Stripe seguem o estado do Stripe (o benefício já acaba pela data).

## 7. Telas

| Tela | Quem |
|---|---|
| Painel › Assinaturas (com benefício hoje, MRR, situação, lista e busca) | `subscriptions.view` |
| Assinatura (dados, link de pagamento, histórico, pagamentos, cancelar, reativar, reembolsar) | `subscriptions.view`; pagamentos `subscriptions.payments`; histórico `subscriptions.history`; ações pela habilidade de cada uma |
| Gerar link de assinatura | `subscriptions.create` |
| Eventos do Stripe | `subscriptions.history` |
| Planos | `plans.manage` (só o proprietário) |
| Minha conta › Assinatura (situação, benefício, pagamentos, pagar link, cancelar renovação, manter assinatura) | o próprio cliente |

**MRR** (receita mensal recorrente): soma do preço contratado das assinaturas ativas e em atraso. Cancelamento
agendado não entra (como no sistema antigo).

## 8. Financeiro

Pagamento de assinatura é uma operação financeira **própria**: não entra no caixa físico, não gera comissão
nem gorjeta (D-46: a comissão é do atendimento do assinante). Relatórios financeiros completos (receita de
assinaturas no faturamento, DRE): fora desta fase.

## 9. Migração

O importador (`SubscriptionsStep`) preserva os IDs do Stripe (cliente, assinatura, pagamento e eventos já
processados), as datas, os cancelamentos e a situação. Novidades da Fase 9: cada plano importado vira a versão
1; cada assinatura ganha a versão, um identificador público e a origem "sistema antigo"; pagamento
"confirmado" vira "paid"; eventos já processados pelo sistema antigo ficam como `legacy` (o mesmo evento
reenviado depois da virada não roda de novo). Teste:
`ImportScenariosTest::test_assinatura_importada_e_reconhecida_pelos_eventos_do_stripe`. Nenhuma migração real
nesta fase.

## 10. PRECISA DE DECISÃO

| # | Ponto | Implementado (mais conservador) |
|---|---|---|
| P9-01 | Serviços **não incluídos** agendados junto da adesão: o sistema antigo cobrava online no checkout | Cobrados na barbearia (pagamento online de atendimento está fora do escopo, D-23; não misturar com o caixa) |
| P9-02 | Adesão no agendamento sem pagamento concluído: o antigo escondia/removia o agendamento | O agendamento continua, pelo valor normal |
| P9-03 | Combo (serviço comum, D-24) parcialmente coberto: o antigo cobrava o menor entre o combo e os itens não cobertos | O combo só sai de graça se estiver incluído no plano; revisar os planos importados |
| P9-04 | Troca de plano (upgrade/downgrade) | Não suportada: cancelar e assinar o novo plano |
| P9-05 | Reembolso não encerra o benefício | O benefício continua até a data paga; a equipe cancela se for o caso |
| P9-06 | Valor fixo de comissão de assinante | Um valor por atendimento; serviços não incluídos no mesmo atendimento seguem a regra normal (o antigo trocava a comissão de todos os serviços) |
| P9-07 | Tolerância de 1 dia | Vale para o benefício da assinatura ativa (o antigo usava só para expirar a situação) |
| P9-08 | Envio do link | Cópia na tela e conta do cliente. **P10-04 (decidido na aprovação da Fase 10):** o link também pode ir por e-mail: botão "Enviar link por e-mail" na assinatura (`subscriptions.create`), pela fila central (transacional, nunca campanha), uma vez por link (chave = sessão do Stripe), sem gerar link ou cobrança nova (duplo clique = "já enviado"), no histórico (`link_emailed`) e na auditoria (`subscription.link_emailed`). O link é lido na hora do envio: vencido ou substituído, não sai |
| P9-09 | Pausar assinatura | Não oferecido; `paused` vindo do Stripe é tratado como em atraso (sem benefício novo) |
| ~~P9-10~~ | Retenção dos eventos do Stripe — **decidida (aprovação da Fase 9): 12 meses** | Ver §11 |

## 11. Retenção dos eventos do Stripe (decisão do dono, P9-10)

Os eventos recebidos do Stripe (`gateway_events`) guardam o corpo inteiro, que traz dados pessoais (nome,
e-mail, endereço de cobrança). Política:

- **12 meses** a partir do recebimento, com o corpo inteiro (permite reprocessar e investigar).
- Depois disso, a rotina `app:communication retention` (diária, 03:30) **remove o corpo** do evento. Fica só o
  necessário para auditoria técnica e financeira: gateway, ID do evento, tipo, situação, resultado, erro
  técnico do processamento (mensagem do sistema, sem o corpo), datas, tentativas e a assinatura local. O ID do evento continua guardado: um reenvio do mesmo evento continua sendo
  reconhecido e não é reprocessado.
- Os dados financeiros que importam já estão nos registros próprios (pagamentos, reembolsos, histórico da
  assinatura), que seguem a política de retenção financeira.
- A rotina é idempotente e **auditável**: cada execução que anonimiza registra na auditoria
  (`retention.gateway_events`) quantos eventos e o limite de data. O evento anonimizado guarda a data da
  anonimização (`payload_purged_at`).
- Nada de dados pessoais guardados indefinidamente por conveniência. A política geral de retenção/LGPD
  (Fase 12) deve citar esta regra.

