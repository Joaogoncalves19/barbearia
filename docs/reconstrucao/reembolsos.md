# Reembolso de assinatura (Fase 9)

Serviço: `SubscriptionManager::refund`. Permissão `subscriptions.refund` (proprietário e financeiro, como o
estorno de pagamento, D-30).

- **Nunca** se apaga ou edita a cobrança: o reembolso é um registro novo (`subscription_refunds`) que aponta o
  pagamento original, com **valor, motivo, data, origem (equipe ou Stripe), ID do Stripe e quem pediu**.
- Parcial ou total: a soma dos reembolsos (concluídos ou em andamento) nunca passa do pago.
- Só para pagamento feito pelo Stripe (com `payment_intent`); pagamento manual importado não é reembolsado pelo
  sistema.
- Fluxo: (1) reserva o valor numa transação com a assinatura travada (dois reembolsos ao mesmo tempo não passam
  do pago); (2) pede ao Stripe com chave de idempotência (duplo clique = um reembolso); (3) grava a situação
  (concluído, em processamento, recusado). Recusado não conta.
- Reembolso feito direto no painel do Stripe chega por webhook e é registrado uma vez (origem "Stripe"). O
  webhook do reembolso pedido aqui não duplica: é ligado pelo metadado `local_refund`.
- O reembolso **não** encerra o benefício (P9-05); se for o caso, cancele também.
- Auditoria: `subscription.refunded`, `subscription.refund_failed`; histórico da assinatura.

Testes: `SubscriptionActionsTest` (parcial e total, idempotente, sem passar do pago, recusado não conta,
webhook do mesmo reembolso, reembolso feito no Stripe, pagamento manual).
