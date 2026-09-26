<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Razoes so-inclusao: pontos de fidelidade, usos de cupom e estoque.
     * Saldo de pontos = SUM(points); estoque = SUM(quantity).
     */
    public function up(): void
    {
        Schema::create('loyalty_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->integer('points');
            $table->string('kind', 24);
            $table->string('description')->nullable();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['customer_id', 'occurred_at']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('redeemed_at')->nullable(); // nulo: legado nao guardava a data
            $table->timestamp('created_at')->nullable();

            // Regra atual: um uso por cliente (clientes nulos nao colidem).
            $table->unique(['coupon_id', 'customer_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->integer('quantity'); // com sinal: entrada > 0, saida < 0
            $table->string('kind', 24);
            $table->string('reason')->nullable();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_label')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['product_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('loyalty_entries');
    }
};
