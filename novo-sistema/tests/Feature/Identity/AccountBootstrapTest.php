<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\StaffAccounts;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Primeiro proprietario (app:create-owner) e regras do servico de contas.
 */
class AccountBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_o_primeiro_proprietario_com_senha_digitada_sem_eco(): void
    {
        $this->artisan('app:create-owner', ['--name' => 'Dona Ficticia', '--username' => 'Dona', '--email' => ''])
            ->expectsQuestion('Senha provisória (não aparece ao digitar)', 'Provisoria123')
            ->expectsQuestion('Repita a senha', 'Provisoria123')
            ->assertSuccessful();

        $dona = User::query()->where('username', 'dona')->firstOrFail();
        $this->assertSame(StaffRole::Owner, $dona->role);
        $this->assertTrue($dona->must_change_password, 'troca obrigatoria no 1o acesso');
        $this->assertTrue(Hash::check('Provisoria123', $dona->password));
    }

    public function test_senha_gerada_e_aleatoria_e_mostrada_uma_vez(): void
    {
        $this->artisan('app:create-owner', ['--name' => 'Dona', '--username' => 'dona', '--email' => '', '--generate-password' => true])
            ->expectsOutputToContain('Senha provisória (mostrada só agora)')
            ->assertSuccessful();

        $this->assertNotNull(User::query()->where('username', 'dona')->value('password'));
    }

    public function test_nao_cria_se_ja_existe_proprietario(): void
    {
        User::factory()->owner()->create();

        $this->artisan('app:create-owner', ['--name' => 'Outro', '--username' => 'outro', '--email' => '', '--generate-password' => true])
            ->assertFailed();
        $this->assertSame(1, User::query()->where('role', 'owner')->count());
    }

    public function test_senha_fraca_ou_diferente_e_recusada(): void
    {
        $this->artisan('app:create-owner', ['--name' => 'Dona', '--username' => 'dona', '--email' => ''])
            ->expectsQuestion('Senha provisória (não aparece ao digitar)', 'fraca')
            ->expectsQuestion('Repita a senha', 'fraca')
            ->assertFailed();

        $this->artisan('app:create-owner', ['--name' => 'Dona', '--username' => 'dona', '--email' => ''])
            ->expectsQuestion('Senha provisória (não aparece ao digitar)', 'Provisoria123')
            ->expectsQuestion('Repita a senha', 'Provisoria999')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_em_producao_sem_confirmacao_nao_roda(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('app:create-owner', ['--name' => 'Dona', '--username' => 'dona', '--email' => '', '--generate-password' => true, '--no-interaction' => true])
            ->assertFailed();
        $this->assertSame(0, User::query()->count());
    }

    public function test_nao_existe_senha_padrao_no_codigo(): void
    {
        $fonte = file_get_contents(app_path('Console/Commands/CreateOwner.php')).file_get_contents(database_path('seeders/DevelopmentSeeder.php'));

        $this->assertDoesNotMatchRegularExpression("/'password'\s*=>\s*'[^']+'/", $fonte);
        $this->assertStringContainsString('Str::password(', $fonte);
    }

    public function test_servico_nao_deixa_o_sistema_sem_proprietario_ativo(): void
    {
        $dono = User::factory()->owner()->create(['username' => 'dono']);
        $outro = User::factory()->owner()->create(['username' => 'outro']);
        $contas = app(StaffAccounts::class);

        $contas->update($outro, ['name' => 'Outro', 'username' => 'outro', 'email' => null], StaffRole::Manager, true, $dono);

        $this->expectException(DomainRuleViolation::class);
        $contas->update($dono, ['name' => 'Dono', 'username' => 'dono', 'email' => null], StaffRole::Owner, false, $outro);
    }
}
