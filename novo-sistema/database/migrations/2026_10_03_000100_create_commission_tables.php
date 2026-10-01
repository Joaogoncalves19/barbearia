<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 7: comissao, gorjeta, vales e repasse (comissoes.md, repasses.md).
 *
 * - commission_rules: a UNICA fonte das regras de comissao (por profissional,
 *   por servico ou as duas coisas; servico ou produto), versionadas: mudar
 *   uma regra encerra a anterior e cria outra; nada e editado. Os campos
 *   commission_rate_bp e commission_on_products do profissional viram regras
 *   e deixam de existir (duas fontes = divergencia).
 * - commission_entries passa a apontar o ATENDIMENTO (e o item), como foi
 *   feito com payments na Fase 6; ganha tipo (calculada, estorno, ajuste),
 *   regra fotografada, motivo, autor, chave e data.
 * - tip_entries: gorjeta do profissional, separada da comissao, apontando o
 *   pagamento de origem.
 * - advances (vales) e commission_payouts (repasses) ganham forma de
 *   pagamento, caixa, autor, chave e estorno; o caixa ganha a origem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->string('target', 16); // service | product
            $table->foreignId('professional_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 16); // percent | fixed | none
            $table->unsignedSmallInteger('rate_bp')->nullable();
            $table->integer('amount_cents')->nullable();
            $table->string('scope_key', 64);
            // Sentinela: vale scope_key enquanto a regra esta em vigor; nula
            // depois de encerrada. Unica = uma regra em vigor por escopo.
            $table->string('current_scope', 64)->nullable()->unique();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['scope_key', 'starts_at']);
        });

        Schema::table('professionals', function (Blueprint $table) {
            // Trava do saldo do profissional (repasse e vale): primeira escrita.
            $table->unsignedInteger('ledger_version')->default(0);
        });

        // Regras que estavam no profissional viram regras versionadas.
        $agora = now();
        foreach (DB::table('professionals')->get(['id', 'commission_rate_bp', 'commission_on_products']) as $p) {
            $taxa = (int) $p->commission_rate_bp;
            if ($taxa > 0) {
                DB::table('commission_rules')->insert([
                    'target' => 'service', 'professional_id' => $p->id, 'service_id' => null, 'type' => 'percent', 'rate_bp' => $taxa,
                    'scope_key' => "service|p{$p->id}|s*", 'current_scope' => "service|p{$p->id}|s*",
                    'starts_at' => $agora, 'reason' => 'Regra que estava no cadastro do profissional', 'created_at' => $agora, 'updated_at' => $agora,
                ]);
                if ((bool) $p->commission_on_products) {
                    DB::table('commission_rules')->insert([
                        'target' => 'product', 'professional_id' => $p->id, 'service_id' => null, 'type' => 'percent', 'rate_bp' => $taxa,
                        'scope_key' => "product|p{$p->id}|s*", 'current_scope' => "product|p{$p->id}|s*",
                        'starts_at' => $agora, 'reason' => 'Regra que estava no cadastro do profissional', 'created_at' => $agora, 'updated_at' => $agora,
                    ]);
                }
            }
        }

        Schema::table('professionals', function (Blueprint $table) {
            $table->dropColumn(['commission_rate_bp', 'commission_on_products']);
        });

        Schema::table('commission_payouts', function (Blueprint $table) {
            $table->integer('commission_cents')->nullable();
            $table->integer('advances_cents')->nullable();
            $table->dateTime('cutoff_at')->nullable();
            $table->string('method', 16)->nullable();
            $table->foreignId('cash_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('snapshot')->nullable(); // lancamentos incluidos (nulo = repasse do sistema antigo)
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('request_key', 64)->nullable()->unique();
            $table->dateTime('reversed_at')->nullable();
            $table->foreignId('reversed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reversal_reason')->nullable();
            $table->foreignId('reversal_cash_session_id')->nullable()->constrained('cash_sessions')->restrictOnDelete();
            $table->string('reversal_request_key', 64)->nullable()->unique();
        });

        Schema::table('commission_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('appointment_id');
        });
        Schema::table('commission_entries', function (Blueprint $table) {
            $table->string('kind', 16)->default('earned'); // earned | refund | adjustment
            $table->foreignId('attendance_id')->nullable()->constrained()->restrictOnDelete();
            // Um lancamento calculado por item (indice unico; estorno e ajuste nao tem item).
            $table->foreignId('attendance_item_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('commission_rule_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('item_name')->nullable();
            $table->unsignedSmallInteger('quantity')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('request_key', 64)->nullable()->unique();
            $table->dateTime('occurred_at')->nullable();
            $table->index(['professional_id', 'commission_payout_id']);
        });

        Schema::create('tip_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->constrained()->restrictOnDelete();
            $table->foreignId('attendance_id')->nullable()->constrained()->restrictOnDelete();
            // Uma gorjeta por pagamento (e um estorno de gorjeta por estorno).
            $table->foreignId('payment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('kind', 16); // earned | refund | adjustment
            $table->integer('amount_cents');
            $table->string('reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('request_key', 64)->nullable()->unique();
            $table->foreignId('commission_payout_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('occurred_at');
            $table->timestamps();
            $table->index(['professional_id', 'commission_payout_id']);
        });

        Schema::table('advances', function (Blueprint $table) {
            $table->string('kind', 16)->default('advance'); // advance | reversal
            $table->foreignId('reverses_advance_id')->nullable()->unique()->constrained('advances')->restrictOnDelete();
            $table->string('method', 16)->nullable();
            $table->foreignId('cash_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('request_key', 64)->nullable()->unique();
            $table->dateTime('occurred_at')->nullable();
            // Vale do sistema antigo: historico, ja abatido la; nunca entra num repasse novo.
            $table->boolean('is_legacy')->default(false);
            $table->index(['professional_id', 'commission_payout_id']);
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->foreignId('commission_payout_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('advance_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('advance_id');
            $table->dropConstrainedForeignId('commission_payout_id');
        });
        Schema::table('advances', function (Blueprint $table) {
            $table->dropIndex(['professional_id', 'commission_payout_id']);
            $table->dropUnique(['request_key']);
            $table->dropUnique(['reverses_advance_id']);
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropConstrainedForeignId('cash_session_id');
            $table->dropConstrainedForeignId('reverses_advance_id');
            $table->dropColumn(['kind', 'method', 'request_key', 'occurred_at', 'is_legacy']);
        });
        Schema::dropIfExists('tip_entries');
        Schema::table('commission_entries', function (Blueprint $table) {
            $table->dropIndex(['professional_id', 'commission_payout_id']);
            $table->dropUnique(['request_key']);
            $table->dropUnique(['attendance_item_id']);
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropConstrainedForeignId('commission_rule_id');
            $table->dropConstrainedForeignId('payment_id');
            $table->dropConstrainedForeignId('attendance_item_id');
            $table->dropConstrainedForeignId('attendance_id');
            $table->dropColumn(['kind', 'item_name', 'quantity', 'reason', 'request_key', 'occurred_at']);
        });
        Schema::table('commission_entries', function (Blueprint $table) {
            $table->foreignId('appointment_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::table('commission_payouts', function (Blueprint $table) {
            $table->dropUnique(['request_key']);
            $table->dropUnique(['reversal_request_key']);
            $table->dropConstrainedForeignId('reversal_cash_session_id');
            $table->dropConstrainedForeignId('reversed_by_user_id');
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropConstrainedForeignId('cash_session_id');
            $table->dropColumn(['commission_cents', 'advances_cents', 'cutoff_at', 'method', 'snapshot', 'request_key', 'reversed_at', 'reversal_reason', 'reversal_request_key']);
        });
        Schema::table('professionals', function (Blueprint $table) {
            $table->unsignedSmallInteger('commission_rate_bp')->default(0);
            $table->boolean('commission_on_products')->default(false);
        });
        foreach (DB::table('commission_rules')->whereNotNull('current_scope')->whereNotNull('professional_id')->whereNull('service_id')->where('type', 'percent')->get() as $r) {
            DB::table('professionals')->where('id', $r->professional_id)->update(
                $r->target === 'service' ? ['commission_rate_bp' => $r->rate_bp] : ['commission_on_products' => true],
            );
        }
        Schema::table('professionals', function (Blueprint $table) {
            $table->dropColumn('ledger_version');
        });
        Schema::dropIfExists('commission_rules');
    }
};
