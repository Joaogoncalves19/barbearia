<?php

use App\Http\Controllers\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Webhooks (Fase 9, webhooks.md). Fora do grupo "web": sem sessao, sem
| cookie e sem CSRF (quem chama e o Stripe, que assina o corpo). A
| autenticidade e conferida pela assinatura do Stripe (StripeSignature).
| Limite proprio por IP. O endereco antigo (webhook_stripe.php) continua
| aceito ate a URL ser trocada no painel do Stripe (proposta-arquitetura.md).
*/

Route::post('/webhooks/stripe', StripeWebhookController::class)->name('webhooks.stripe');
Route::post('/webhook_stripe.php', StripeWebhookController::class)->name('webhooks.stripe.legacy');
