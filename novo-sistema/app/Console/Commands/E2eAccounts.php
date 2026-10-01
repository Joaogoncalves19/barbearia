<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\ServiceCategory;
use App\Modules\Catalog\Services\StockLedger;
use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Enums\ItemType;
use App\Modules\Scheduling\Enums\PriceSource;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BusinessHour;
use App\Modules\Scheduling\Services\AppointmentPricing;
use App\Modules\Scheduling\Support\BusinessTime;
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

        DB::transaction(fn () => $this->agenda());

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
        $a = $this->barbeiro("e2e-barbeiro-a-{$s}", "Barbeiro A {$s}", $senha);
        $b = $this->barbeiro("e2e-barbeiro-b-{$s}", "Barbeiro B {$s}", $senha);

        $ana = $this->cliente("e2e-cliente-{$s}@exemplo.test", 'Cliente E2E', $senha);
        $outra = $this->cliente("e2e-outra-{$s}@exemplo.test", 'Outra Cliente E2E', $senha);

        $this->agendamento("AG-E2E-A-{$s}", $ana, $a);
        $this->agendamento("AG-E2E-B-{$s}", $outra, $b);

        // Agenda (Fase 5): os dois barbeiros fazem o servico de teste.
        $corte = Service::query()->where('slug', 'corte-e2e')->firstOrFail();
        $a->services()->syncWithoutDetaching([$corte->id]);
        $b->services()->syncWithoutDetaching([$corte->id]);

        // Atendimento e caixa (Fase 6): recepcao sem senha provisoria, um
        // produto com estoque e um cliente agendado para HOJE (novo a cada
        // execucao: o de ontem ja foi atendido).
        $gerente = User::query()->where('username', "e2e-gerente-{$s}")->firstOrFail();
        $this->membro("e2e-recepcao-{$s}", 'Recepção Caixa E2E', StaffRole::Reception, $senha);
        // Conta propria (limite de tentativas de login separado do catalogo).
        $this->membro("e2e-operacao-{$s}", 'Gerente Operação E2E', StaffRole::Manager, $senha);
        $this->produto("Pomada E2E {$s}", $gerente);
        $this->chegadaDeHoje($s, $ana, $s === 'celular' ? $a : $b, $corte);
    }

    /** Produto de teste com ao menos 20 no estoque (entrada pelo razao, nunca editando saldo). */
    private function produto(string $nome, User $quem): void
    {
        $p = Product::query()->firstOrNew(['name' => $nome]);
        $p->fill(['price_cents' => 3500, 'cost_cents' => 1500, 'unit' => 'un', 'is_active' => true])->save();
        $ledger = app(StockLedger::class);
        $saldo = $ledger->balance($p);
        if ($saldo < 20) {
            $ledger->receive($p->refresh(), 20 - $saldo, 1500, 'Reposição para os testes E2E', $quem);
        }
    }

    /**
     * Agendamento confirmado para hoje (no fuso da barbearia), com o servico
     * de teste. Sobras de execucoes interrompidas (chegada ainda confirmada,
     * atendimento aberto) sao canceladas antes, pelas transicoes permitidas.
     */
    private function chegadaDeHoje(string $s, Customer $cliente, Professional $pro, Service $servico): void
    {
        $sobras = Appointment::query()->where('customer_name', "Chegada E2E {$s}")
            ->whereIn('status', [AppointmentStatus::Confirmed->value, AppointmentStatus::Pending->value])->get();
        foreach ($sobras as $velho) {
            foreach (Attendance::query()->where('appointment_id', $velho->id)->whereIn('status', ['open', 'in_progress'])->get() as $at) {
                $at->forceFill(['status' => AttendanceStatus::Cancelled, 'active_appointment_id' => null, 'cancelled_at' => now(), 'cancellation_reason' => 'Sobra de teste E2E'])->save();
            }
            $velho->forceFill(['status' => AppointmentStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => 'Sobra de teste E2E'])->save();
        }

        $inicio = BusinessTime::at(BusinessTime::today(), '12:00');
        $ag = Appointment::query()->create([
            'code' => 'AG-E2E-HJ-'.mb_substr($s, 0, 3).'-'.now()->format('ymdHis'),
            'customer_id' => $cliente->id,
            'customer_name' => "Chegada E2E {$s}",
            'professional_id' => $pro->id,
            'professional_name' => $pro->display_name,
            'starts_at' => $inicio,
            'ends_at' => $inicio->addMinutes(30),
            'status' => AppointmentStatus::Confirmed,
            'source' => AppointmentSource::Staff,
        ]);
        $ag->items()->create([
            'item_type' => ItemType::Service, 'service_id' => $servico->id, 'name' => $servico->name, 'quantity' => 1,
            'unit_price_cents' => $servico->price_cents, 'duration_minutes' => $servico->duration_minutes, 'price_source' => PriceSource::CatalogAtBooking,
        ]);
        app(AppointmentPricing::class)->refresh($ag);
    }

    /**
     * Agenda minima para os testes: funcionamento todos os dias das 09:00 as
     * 20:00 (so se ainda nao houver nenhum, para nao mexer na configuracao de
     * quem desenvolve) e o servico "Corte E2E".
     */
    private function agenda(): void
    {
        if (! BusinessHour::query()->exists()) {
            for ($d = 0; $d <= 6; $d++) {
                BusinessHour::query()->create(['weekday' => $d, 'starts_at' => '09:00', 'ends_at' => '20:00']);
            }
        }

        $categoria = ServiceCategory::query()->firstOrCreate(['slug' => 'e2e'], ['name' => 'E2E', 'is_active' => true, 'sort_order' => 999]);
        $servico = Service::query()->firstOrNew(['slug' => 'corte-e2e']);
        $servico->fill(['name' => 'Corte E2E', 'category_id' => $categoria->id, 'duration_minutes' => 30, 'price_cents' => 5000, 'is_active' => true, 'is_public' => true]);
        $servico->save();
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
