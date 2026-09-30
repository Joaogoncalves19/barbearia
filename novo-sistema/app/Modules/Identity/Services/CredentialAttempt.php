<?php

namespace App\Modules\Identity\Services;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Tentativa de login por senha, comum a equipe e clientes.
 *
 * Por que nao so Auth::attempt(): quando a conta NAO existe, o Laravel
 * responde sem calcular hash nenhum, e a diferenca de tempo revela quais
 * contas existem. Aqui a conta inexistente paga o mesmo custo (hash de uma
 * senha descartavel), e a resposta ao usuario e sempre a mesma.
 *
 * Senha nula (conta importada com hash nao reconhecido, cliente que so usa
 * link magico) nunca confere: o provider recusa.
 */
final class CredentialAttempt
{
    private static ?string $dummyHash = null;

    /**
     * @param  array<string, mixed|Closure>  $credentials  como em Auth::attempt(), SEM a senha
     */
    public function attempt(string $guard, array $credentials, #[\SensitiveParameter] string $password, bool $remember): ?Authenticatable
    {
        $auth = Auth::guard($guard);
        $existe = Auth::createUserProvider((string) config("auth.guards.{$guard}.provider"))?->retrieveByCredentials($credentials);

        if ($existe === null) {
            Hash::check($password, self::$dummyHash ??= Hash::make(Str::random(40)));

            return null;
        }

        // attempt() tambem re-hasheia a senha antiga (bcrypt custo 10 do
        // sistema antigo) no primeiro login: hashing.rehash_on_login.
        if (! $auth->attempt($credentials + ['password' => $password], $remember)) {
            return null;
        }

        return $auth->user();
    }
}
