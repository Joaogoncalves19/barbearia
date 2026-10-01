<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 6: atendimento, caixa, produtos e estoque.
     *
     * Fatos diferentes, tabelas diferentes (atendimento.md):
     *
     * - attendances: o que ACONTECEU quando o cliente chegou. Nasce de um
     *   agendamento (appointment_id) ou de um encaixe (sem agendamento).
     *   active_appointment_id e a sentinela "um atendimento nao cancelado por
     *   agendamento" (vira nulo ao cancelar). version e a linha de trava do
     *   atendimento (incrementada como PRIMEIRA escrita de toda transacao que
     *   muda o atendimento). completion_key torna a conclusao idempotente.
     * - attendance_items: servicos e produtos VENDIDOS, com nome e preco
     *   fotografados. attendance_consumptions: material USADO no servico (nao
     *   cobrado), que tambem baixa o estoque. attendance_discounts: descontos
     *   (tipo, valor antes, valor descontado, motivo, quem aplicou).
     *   attendance_events: linha do tempo.
     * - cash_sessions: abertura e fechamento do caixa. open_marker (1 enquanto
     *   aberto, nulo depois) garante UM caixa aberto por barbearia (decisao do
     *   dono na Fase 6). cash_movements: razao do caixa (so inclusao).
     * - payments: passa a apontar o ATENDIMENTO (nao mais o agendamento) e o
     *   caixa em que entrou. request_key protege o estorno contra repeticao.
     * - stock_movements: passa a apontar o atendimento; ganha quem lancou, o
     *   saldo depois do lancamento, o movimento revertido e request_key.
     * - products: codigo, descricao, unidade, preco de venda opcional (insumo
     *   so de consumo nao tem preco), lock_version (edicao) e stock_version
     *   (linha de trava do estoque do produto).
     */
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('source', 16); // appointment | walk_in | legacy
            $table->foreignId('appointment_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('active_appointment_id')->nullable()->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('customer_phone', 32)->nullable();
            // Nulo so no legado (atendimento antigo sem barbeiro); o
            // AttendanceService sempre exige o profissional.
            $table->foreignId('professional_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('professional_name')->nullable();
            $table->string('status', 16);
            $table->timestamp('opened_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->integer('subtotal_cents')->nullable();
            $table->integer('discount_cents')->nullable();
            $table->integer('total_cents')->nullable();
            $table->integer('tip_cents')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('completion_key', 64)->nullable()->unique();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->index(['status', 'opened_at']);
            $table->index(['professional_id', 'opened_at']);
            $table->index('completed_at');
        });

        Schema::create('attendance_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 16); // service | package | product
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('appointment_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('quantity')->default(1);
            $table->integer('unit_price_cents')->nullable();
            $table->integer('total_cents')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('price_source', 32);
            $table->integer('cost_cents')->nullable();
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('attendance_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name');
            $table->unsignedInteger('quantity');
            $table->integer('unit_cost_cents')->nullable();
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('attendance_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24); // AdjustmentKind (manual, cupom... e legado)
            $table->string('type', 16); // percent | fixed
            $table->unsignedSmallInteger('percent_bp')->nullable();
            $table->integer('fixed_cents')->nullable();
            $table->integer('base_cents'); // valor antes
            $table->integer('amount_cents'); // valor descontado
            $table->string('reason')->nullable();
            $table->foreignId('applied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('attendance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('description');
            $table->string('actor_label')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('occurred_at');
        });

        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('open_marker')->nullable()->unique();
            $table->string('status', 16); // open | closed
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->integer('opening_float_cents');
            $table->string('opening_notes')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->integer('expected_cash_cents')->nullable();
            $table->integer('counted_cash_cents')->nullable();
            $table->integer('difference_cents')->nullable();
            $table->string('closing_notes')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->index('opened_at');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('appointment_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('attendance_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->string('request_key', 64)->nullable()->unique();
            $table->index('attendance_id');
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_session_id')->constrained()->restrictOnDelete();
            $table->string('type', 16); // payment | refund | supply | withdrawal
            $table->string('method', 16);
            $table->integer('amount_cents'); // com sinal: entrada > 0, saida < 0
            $table->foreignId('payment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('description');
            $table->string('request_key', 64)->nullable()->unique();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['cash_session_id', 'method']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('appointment_id');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('attendance_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reverses_movement_id')->nullable()->unique()->constrained('stock_movements')->restrictOnDelete();
            $table->integer('balance_after')->nullable();
            $table->integer('unit_cost_cents')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('request_key', 64)->nullable()->unique();
            $table->index('attendance_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->integer('price_cents')->nullable()->change();
            $table->string('sku', 32)->nullable()->unique();
            $table->text('description')->nullable();
            $table->string('unit', 8)->default('un');
            $table->unsignedInteger('lock_version')->default(0);
            $table->unsignedInteger('stock_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['sku']);
            $table->dropColumn(['sku', 'description', 'unit', 'lock_version', 'stock_version']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropUnique(['request_key']);
            $table->dropUnique(['reverses_movement_id']);
            $table->dropIndex(['attendance_id']);
            $table->dropConstrainedForeignId('attendance_id');
            $table->dropConstrainedForeignId('reverses_movement_id');
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn(['balance_after', 'unit_cost_cents', 'request_key']);
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::dropIfExists('cash_movements');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['request_key']);
            $table->dropIndex(['attendance_id']);
            $table->dropConstrainedForeignId('attendance_id');
            $table->dropConstrainedForeignId('cash_session_id');
            $table->dropConstrainedForeignId('received_by_user_id');
            $table->dropColumn(['reason', 'request_key']);
            $table->foreignId('appointment_id')->nullable()->constrained()->restrictOnDelete();
        });

        Schema::dropIfExists('cash_sessions');
        Schema::dropIfExists('attendance_events');
        Schema::dropIfExists('attendance_discounts');
        Schema::dropIfExists('attendance_consumptions');
        Schema::dropIfExists('attendance_items');
        Schema::dropIfExists('attendances');
    }
};
