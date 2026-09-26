<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seed de DESENVOLVIMENTO. Nunca roda em producao (ver DevelopmentSeeder).
 * Dados reais do sistema antigo chegam pelo importador da Fase 2.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment(['local', 'testing'])) {
            $this->call(DevelopmentSeeder::class);
        }
    }
}
