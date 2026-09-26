<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agendamentos e o que foi feito neles.
     *
     * - Contato do cliente e nome do profissional sao FOTOGRAFADOS no
     *   agendamento: editar o cadastro depois nao reescreve o historico.
     * - Itens guardam nome, preco e duracao do momento (price_source diz a
     *   origem do valor). O item continua existindo se o catalogo sumir.
     * - Sem indice unico de horario: o banco antigo pode ter conflitos reais.
     *   A regra "um atendimento por profissional/horario" e do BookingService
     *   (Fase 5); conflitos importados viram pendencia.
     */
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('professional_name')->nullable();
            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->string('customer_phone', 32)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 24);
            $table->string('source', 16);
            $table->text('notes')->nullable();
            $table->integer('subtotal_cents')->nullable();
            $table->integer('discount_cents')->nullable();
            $table->integer('total_cents')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by', 16)->nullable();
            $table->string('cancellation_reason', 64)->nullable();
            $table->timestamp('confirmation_requested_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('payment_gateway', 16)->nullable();
            $table->string('payment_gateway_reference')->nullable()->index();
            $table->timestamps();

            $table->index(['professional_id', 'starts_at']);
            $table->index(['customer_id', 'starts_at']);
            $table->index(['status', 'starts_at']);
        });

        Schema::create('appointment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 16); // service | package | product
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('quantity')->default(1);
            $table->integer('unit_price_cents')->nullable();
            $table->integer('total_cents')->nullable();
            $table->integer('cost_cents')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('price_source', 32);
            $table->timestamps();
        });

        Schema::create('appointment_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24);
            $table->integer('amount_cents'); // desconto, sempre >= 0
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('gift_card_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('appointment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->text('description')->nullable();
            $table->string('actor_label')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['appointment_id', 'occurred_at']);
        });

        Schema::create('appointment_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16); // day_before | hours_before
            $table->string('status', 16); // sent | failed
            $table->timestamp('sent_at')->nullable(); // nulo: legado sem horario de envio
            $table->timestamps();

            $table->unique(['appointment_id', 'kind']);
        });

        Schema::table('gift_cards', function (Blueprint $table) {
            $table->foreignId('redeemed_appointment_id')->nullable()->after('redeemed_at')
                ->constrained('appointments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gift_cards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('redeemed_appointment_id');
        });
        Schema::dropIfExists('appointment_reminders');
        Schema::dropIfExists('appointment_events');
        Schema::dropIfExists('appointment_adjustments');
        Schema::dropIfExists('appointment_items');
        Schema::dropIfExists('appointments');
    }
};
