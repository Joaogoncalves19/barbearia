<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Authorization\PermissionMatrix;
use App\Modules\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Minha conta" de quem e da equipe: ve seus dados e o que o papel permite,
 * e altera so o proprio nome. Usuario, e-mail e papel sao definidos pelo
 * proprietario (evita que alguem troque o proprio identificador de login ou
 * se promova).
 */
class AccountController extends Controller
{
    public function edit(Request $request): View
    {
        $user = $this->user($request);
        $papel = $user->role;

        $permissoes = collect($papel ? PermissionMatrix::abilitiesForRole($papel->value) : [])
            ->map(fn (string $h) => PermissionMatrix::staffAbilities()[$h])
            ->values();

        return view('panel.account', ['user' => $user, 'permissions' => $permissoes]);
    }

    public function update(Request $request): RedirectResponse
    {
        $dados = $request->validate(['name' => ['required', 'string', 'min:2', 'max:120']], [], ['name' => 'nome']);

        $user = $this->user($request);
        $user->name = trim($dados['name']);
        $user->save();

        return redirect()->route('panel.account.edit')->with('status', 'Nome atualizado.');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user('web');
    }
}
