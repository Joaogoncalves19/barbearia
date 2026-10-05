<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 11 (site publico) e as decisoes da aprovacao da Fase 10.
 *
 * - site_images: imagens do site (inicio, sobre, galeria, logo), sempre
 *   reprocessadas no servidor (WebP, tamanhos fixos, sem metadados), com texto
 *   alternativo obrigatorio. O banco guarda so o caminho e as dimensoes.
 * - email_messages.provider / provider_message_id (D-05: abstracao de
 *   provedor; Resend), marketing_cleared_at (P10-01: limite de 4 campanhas em
 *   30 dias) e purged_at (P10-03: retencao de 12 meses).
 * - campaign_recipients.purged_at (P10-03).
 * - customers.communication_version: trava da conferencia do limite de
 *   campanhas (dois workers nunca passam do limite para o mesmo cliente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_images', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16); // hero | about | gallery | logo
            $table->string('path');
            $table->string('alt', 160);
            $table->string('caption', 160)->nullable();
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['kind', 'is_active', 'sort_order']);
        });

        Schema::table('email_messages', function (Blueprint $table) {
            $table->string('provider', 16)->nullable();
            $table->string('provider_message_id', 100)->nullable();
            $table->timestamp('marketing_cleared_at')->nullable();
            $table->timestamp('purged_at')->nullable();

            $table->index(['customer_id', 'category', 'marketing_cleared_at']);
            $table->index('created_at');
        });

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->timestamp('purged_at')->nullable();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedInteger('communication_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('communication_version');
        });
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropColumn('purged_at');
        });
        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'category', 'marketing_cleared_at']);
            $table->dropIndex(['created_at']);
            $table->dropColumn(['provider', 'provider_message_id', 'marketing_cleared_at', 'purged_at']);
        });
        Schema::dropIfExists('site_images');
    }
};
