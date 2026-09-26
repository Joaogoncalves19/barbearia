<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Financeiro. Pagamentos e lancamentos de comissao sao so-inclusao:
     * corrige-se com estorno (kind=refund / valor negativo), nunca editando.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16)->default('payment'); // payment | refund
            $table->foreignId('refunds_payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->string('method', 16);
            $table->integer('amount_cents');
            $table->integer('tip_cents')->default(0);
            $table->string('amount_source', 24); // recorded | legacy_estimated
            $table->timestamp('paid_at')->nullable();
            $table->string('received_by_label')->nullable();
            $table->timestamps();

            $table->index('paid_at');
        });

        Schema::create('commission_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->constrained()->restrictOnDelete();
            $table->integer('amount_cents');
            $table->integer('tip_cents')->nullable();
            $table->integer('services_total_cents')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->char('reference_month', 7)->nullable(); // YYYY-MM
            $table->date('paid_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['professional_id', 'paid_on']);
        });

        Schema::create('commission_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->constrained()->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->restrictOnDelete();
            $table->integer('base_cents');
            $table->unsignedSmallInteger('rate_bp')->nullable();
            $table->integer('amount_cents');
            $table->json('rule')->nullable();
            $table->foreignId('commission_payout_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->constrained()->restrictOnDelete();
            $table->integer('amount_cents');
            $table->date('issued_on')->nullable();
            $table->char('reference_month', 7)->nullable();
            $table->string('description')->nullable();
            $table->foreignId('commission_payout_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('description');
            $table->string('category')->nullable();
            $table->integer('amount_cents');
            $table->date('due_on')->nullable();
            $table->date('paid_on')->nullable();
            $table->string('status', 16);
            $table->boolean('is_recurring')->default(false);
            $table->foreignId('recurrence_parent_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'due_on']);
        });

        Schema::create('financial_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('period', 16); // daily | monthly
            $table->integer('amount_cents');
            $table->date('effective_from')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_goals');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('advances');
        Schema::dropIfExists('commission_entries');
        Schema::dropIfExists('commission_payouts');
        Schema::dropIfExists('payments');
    }
};
