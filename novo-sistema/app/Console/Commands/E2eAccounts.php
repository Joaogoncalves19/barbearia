<?php

namespace App\Console\Commands;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Team\Models\Professional;
use Database\Factories\CustomerFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Contas FICTICIAS para os testes de navegador (Playwright).
 *
 * - So roda em local/testing (recusa qualquer outro ambiente).
 * - A senha vem da variavel E2E_PASSWORD, gerada aleatoriamente pelo
 *   playwright.config.js a cada execucao: nao existe senha fixa no codigo.
 * - Idempotente: cada execucao recoloca as contas no estado inicial.
 * - Um conjunto de contas por "--suffix" (um por projeto do Playwright, que
 *   rodam em paralelo e trocam senhas).
 */
class E2eAccounts extends Command
{
    protected $signature = 'app:e2e-accounts {--suffix=* : Um conjunto de contas por sufixo}';

    protected $description = 'Cria/reinicia contas ficticias para os testes de navegador (so local/testing)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Somente em local/testing.');

            return self::FAILURE;
        }

        // Lida direto do processo (nao do .env): so existe durante o teste.
        $senha = (string) getenv('E2E_PASSWORD');
        if (strlen($senha) < 12) {
            $this->error('Defina E2E_PASSWORD (12+ caracteres, gerada pelo playwright.config.js).');

            return self::FAILURE;
        }

        foreach ($this->option('suffix') ?: ['local'] as $sufixo) {
            DB::transaction(fn () => $this->conjunto((string) $sufixo, $senha));
        }

        $this->info('Contas de teste prontas.');

        return self::SUCCESS;
    }

    private function conjunto(string $s, string $senha): void
    {
        $this->membro("e2e-dono-{$s}", 'Dono E2E', StaffRole::Owner, $senha);
        $this->membro("e2e-provisorio-{$s}", 'Recepção E2E', StaffRole::Reception, $senha, provisoria: true);
        // Catalogo e equipe (Fase 4): conta propria, para nao dividir o limite
        // de tentativas de login com os testes de acesso.
        $this->membro("e2e-gerente-{$s}", 'Gerente E2E', StaffRole::Manager, $senha);
        $a = $this->barbeiro("e2e-barbeiro-a-{$s}", 'Barbeiro A E2E', $senha);
        $b = $this->barbeiro("e2e-barbeiro-b-{$s}", 'Barbeiro B E2E', $senha);

        $ana = $this->cliente("e2e-cliente-{$s}@exemplo.test", 'Cliente E2E', $senha);
        $outra = $this->cliente("e2e-outra-{$s}@exemplo.test", 'Outra Cliente E2E', $senha);

        $this->agendamento("AG-E2E-A-{$s}", $ana, $a);
        $this->agendamento("AG-E2E-B-{$s}", $outra, $b);
    }

    private function membro(string $username, string $nome, StaffRole $papel, string $senha, bool $provisoria = false): User
    {
        $user = User::query()->firstOrNew(['username' => $username]);
        $user->name = $nome;
        $user->email = $username.'@barbearia.test';
        $user->password = $senha;
        $user->forceFill(['role' => $papel, 'is_active' => true, 'must_change_password' => $provisoria])->save();

        return $user;
    }

    private function barbeiro(string $username, string $nome, string $senha): Professional
    {
        $user = $this->membro($username, $nome, StaffRole::Professional, $senha);

        return Professional::query()->updateOrCreate(['user_id' => $user->id], ['display_name' => $nome, 'is_active' => true, 'is_bookable' => true]);
    }

    private function cliente(string $email, string $nome, string $senha): Customer
    {
        $c = Customer::query()->firstOrNew(['email' => $email]);
        $c->fill(['name' => $nome, 'password' => $senha]);
        $c->cpf ??= CustomerFactory::fakeCpf();
        $c->email_verified_at = now();
        $c->save();

        return $c;
    }

    private function agendamento(string $codigo, Customer $cliente, Professional $pro): void
    {
        $inicio = now()->addDays(3)->setTime(14, 0);

        Appointment::query()->updateOrCreate(['code' => $codigo], [
            'customer_id' => $cliente->id,
            'customer_name' => $cliente->name,
            'professional_id' => $pro->id,
            'professional_name' => $pro->display_name,
            'starts_at' => $inicio,
            'ends_at' => $inicio->copy()->addMinutes(30),
            'status' => AppointmentStatus::Confirmed,
            'source' => AppointmentSource::Staff,
        ]);
    }
}
