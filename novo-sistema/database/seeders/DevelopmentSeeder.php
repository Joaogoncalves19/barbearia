<?php

namespace Database\Seeders;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Team\Models\Professional;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Dados FICTICIOS para desenvolvimento local: um usuario de cada papel
 * (usuario = nome do papel, e-mail <papel>@barbearia.test), a ficha do
 * profissional e um cliente de exemplo.
 *
 * A senha e gerada aleatoriamente a cada execucao e mostrada so no terminal:
 * nao existe senha fixa no codigo que possa vazar para outro ambiente.
 * Nunca roda em producao nem em homologacao.
 */
class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DevelopmentSeeder so roda em local/testing.');
        }

        $senha = Str::password(16);

        foreach (StaffRole::cases() as $role) {
            $user = User::query()->firstOrNew(['username' => $role->value]);
            $user->name = $role->label().' (dev)';
            $user->email = $role->value.'@barbearia.test';
            $user->password = $senha;
            $user->forceFill(['role' => $role, 'is_active' => true, 'must_change_password' => false])->save();

            if ($role === StaffRole::Professional && ! $user->professional()->exists()) {
                Professional::query()->create(['user_id' => $user->id, 'display_name' => 'Profissional (dev)', 'is_active' => true, 'is_bookable' => true]);
            }
        }

        $cliente = Customer::query()->firstOrNew(['email' => 'cliente@barbearia.test']);
        $cliente->fill(['name' => 'Cliente de Exemplo', 'cpf' => $cliente->cpf ?? CustomerFactory::fakeCpf(), 'password' => $senha]);
        $cliente->email_verified_at = now();
        $cliente->save();

        $this->command->info('Equipe: usuario = owner | manager | reception | finance | professional (ou <papel>@barbearia.test) em /painel/entrar');
        $this->command->info('Cliente: cliente@barbearia.test em /entrar');
        $this->command->warn('Senha (so desta execucao): '.$senha);
    }
}
