<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 3: seguranca das contas (equipe e clientes).
     *
     * - users.must_change_password: senha provisoria definida pelo
     *   proprietario (criacao ou redefinicao) precisa ser trocada no
     *   primeiro acesso.
     * - password_changed_at: quando a senha mudou pela ultima vez (auditoria
     *   e suporte), nas duas tabelas de contas.
     * - customer_password_reset_tokens: tokens de redefinicao dos CLIENTES,
     *   separados dos da equipe. Na mesma tabela, um cliente e um membro da
     *   equipe com o mesmo e-mail sobrescreveriam o token um do outro.
     * - customer_login_tokens: links magicos de login. So o hash SHA-256 do
     *   token e gravado; uso unico (used_at) e expiracao curta.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('password_changed_at')->nullable();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('password_changed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
        });

        Schema::create('customer_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('customer_login_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('requested_ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['customer_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_login_tokens');
        Schema::dropIfExists('customer_password_reset_tokens');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['password_changed_at', 'last_login_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'password_changed_at']);
        });
    }
};
