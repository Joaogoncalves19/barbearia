<?php

namespace Tests\Feature\Foundation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_aplicacao_inicializa_e_endpoint_de_saude_responde(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_conecta_no_banco_de_testes_isolado(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        $this->assertSame(1, (int) DB::selectOne('select 1 as ok')->ok);
    }

    public function test_migrations_criam_as_tabelas_da_fundacao(): void
    {
        foreach (['users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'] as $tabela) {
            $this->assertTrue(Schema::hasTable($tabela), "Tabela {$tabela} ausente");
        }
        $this->assertTrue(Schema::hasColumns('users', ['role', 'is_active', 'last_login_at']));
    }

    public function test_migrations_podem_ser_revertidas_e_reaplicadas(): void
    {
        $this->artisan('migrate:rollback', ['--force' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('users'));
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertTrue(Schema::hasTable('users'));
    }

    public function test_rota_inexistente_mostra_pagina_de_erro_propria_sem_detalhe_tecnico(): void
    {
        $this->get('/nao-existe')
            ->assertNotFound()
            ->assertSee('Página não encontrada')
            ->assertDontSee('Symfony');
    }

    public function test_raiz_leva_a_home_de_referencia_quando_prototipos_ligados(): void
    {
        $this->get('/')->assertRedirect(route('prototypes.home'));
    }

    public function test_raiz_mostra_pagina_neutra_quando_prototipos_desligados(): void
    {
        config(['barbearia.prototypes_enabled' => false]);
        $this->get('/')->assertOk()->assertSee('Novo site em construção');
    }
}
