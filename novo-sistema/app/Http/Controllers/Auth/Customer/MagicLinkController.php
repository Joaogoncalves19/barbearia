<?php

namespace App\Http\Controllers\Auth\Customer;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Services\MagicLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Entrar sem senha: o cliente recebe um link de uso unico no e-mail.
 *
 * Abrir o link (GET) so mostra o botao "Entrar"; o login acontece no POST.
 * Assim, leitores de e-mail que pre-carregam links nao gastam o token e um
 * link vazado numa pagina nao faz login sozinho.
 */
class MagicLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.customer.magic-link');
    }

    public function store(Request $request, MagicLinkService $magic): RedirectResponse
    {
        $request->validate(['email' => ['required', 'string', 'email', 'max:255']], [], ['email' => 'e-mail']);

        $magic->send($request->string('email')->value(), $request->ip());

        return back()->with('status', 'Se este e-mail tiver cadastro, enviamos um link para entrar. Ele vale por '
            .config('barbearia.security.magic_link_minutes').' minutos.');
    }

    public function show(string $token, MagicLinkService $magic): View
    {
        return view('auth.customer.magic-link-confirm', [
            'token' => $token,
            'valid' => $magic->isUsable($token),
        ]);
    }

    public function consume(Request $request, string $token, MagicLinkService $magic): RedirectResponse
    {
        $customer = $magic->consume($token);

        if ($customer === null) {
            return redirect()->route('customer.magic.request')
                ->withErrors(['email' => 'Este link não é válido, já foi usado ou expirou. Peça um novo.']);
        }

        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();
        $customer->forceFill(['last_login_at' => now()])->saveQuietly();

        return redirect()->intended(route('account.home'));
    }
}
