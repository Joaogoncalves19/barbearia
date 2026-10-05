# Webhooks do Stripe (Fase 9)

Endpoint: `POST /webhooks/stripe` (e o antigo `/webhook_stripe.php`, até trocar no Stripe). Fora do grupo
"web": **sem sessão, sem cookie, sem CSRF**. Limite `throttle:webhooks` (600/min por IP). Processador:
`app/Modules/Subscriptions/Services/StripeWebhook.php`.

## 1. Eventos usados

Configure no endpoint do Stripe:

| Evento | Efeito |
|---|---|
| `checkout.session.completed`, `checkout.session.async_payment_succeeded` | Liga a assinatura local aos IDs do Stripe (cliente, assinatura); reprocessa eventos que tinham chegado antes do vínculo |
| `checkout.session.expired` | Adesão não paga → expirada |
| `customer.subscription.created/updated/deleted/paused/resumed` | Fotografia da assinatura → estado consolidado (só se for mais nova) |
| `invoice.paid`, `invoice.payment_succeeded` | Pagamento recebido (uma vez por fatura) e direito estendido; ativa ou recupera |
| `invoice.payment_failed` | Histórico "pagamento recusado" (o estado vem da fotografia) |
| `invoice.payment_action_required` | Histórico "pagamento pendente" |
| `charge.refunded`, `refund.created`, `refund.updated`, `charge.refund.updated` | Reembolso registrado uma vez (inclusive os feitos no painel do Stripe) |

Outros tipos: guardados e marcados "sem efeito".

## 2. Validação e replay

1. Cabeçalho `Stripe-Signature`: `t=<unix>` e um ou mais `v1=<hex>`; esperado = HMAC-SHA256 de
   `"<t>.<corpo cru>"` com o segredo do endpoint; comparação em tempo constante.
2. **Janela de 5 minutos** (`STRIPE_WEBHOOK_TOLERANCE`): evento assinado antes disso é recusado (replay de uma
   requisição capturada).
3. Dentro da janela, o mesmo evento reenviado é reconhecido pelo ID (§3).
4. Corpo sem `id`, `type` ou `data.object`: recusado.
5. Nada é gravado antes da validação. Respostas: 400 (inválido), 503 (segredo não configurado).

## 3. Idempotência e transação

1. O evento é **guardado inteiro, uma vez** (`gateway_events`, único por gateway + ID). Duas entregas ao mesmo
   tempo: o índice único barra a segunda, que lê o registro existente.
2. Processamento em **uma transação**: a primeira escrita "reivindica" o evento (só se ainda não processado);
   depois trava a assinatura (`version`), aplica e marca o evento como processado, com o resultado.
3. Já processado: responde 200 `duplicate`, sem efeito. Mesmo efeito por outro evento (a mesma fatura em
   `invoice.paid` e `invoice.payment_succeeded`) também não duplica: pagamento, reembolso e evento são únicos
   no banco.
4. **Falha**: a transação desfaz tudo; o evento fica `failed` com o erro e a resposta é 500 — o Stripe
   reenvia. Nunca fica nada aplicado pela metade (corrige S-21 do sistema antigo, que respondia 200 antes de
   processar).

Um mesmo webhook enviado duas vezes nunca gera duas cobranças, dois registros, duas ativações nem dois
benefícios (testado inclusive com processos reais).

## 4. Fora de ordem

Não se confia na ordem de chegada:

- Fotografia da assinatura só é aplicada se o `created` do evento for **mais novo** que o da última aplicada
  (`gateway_synced_at`); a mais antiga fica como `stale`, sem efeito.
- O direito (`ends_on`) **só aumenta** com fatura paga (máximo entre o atual e o fim do período pago); fatura
  antiga chegando depois não encurta nada.
- Falha de pagamento só vai para o histórico; não revoga o benefício.
- Evento sem assinatura local (chegou antes do vínculo, sem metadado): guardado como `unmatched` e
  reprocessado quando o `checkout.session.completed` liga a assinatura.
- Transição impossível (cancelada voltando a ativa): não aplicada; registrada no histórico e no log.

## 5. Reprocessamento

`php artisan app:stripe-reprocess <evt_...>`, `--failed` ou `--unmatched`. Seguro: evento já processado não
roda de novo; cada efeito é único. A tela **Eventos do Stripe** (`subscriptions.history`) mostra situação,
resultado, tentativas e erro (sem o corpo do evento).

## 6. Testes

`WebhookTest` (11), `SubscriptionLifecycleTest` (14), `WebhookConcurrencyTest` (2, processos reais),
`ImportScenariosTest::test_assinatura_importada_e_reconhecida_pelos_eventos_do_stripe`.
