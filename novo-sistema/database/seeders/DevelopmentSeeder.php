<?php

namespace Database\Seeders;

use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Cria um usuario de cada papel para desenvolvimento local.
 *
 * A senha e gerada aleatoriamente a cada execucao e mostrada so no terminal:
 * nao existe senha fixa no codigo que possa vazar para outro ambiente.
 */
class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DevelopmentSeeder nao pode rodar em producao.');
        }

        $senha = Str::password(16);

        foreach (StaffRole::cases() as $role) {
            $user = User::firstOrNew(['email' => $role->value.'@barbearia.test']);
            $user->name = $role->label().' (dev)';
            $user->password = $senha;
            $user->role = $role;
            $user->is_active = true;
            $user->save();
        }

        $this->command->info('Usuarios de desenvolvimento: <papel>@barbearia.test');
        $this->command->warn('Senha (so desta execucao): '.$senha);
    }
}
