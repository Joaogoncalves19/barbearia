<?php

use App\Http\Controllers\Site\UnsubscribeController;
use Illuminate\Support\Facades\Route;

/*
| Descadastro de "um clique" (Fase 10, RFC 8058; consentimento.md §3). Quem
| chama e o leitor de e-mail (Gmail, Outlook...), sem sessao, sem cookie e
| sem o token do CSRF, por isso fica FORA do grupo "web" (como os webhooks).
| A garantia e a assinatura da URL (middleware "signed", HMAC com a APP_KEY);
| limite proprio por IP. So revoga o marketing; nada mais muda.
*/

Route::post('/descadastro/{customer:public_id}/um-clique', [UnsubscribeController::class, 'oneClick'])->name('unsubscribe.one-click');
