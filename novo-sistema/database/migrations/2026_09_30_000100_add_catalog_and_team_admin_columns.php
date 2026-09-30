<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Fase 4: administracao do catalogo e da equipe.
     *
     * - slug: identificacao estavel (URLs do site publico). Gerado na criacao
     *   e NAO muda quando o nome muda. Anulavel so para nao quebrar gravacoes
     *   antigas; o model e o importador sempre preenchem.
     * - is_active (categoria): categoria inativa tira seus servicos de novos
     *   agendamentos.
     * - is_public / is_featured: visibilidade e destaque no site (Fase 11).
     *   Independentes de is_active: aparecer no site exige ativo E publico.
     * - image_path (servico), bio/headline (profissional): informacoes publicas.
     *   Imagens ficam no disco de midia (config barbearia.media_disk), nunca
     *   no banco.
     * - lock_version: controle de concorrencia otimista. Duas pessoas editando
     *   o mesmo registro: a segunda recebe aviso em vez de sobrescrever.
     */
    public function up(): void
    {
        Schema::table('service_categories', function (Blueprint $table) {
            $table->string('slug', 80)->nullable()->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->string('slug', 80)->nullable()->unique();
            $table->boolean('is_public')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->string('image_path')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
        });

        Schema::table('professionals', function (Blueprint $table) {
            $table->string('slug', 80)->nullable()->unique();
            $table->string('headline', 120)->nullable();
            $table->text('bio')->nullable();
            $table->boolean('is_public')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('lock_version')->default(0);

            $table->index(['is_active', 'sort_order']);
        });

        // Registros ja existentes (ex.: importados) ganham slug.
        foreach (['service_categories' => 'name', 'services' => 'name', 'professionals' => 'display_name'] as $tabela => $coluna) {
            $usados = [];
            foreach (DB::table($tabela)->orderBy('id')->get(['id', $coluna]) as $row) {
                $base = Str::limit(Str::slug((string) $row->{$coluna}), 70, '') ?: 'item';
                $slug = $base;
                for ($i = 2; isset($usados[$slug]); $i++) {
                    $slug = "{$base}-{$i}";
                }
                $usados[$slug] = true;
                DB::table($tabela)->where('id', $row->id)->update(['slug' => $slug]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('professionals', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'sort_order']);
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'headline', 'bio', 'is_public', 'is_featured', 'lock_version']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'is_public', 'is_featured', 'image_path', 'lock_version']);
        });

        Schema::table('service_categories', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'description', 'is_active', 'lock_version']);
        });
    }
};
