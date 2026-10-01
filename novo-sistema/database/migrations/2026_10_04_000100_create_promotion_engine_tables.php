<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 8: promocoes, fidelidade, vale-presente e comprovantes
 * (promocoes.md, fidelidade.md, vale-presente.md, comprovantes.md).
 *
 * - Cupom e resgate de pontos sao RESERVADOS ao agendar/aplicar e so viram
 *   uso na conclusao do atendimento; cancelamento, falta ou desconto maior
 *   liberam a reserva. A sentinela active_key (unica) garante "1 uso por
 *   cliente" (cupom) e "1 resgate por agendamento" (pontos).
 * - O desconto guarda a REGRA (tipo e valor) no agendamento, como ja era no
 *   atendimento, e aponta a reserva que o originou.
 * - Vale-presente e forma de pagamento (decisao do dono): a venda entra no
 *   caixa; o uso e um pagamento apontando o vale.
 * - receipt_deliveries: registro de cada envio de comprovante por e-mail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->string('description')->nullable();
            $table->unsignedInteger('version')->default(0); // trava (uso simultaneo)
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('coupon_redemptions', function (Blueprint $table) {
            $table->dropUnique(['coupon_id', 'customer_id']);
        });
        Schema::table('coupon_redemptions', function (Blueprint $table) {
            $table->string('status', 16)->default('redeemed'); // reserved | redeemed | released
            $table->string('active_key', 64)->nullable()->unique(); // c{cupom}|u{cliente} enquanto reservado ou usado
            $table->foreignId('attendance_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('discount_cents')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason')->nullable();
        });
        foreach (DB::table('coupon_redemptions')->whereNotNull('customer_id')->get(['id', 'coupon_id', 'customer_id']) as $r) {
            DB::table('coupon_redemptions')->where('id', $r->id)->update(['active_key' => "c{$r->coupon_id}|u{$r->customer_id}"]);
        }

        Schema::create('loyalty_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('points');
            $table->string('status', 16); // reserved | redeemed | released
            $table->string('active_key', 64)->nullable()->unique(); // a{agendamento} enquanto reservado ou usado
            $table->foreignId('appointment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('attendance_id')->nullable()->constrained()->nullOnDelete();
            $table->json('reward'); // fotografia da recompensa (tipo, base, percentual, valor)
            $table->integer('discount_cents');
            $table->timestamp('reserved_at');
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'status']);
        });

        Schema::table('loyalty_entries', function (Blueprint $table) {
            $table->foreignId('attendance_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('loyalty_redemption_id')->nullable()->unique()->constrained()->restrictOnDelete();
            // Bonus de indicacao: uma vez por cliente indicado.
            $table->foreignId('referred_customer_id')->nullable()->unique()->constrained('customers')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('request_key', 64)->nullable()->unique();
            // Um ganho e um resgate por atendimento (nulos nao colidem).
            $table->unique(['attendance_id', 'kind']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedInteger('loyalty_version')->default(0); // trava do saldo de pontos
        });

        Schema::table('appointment_adjustments', function (Blueprint $table) {
            $table->string('discount_type', 16)->nullable(); // percent | fixed (a regra; nulo no legado)
            $table->unsignedSmallInteger('percent_bp')->nullable();
            $table->integer('fixed_cents')->nullable();
            $table->foreignId('coupon_redemption_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('loyalty_redemption_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('attendance_discounts', function (Blueprint $table) {
            $table->foreignId('coupon_redemption_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('loyalty_redemption_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('gift_cards', function (Blueprint $table) {
            $table->string('purchaser_email')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email')->nullable();
            $table->string('message', 500)->nullable();
            $table->string('sale_method', 16)->nullable();
            $table->foreignId('sale_cash_session_id')->nullable()->constrained('cash_sessions')->restrictOnDelete();
            $table->foreignId('sold_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('redeemed_attendance_id')->nullable()->constrained('attendances')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_legacy')->default(false); // venda nao registrada no sistema antigo
            $table->string('request_key', 64)->nullable()->unique();
            $table->unsignedInteger('version')->default(0);
        });
        DB::table('gift_cards')->update(['is_legacy' => true]);

        Schema::table('payments', function (Blueprint $table) {
            // Pagamento com vale-presente: aponta o vale (uso unico).
            $table->foreignId('gift_card_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->foreignId('gift_card_id')->nullable()->constrained()->restrictOnDelete();
        });

        Schema::create('receipt_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_type', 24); // attendance | payout | gift_card | cash_session
            $table->unsignedBigInteger('receipt_id');
            $table->string('email');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('requested_by_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('request_key', 64)->unique();
            $table->timestamp('created_at')->nullable();
            $table->index(['receipt_type', 'receipt_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_deliveries');
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gift_card_id');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['gift_card_id']);
            $table->dropConstrainedForeignId('gift_card_id');
        });
        Schema::table('gift_cards', function (Blueprint $table) {
            $table->dropUnique(['request_key']);
            $table->dropConstrainedForeignId('sale_cash_session_id');
            $table->dropConstrainedForeignId('sold_by_user_id');
            $table->dropConstrainedForeignId('redeemed_attendance_id');
            $table->dropConstrainedForeignId('cancelled_by_user_id');
            $table->dropColumn(['purchaser_email', 'recipient_name', 'recipient_email', 'message', 'sale_method', 'cancelled_at', 'cancel_reason', 'is_legacy', 'request_key', 'version']);
        });
        Schema::table('attendance_discounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_redemption_id');
            $table->dropConstrainedForeignId('loyalty_redemption_id');
        });
        Schema::table('appointment_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_redemption_id');
            $table->dropConstrainedForeignId('loyalty_redemption_id');
            $table->dropColumn(['discount_type', 'percent_bp', 'fixed_cents']);
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('loyalty_version');
        });
        Schema::table('loyalty_entries', function (Blueprint $table) {
            $table->dropUnique(['attendance_id', 'kind']);
            $table->dropUnique(['request_key']);
            $table->dropUnique(['loyalty_redemption_id']);
            $table->dropUnique(['referred_customer_id']);
            $table->dropConstrainedForeignId('attendance_id');
            $table->dropConstrainedForeignId('loyalty_redemption_id');
            $table->dropConstrainedForeignId('referred_customer_id');
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn('request_key');
        });
        Schema::dropIfExists('loyalty_redemptions');
        Schema::table('coupon_redemptions', function (Blueprint $table) {
            $table->dropUnique(['active_key']);
            $table->dropConstrainedForeignId('attendance_id');
            $table->dropColumn(['status', 'active_key', 'discount_cents', 'reserved_at', 'released_at', 'release_reason']);
        });
        Schema::table('coupon_redemptions', function (Blueprint $table) {
            $table->unique(['coupon_id', 'customer_id']);
        });
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn(['description', 'version']);
        });
    }
};
