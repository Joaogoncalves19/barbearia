<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Enums\MarketingConsent;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\MarketingConsentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Descadastro do marketing pelo link do e-mail de campanha (consentimento.md
 * §3). Link ASSINADO com o identificador publico do cliente (nunca o id nem
 * o e-mail na URL); sem login.
 *
 * - GET mostra a pagina com o botao (leitores de e-mail abrem links sozinhos:
 *   abrir nao descadastra).
 * - POST descadastra: consentimento revogado + e-mail na lista de supressao,
 *   com prova em consent_records (origem unsubscribe_link). Repetir nao faz
 *   nada de novo.
 * - "Um clique" (RFC 8058, cabecalho List-Unsubscribe-Post): outra rota,
 *   fora do grupo web (routes/email-links.php), sem sessao nem CSRF; a
 *   assinatura da URL e a garantia.
 * - So o MARKETING sai: confirmacoes, lembretes e comprovantes continuam
 *   (sao necessarios ao servico).
 */
class UnsubscribeController extends Controller
{
    public function show(Customer $customer): View
    {
        return view('site.unsubscribe', ['customer' => $customer, 'done' => $customer->marketing_email_consent === MarketingConsent::Revoked]);
    }

    public function store(Request $request, Customer $customer, MarketingConsentService $consent): RedirectResponse
    {
        $this->revoke($customer, $consent, 'link');

        return redirect()->to($request->fullUrl())->with('status', 'Pronto: você não vai mais receber novidades e promoções por e-mail.');
    }

    public function oneClick(Customer $customer, MarketingConsentService $consent): Response
    {
        $this->revoke($customer, $consent, 'one_click');

        return response('', 200);
    }

    private function revoke(Customer $customer, MarketingConsentService $consent, string $evidence): void
    {
        if ($customer->marketing_email_consent !== MarketingConsent::Revoked) {
            $consent->revoke($customer, $customer->email, 'unsubscribe_link', $evidence);
        }
    }
}
