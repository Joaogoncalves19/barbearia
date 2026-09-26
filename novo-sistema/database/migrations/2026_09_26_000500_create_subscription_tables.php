<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Planos e assinaturas, com historico (o sistema antigo so guardava a
     * assinatura atual). IDs do gateway (Stripe) preservados para a cobranca
     * continuar depois da virada.
     *
     * active_customer_id: sentinela de "uma assinatura ativa por cliente".
     * Preenchida com customer_id enquanto a assinatura vale; nula depois.
     * Indice unico portavel (SQLite e MySQL) sem indice parcial.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('price_cents');
            $table->boolean('is_active')->default(true);
            $table->string('gateway_price_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('plan_services', function (Blueprint $table) {
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->primary(['plan_id', 'service_id']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 24);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('gateway', 16)->default('manual');
            $table->string('gateway_customer_id')->nullable()->index();
            $table->string('gateway_subscription_id')->nullable()->unique();
            $table->string('gateway_status', 32)->nullable();
            $table->string('last_gateway_payment_id')->nullable();
            $table->unsignedBigInteger('active_customer_id')->nullable()->unique();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('gateway', 16);
            $table->string('gateway_payment_id')->nullable()->unique();
            $table->string('gateway_subscription_id')->nullable();
            $table->integer('amount_cents');
            $table->char('currency', 3)->default('BRL');
            $table->string('status', 24);
            $table->string('kind', 24);
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['customer_id', 'paid_at']);
        });

        Schema::create('gateway_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 16);
            $table->string('event_id');
            $table->string('type')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->unique(['gateway', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_events');
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plan_services');
        Schema::dropIfExists('plans');
    }
};
