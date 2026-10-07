# Stripe (Fase 9)

O Stripe é o **sistema externo de pagamento** das assinaturas. O banco local tem o próprio histórico e não
depende do Stripe para responder "quem assina, até quando, quanto pagou".

## 1. Configuração (só variável de ambiente)

| Variável | Uso |
|---|---|
| `STRIPE_SECRET` | Chave secreta da API (`sk_test_...` até a virada) |
| `STRIPE_WEBHOOK_SECRET` | Segredo de assinatura do endpoint (`whsec_...`) |
| `STRIPE_API_VERSION` | Opcional; padrão `2024-06-20` (versão fixada) |

- **Nunca** no banco, no código versionado, no frontend ou no log. Nenhuma tela mostra as chaves: o painel só
  diz se o pagamento online está configurado. Usuário administrativo não tem acesso a elas.
- Sem `STRIPE_SECRET`: a adesão online não aparece (R-25) e nenhuma chamada é feita.
- Sem `STRIPE_WEBHOOK_SECRET`: todo webhook é recusado (503), nada é gravado.
- O sistema antigo guardava as chaves no banco (S-10): elas **não** são importadas; são recadastradas no
  `.env` do novo sistema na virada (estrategia-migracao.md).

## 2. Chamadas à API

Adaptador próprio por HTTP (`app/Modules/Subscriptions/Gateway/StripeClient.php`), com a versão da API fixada
(`Stripe-Version`) e **chave de idempotência em toda escrita** (repetir a chamada nunca cobra, cancela ou
reembolsa duas vezes no Stripe):

| Chamada | Quando | Chave de idempotência |
|---|---|---|
| `POST /v1/checkout/sessions` (modo assinatura, `price_data` mensal, metadado `local_subscription`, `client_reference_id`, cliente Stripe anterior ou e-mail) | Adesão (agendamento ou link) | `checkout-<id público>` |
| `POST /v1/checkout/sessions/{id}/expire` | Novo link substitui o anterior; cancelar adesão pendente | `expire-<id público>` |
| `POST /v1/subscriptions/{id}` `cancel_at_period_end` | Cancelar no fim do período / reativar | `cancel-…-end-v<versão>` / `reactivate-…-v<versão>` |
| `DELETE /v1/subscriptions/{id}` | Cancelar imediatamente | `cancel-…-now-v<versão>` |
| `POST /v1/refunds` (`payment_intent`, `amount`, metadado `local_refund`) | Reembolso | `refund-<chave do pedido>` |

Escolha técnica (T9-01): adaptador HTTP no lugar do SDK oficial recomendado na Fase 0. Motivos: poucas
chamadas, teste sem rede com `Http::fake`, nenhuma dependência nova e controle explícito de versão e
idempotência. A verificação da assinatura do webhook segue a especificação pública do Stripe
([webhooks.md](webhooks.md)). Trocar pelo SDK depois não muda o domínio.

Erro do Stripe: nada muda no banco local; o log guarda só o caminho, o status e o código do erro (nunca a
chave nem dados do cliente).

URLs de retorno do checkout vêm de `APP_URL` (`route()`), nunca do cabeçalho Host (corrige S-19).

## 3. IDs preservados

`gateway_customer_id` (`cus_...`), `gateway_subscription_id` (`sub_...`, único, **nunca substituído** depois de
gravado: o model recusa), `checkout_session_id`, fatura (`in_...`, `gateway_payment_id` único), `payment_intent`,
`charge`, reembolso (`re_...`, único), evento (`evt_...`, único). Importados do sistema antigo exatamente como
estavam.

## 4. Formatos de objeto

A leitura (`StripeData`) aceita o formato da versão fixada e os formatos novos (fim do período nos itens,
assinatura da fatura em `parent.subscription_details`, pagamento da fatura em `payments`), como o sistema
antigo já fazia para o fim do período.

## 5. Modo teste e virada

- Até a virada: só chaves de **teste**. Não há chaves de teste neste repositório nem no CI: os testes simulam
  o Stripe por completo.
- **Ciclo completo em modo teste** (critério do roadmap): depende do dono criar uma conta/chaves de teste e
  um endpoint de webhook de teste apontando para a homologação. **PENDENTE**.
- **Ensaio da Fase 13 (simulador):** a homologação instalada pelo pacote conversou com um simulador da API
  (`novo-sistema/scripts/ensaio/stripe-simulado.php`, via `STRIPE_API_BASE`) que entrega webhooks assinados
  por HTTP depois de responder, como o Stripe: adesão por link e checkout, renovação, falha, recuperação,
  cancelar no fim, reativar, reembolso, cancelar agora, reenvio, duplicado e fora de ordem
  ([homologacao.md](homologacao.md) §4). Prova a integração da instalação; **não** substitui o ciclo na
  conta real de teste.
- **Na virada:** rotacionar a chave secreta (as antigas ficaram em texto puro no banco antigo) e criar o
  endpoint novo antes de desativar o antigo ([plano-virada.md](plano-virada.md) passo 9).
- Na virada (Fase 13): trocar a URL do webhook no painel do Stripe para `/webhooks/stripe` (o endereço antigo
  `/webhook_stripe.php` continua aceito, com a mesma conferência, até a troca).
