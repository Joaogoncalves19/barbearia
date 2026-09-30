<?php

namespace App\Http\Controllers\Auth\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StaffLoginRequest;
use App\Modules\Identity\Models\User;
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
 * Login da EQUIPE (guard web): nome de usuario ou e-mail + senha.
 */
class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.staff.login');
    }

    public function store(StaffLoginRequest $request, CredentialAttempt $attempt): RedirectResponse
    {
        $identifier = $request->string('identifier')->trim()->value();

        $user = $attempt->attempt(
            'web',
            LoginCredentials::staff($identifier),
            $request->string('password')->value(),
            $request->boolean('remember'),
        );

        if (! $user instanceof User) {
            Log::channel('security')->notice('Login da equipe recusado', [
                'identificador_hash' => hash('sha256', mb_strtolower($identifier)),
                'ip' => $request->ip(),
            ]);

            // Mesma mensagem para conta inexistente, senha errada, conta
            // inativa ou sem senha: a resposta nao revela qual conta existe.
            throw ValidationException::withMessages([
                'identifier' => 'Usuário, e-mail ou senha incorretos.',
            ]);
        }

        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        AuditTrail::record('auth.login', $user, $user, 'Entrou no painel.');

        return redirect()->intended(route('panel.home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = Auth::guard('web')->user();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user instanceof User) {
            AuditTrail::record('auth.logout', $user, $user, 'Saiu do painel.');
        }

        return redirect()->route('staff.login');
    }
}
