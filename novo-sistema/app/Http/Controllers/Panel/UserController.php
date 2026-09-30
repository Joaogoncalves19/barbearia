<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\StaffUserRequest;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\PasswordManager;
use App\Modules\Identity\Services\StaffAccounts;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Usuarios da equipe (proprietario). Rota: can:users.manage + senha
 * reconfirmada; registro: UserPolicy. Mudar o PROPRIO papel/status e negado
 * pela policy; manter 1 proprietario ativo e garantido pelo servico.
 */
class UserController extends Controller
{
    public function index(): View
    {
        return view('panel.users.index', [
            'users' => User::query()->orderByDesc('is_active')->orderBy('name')->paginate(25),
        ]);
    }

    public function create(): View
    {
        return view('panel.users.form', ['user' => new User, 'roles' => $this->roles()]);
    }

    public function store(StaffUserRequest $request, StaffAccounts $accounts): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');

        $user = $accounts->create($request->accountData(), $request->role() ?? StaffRole::Reception, $request->string('temporary_password')->value(), $actor);

        return redirect()->route('panel.users.index')->with('status',
            "Conta de {$user->name} criada. Passe a senha provisória pessoalmente: ela será trocada no primeiro acesso."
        );
    }

    public function edit(Request $request, User $user): View
    {
        return view('panel.users.form', [
            'user' => $user,
            'roles' => $this->roles(),
            'canChangeRole' => Gate::forUser($request->user('web'))->allows('changeRoleOrStatus', $user),
            'canSetPassword' => Gate::forUser($request->user('web'))->allows('setTemporaryPassword', $user),
        ]);
    }

    public function update(StaffUserRequest $request, User $user, StaffAccounts $accounts): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');

        $papel = $request->role() ?? $user->role ?? StaffRole::Reception;
        $ativo = $request->has('is_active') ? $request->boolean('is_active') : (bool) $user->is_active;

        // Papel e status so mudam se a policy deixar (nunca os proprios):
        // se o formulario vier adulterado, o pedido e recusado inteiro.
        if (($papel !== $user->role || $ativo !== (bool) $user->is_active)
            && Gate::forUser($actor)->denies('changeRoleOrStatus', $user)) {
            abort(403);
        }

        try {
            $accounts->update($user, $request->accountData(), $papel, $ativo, $actor);
        } catch (DomainRuleViolation $e) {
            throw ValidationException::withMessages(['role' => $e->getMessage()]);
        }

        return redirect()->route('panel.users.index')->with('status', "Conta de {$user->name} atualizada.");
    }

    public function temporaryPassword(Request $request, User $user, PasswordManager $passwords): RedirectResponse
    {
        $request->validate(
            ['temporary_password' => ['required', 'string', Password::defaults()]],
            [],
            ['temporary_password' => 'senha provisória'],
        );

        /** @var User $actor */
        $actor = $request->user('web');
        $passwords->setTemporary($user, $request->string('temporary_password')->value(), $actor);

        return redirect()->route('panel.users.edit', $user)->with('status',
            'Senha provisória definida. As sessões abertas dessa pessoa foram encerradas e ela criará uma senha nova no próximo acesso.'
        );
    }

    /**
     * @return array<string, string>
     */
    private function roles(): array
    {
        return collect(StaffRole::cases())->mapWithKeys(fn (StaffRole $r) => [$r->value => $r->label()])->all();
    }
}
