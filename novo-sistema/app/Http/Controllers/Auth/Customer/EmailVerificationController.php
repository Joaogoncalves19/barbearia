<?php

namespace App\Http\Controllers\Auth\Customer;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use App\Modules\System\Services\AuditTrail;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;

/**
 * Confirmacao de e-mail pelo link assinado (a assinatura e a validade sao
 * conferidas pelo middleware "signed"). O hash precisa bater com o e-mail
 * ATUAL: link de um e-mail antigo nao confirma o novo.
 */
class EmailVerificationController extends Controller
{
    public function __invoke(Customer $customer, string $hash): RedirectResponse
    {
        $email = $customer->email;

        if ($email === null || ! hash_equals(sha1($email), $hash) || ! $customer->canSignIn()) {
            abort(404);
        }

        if (! $customer->hasVerifiedEmail()) {
            $customer->markEmailAsVerified();
            event(new Verified($customer));
            AuditTrail::record('auth.email_verified', $customer, $customer, 'E-mail confirmado pelo link.');
        }

        return redirect()->route('customer.login')->with('status', 'E-mail confirmado. Agora é só entrar.');
    }
}
