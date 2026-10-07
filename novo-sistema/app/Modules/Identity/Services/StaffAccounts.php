<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Support\Ordering;
use App\Modules\System\Services\AuditTrail;
use App\Modules\Team\Models\Professional;
use Illuminate\Support\Facades\DB;

/**
 * Criacao e alteracao de contas da equipe (so pelo proprietario; a Policy
 * decide QUEM pode, este servico garante as regras que valem para todos).
 *
 * Regras:
 * - R-IDENT-01: sempre sobra pelo menos 1 proprietario ativo (ninguem
 *   rebaixa nem desativa o ultimo, nem ele mesmo).
 * - R-IDENT-02: conta nova nasce com senha provisoria e troca obrigatoria.
 * - R-IDENT-03: usuario com papel "profissional" fica ligado a uma ficha de
 *   profissional (a ficha completa e da Fase 4). Sem ficha, cria uma minima,
 *   inativa para agendamento ate a Fase 4 configurar servicos e horarios.
 */
final class StaffAccounts
{
    public function __construct(private readonly PasswordManager $passwords) {}

    /**
     * @param  array{name: string, username: string, email: ?string}  $data
     */
    public function create(array $data, StaffRole $role, #[\SensitiveParameter] string $temporaryPassword, User $actor): User
    {
        return DB::transaction(function () use ($data, $role, $temporaryPassword, $actor): User {
            $user = new User;
            $user->fill($data);
            $user->role = $role;
            $user->is_active = true;
            $user->save();

            $this->passwords->setTemporary($user, $temporaryPassword, $actor);
            $this->syncProfessional($user);

            AuditTrail::record('user.created', $user, $actor, "Conta criada com o papel {$role->label()}.", ['role' => $role->value]);

            return $user;
        });
    }

    /**
     * Acesso ao painel criado no PROPRIO cadastro do profissional (Fase
     * 12.5, como no sistema antigo): conta com o papel Profissional ja ligada
     * a esta ficha (sem criar a ficha minima de R-IDENT-03), senha
     * provisoria com troca obrigatoria (R-IDENT-02). Mesma auditoria da tela
     * Usuarios.
     *
     * @param  array{name: string, username: string, email: ?string}  $data
     */
    public function createForProfessional(Professional $professional, array $data, #[\SensitiveParameter] string $temporaryPassword, User $actor): User
    {
        return DB::transaction(function () use ($professional, $data, $temporaryPassword, $actor): User {
            if ($professional->user_id !== null) {
                throw DomainRuleViolation::rule('R-IDENT-03', 'Este profissional já tem uma conta de acesso.');
            }

            $user = new User;
            $user->fill($data);
            $user->role = StaffRole::Professional;
            $user->is_active = true;
            $user->save();

            $this->passwords->setTemporary($user, $temporaryPassword, $actor);
            $professional->forceFill(['user_id' => $user->id])->save();

            AuditTrail::record('user.created', $user, $actor, 'Conta criada com o papel '.StaffRole::Professional->label()." no cadastro do profissional {$professional->display_name}.", [
                'role' => StaffRole::Professional->value, 'professional_id' => $professional->id,
            ]);

            return $user;
        });
    }

    /**
     * @param  array{name: string, username: string, email: ?string}  $data
     */
    public function update(User $user, array $data, StaffRole $role, bool $active, User $actor): void
    {
        DB::transaction(function () use ($user, $data, $role, $active, $actor): void {
            $papelAntes = $user->role;
            $ativoAntes = (bool) $user->is_active;

            $perdeDono = $papelAntes === StaffRole::Owner && ($role !== StaffRole::Owner || ! $active);
            if ($perdeDono && $this->activeOwners($user) === 0) {
                throw DomainRuleViolation::rule('R-IDENT-01', 'É preciso manter pelo menos um proprietário ativo.');
            }

            $user->fill($data);
            $user->role = $role;
            $user->is_active = $active;
            $user->save();

            $this->syncProfessional($user);

            if ($papelAntes !== $role) {
                AuditTrail::record('user.role_changed', $user, $actor, "Papel alterado de {$papelAntes?->label()} para {$role->label()}.", [
                    'from' => $papelAntes?->value, 'to' => $role->value,
                ]);
            }

            if ($ativoAntes !== $active) {
                AuditTrail::record($active ? 'user.activated' : 'user.deactivated', $user, $actor,
                    $active ? 'Acesso reativado.' : 'Acesso desativado; as sessões abertas caem na próxima requisição.');
            }
        });
    }

    /** Proprietarios ativos alem de $except. */
    private function activeOwners(User $except): int
    {
        return User::query()
            ->where('role', StaffRole::Owner->value)
            ->where('is_active', true)
            ->whereKeyNot($except->getKey())
            ->count();
    }

    private function syncProfessional(User $user): void
    {
        if ($user->role !== StaffRole::Professional || $user->professional()->exists()) {
            return;
        }

        // Ficha minima: fora do site e sem receber agendamentos ate alguem
        // com professionals.* completar (servicos, apresentacao, foto).
        Professional::query()->create([
            'user_id' => $user->id,
            'display_name' => $user->name,
            'is_active' => true,
            'is_bookable' => false,
            'is_public' => false,
            'sort_order' => Ordering::next(Professional::query()),
        ]);
    }
}
