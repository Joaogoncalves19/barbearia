<?php

namespace App\Modules\Identity\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\System\Services\AuditTrail;
use Illuminate\Foundation\Auth\User as Account;
use Illuminate\Support\Str;

/**
 * Toda gravacao de senha passa por aqui (troca pela propria pessoa,
 * redefinicao por link, senha provisoria do proprietario).
 *
 * Efeitos em TODOS os casos:
 * - hash seguro (cast "hashed"; nunca texto, nunca em log);
 * - password_changed_at atualizado;
 * - remember_token trocado: todo "manter conectado" antigo deixa de valer;
 * - as outras sessoes caem na proxima requisicao (middleware auth.session
 *   compara o hash da senha guardado na sessao);
 * - evento na auditoria.
 */
final class PasswordManager
{
    /** A propria pessoa trocou (sabia a senha atual ou entrou por link). */
    public function change(Account $account, #[\SensitiveParameter] string $newPassword): void
    {
        $this->store($account, $newPassword, mustChange: false);

        AuditTrail::record('password.changed', $account, $account, 'Senha alterada pela própria pessoa.');
    }

    /** Redefinicao pelo link enviado por e-mail (token ja validado pelo broker). */
    public function resetByLink(Account $account, #[\SensitiveParameter] string $newPassword): void
    {
        $this->store($account, $newPassword, mustChange: false);

        // Quem recebeu o link no e-mail provou que o e-mail e dele.
        if ($account instanceof Customer && $account->email_verified_at === null) {
            $account->forceFill(['email_verified_at' => now()])->save();
        }

        AuditTrail::record('password.reset', $account, $account, 'Senha redefinida pelo link de e-mail.');
    }

    /**
     * O proprietario define uma senha PROVISORIA para alguem da equipe (conta
     * nova ou quem esqueceu e nao tem e-mail). Troca obrigatoria no 1o acesso.
     */
    public function setTemporary(User $target, #[\SensitiveParameter] string $temporaryPassword, User $actor): void
    {
        $this->store($target, $temporaryPassword, mustChange: true);

        AuditTrail::record('password.temporary_set', $target, $actor, 'Senha provisória definida pelo proprietário.');
    }

    private function store(Account $account, #[\SensitiveParameter] string $password, bool $mustChange): void
    {
        $account->forceFill([
            'password' => $password,
            'password_changed_at' => now(),
        ]);

        if ($account instanceof User) {
            $account->must_change_password = $mustChange;
        }

        $account->setRememberToken(Str::random(60));
        $account->save();
    }
}
