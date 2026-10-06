<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 12 (area do cliente).
 *
 * - customers.pending_email / pending_email_token / pending_email_expires_at:
 *   troca de e-mail pelo proprio cliente. O endereco novo so vale depois de
 *   confirmado pelo link enviado a ele; o banco guarda so o hash do token.
 * - customer_erasures: registro de cada exclusao de conta (anonimizacao), sem
 *   nenhum dado pessoal: quem pediu (o proprio cliente ou a equipe), quando e
 *   quantos registros foram tratados. Prova de que o pedido foi atendido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('pending_email')->nullable()->after('email_verified_at');
            $table->char('pending_email_token', 64)->nullable()->unique()->after('pending_email');
            $table->timestamp('pending_email_expires_at')->nullable()->after('pending_email_token');
        });

        Schema::create('customer_erasures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->restrictOnDelete();
            $table->string('requested_by', 16); // customer | staff
            $table->foreignId('staff_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('counts');
            $table->timestamp('erased_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_erasures');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['pending_email_token']);
            $table->dropColumn(['pending_email', 'pending_email_token', 'pending_email_expires_at']);
        });
    }
};
