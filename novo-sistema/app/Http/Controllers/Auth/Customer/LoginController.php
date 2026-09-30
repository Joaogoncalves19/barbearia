<?php

namespace App\Http\Controllers\Auth\Customer;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Services\CredentialAttempt;
use App\Modules\Identity\Services\LoginCredentials;
use App\Modules\System\Services\AuditTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Login do CLIENTE (guard customer) com e-mail e senha. A alternativa sem
 * senha (link magico) fica em MagicLinkController.
 */
class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.customer.login');
    }

    public function store(Request $request, CredentialAttempt $attempt): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['sometimes', 'boolean'],
        ], [], ['email' => 'e-mail', 'password' => 'senha']);

        $customer = $attempt->attempt(
            'customer',
            LoginCredentials::customer($request->string('email')->value()),
            $request->string('password')->value(),
            $request->boolean('remember'),
        );

        if (! $customer instanceof Customer) {
            Log::channel('security')->notice('Login de cliente recusado', [
                'email_hash' => hash('sha256', mb_strtolower(trim($request->string('email')->value()))),
                'ip' => $request->ip(),
            ]);

            throw ValidationException::withMessages(['email' => 'E-mail ou senha incorretos.']);
        }

        // Senha certa, mas e-mail nunca confirmado: nao entra; recebe o link
        // de novo. (So quem sabe a senha chega a esta mensagem.)
        if (! $customer->hasVerifiedEmail()) {
            Auth::guard('customer')->logout();
            $customer->sendEmailVerificationNotification();

            throw ValidationException::withMessages([
                'email' => 'Confirme seu e-mail antes de entrar. Enviamos um novo link de confirmação.',
            ]);
        }

        $request->session()->regenerate();
        $customer->forceFill(['last_login_at' => now()])->saveQuietly();
        AuditTrail::record('auth.login', $customer, $customer, 'Cliente entrou com senha.');

        return redirect()->intended(route('account.home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $customer = Auth::guard('customer')->user();

        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($customer instanceof Customer) {
            AuditTrail::record('auth.logout', $customer, $customer, 'Cliente saiu.');
        }

        return redirect()->route('customer.login')->with('status', 'Você saiu da sua conta.');
    }
}
