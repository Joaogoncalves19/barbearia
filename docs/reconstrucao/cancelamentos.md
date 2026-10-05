# Cancelamento de assinatura (Fase 9)

Serviço: `SubscriptionManager::cancel` / `reactivate`. A assinatura **nunca é apagada**.

| Tipo | Quem | Efeito |
|---|---|---|
| No fim do período | Equipe (`subscriptions.cancel`: proprietário, gerente) com motivo; cliente pela conta ("Cancelar renovação") | Não cobra de novo; estado "cancelamento agendado"; benefício até o fim pago; no fim, "cancelada" |
| Imediato | Só a equipe, com motivo | Estado "cancelada"; benefício termina hoje (`ends_on` = ontem); não devolve dinheiro (use o reembolso) |
| Reativação | Equipe (`subscriptions.reactivate`) ou cliente ("Manter minha assinatura") | Só com cancelamento agendado e antes do fim pago: volta a renovar |
| Adesão pendente | Equipe | O link deixa de valer (expirado no Stripe); "cancelada" |
| Pelo Stripe (portal, painel do Stripe, inadimplência) | Stripe | Chega por webhook e é consolidado igual |

Registro de cada decisão: **origem** (cliente, equipe, Stripe, sistema), quem, quando pediu, motivo e **data
efetiva** (`cancel_effective_on`), no registro da assinatura, no histórico (`subscription_events`) e na
auditoria (`subscription.cancel_scheduled`, `subscription.cancelled`, `subscription.reactivated`).

Assinatura do Stripe: a ação vai primeiro ao Stripe (chave de idempotência); o estado local é consolidado pela
resposta. Se o Stripe recusar, nada muda. Assinatura manual (importada): ação só local.

Inadimplência: o Stripe tenta de novo (estado "em atraso", benefício até a data paga); se desistir e cancelar,
a assinatura vira "cancelada" sem mexer no passado.

Testes: `SubscriptionActionsTest` (cancelar no fim, imediato, Stripe recusando, motivo obrigatório, cliente só
renovação, cliente cancela e desfaz, manual sem Stripe), `SubscriptionLifecycleTest` (pelo Stripe).
