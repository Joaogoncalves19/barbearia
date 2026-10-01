<?php

namespace Database\Seeders;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\ServiceCategory;
use App\Modules\Catalog\Services\StockLedger;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Models\BusinessHour;
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

        // Agenda de exemplo (Fase 5): terca a sabado, 09:00-19:00, e tres
        // servicos ("Corte + Barba" e um servico comum, decisao D-24).
        if (! BusinessHour::query()->exists()) {
            foreach ([2, 3, 4, 5, 6] as $dia) {
                BusinessHour::query()->create(['weekday' => $dia, 'starts_at' => '09:00', 'ends_at' => '19:00']);
            }
        }
        $cabelo = ServiceCategory::query()->firstOrCreate(['slug' => 'cabelo'], ['name' => 'Cabelo', 'is_active' => true, 'sort_order' => 10]);
        $profissional = Professional::query()->whereHas('user', fn ($q) => $q->where('username', 'professional'))->first();
        foreach ([['Corte', 30, 5000], ['Barba', 30, 3500], ['Corte + Barba', 60, 7500]] as $i => [$nome, $min, $preco]) {
            $s = Service::query()->firstOrCreate(['name' => $nome], [
                'category_id' => $cabelo->id, 'duration_minutes' => $min, 'price_cents' => $preco, 'is_active' => true, 'sort_order' => ($i + 1) * 10,
            ]);
            $profissional?->services()->syncWithoutDetaching([$s->id]);
        }

        // Produtos de exemplo (Fase 6): dois a venda e um insumo. O estoque
        // inicial entra pelo razao (StockLedger), nunca por um saldo editado.
        $gerente = User::query()->where('username', 'manager')->firstOrFail();
        foreach ([['Pomada modeladora', 3500, 1500, 5], ['Óleo para barba', 4290, 1800, 3], ['Lâmina descartável', null, 80, 50]] as [$nome, $preco, $custo, $minimo]) {
            $p = Product::query()->firstOrCreate(['name' => $nome], ['price_cents' => $preco, 'cost_cents' => $custo, 'min_stock' => $minimo, 'unit' => 'un', 'is_active' => true]);
            if (app(StockLedger::class)->balance($p) === 0) {
                app(StockLedger::class)->receive($p->refresh(), $minimo * 4, $custo, 'Estoque inicial (dados de exemplo)', $gerente);
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
