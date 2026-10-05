<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Fase 9 — assinaturas (assinaturas.md):
 *
 * - plan_versions / plan_version_services: preco, periodicidade e servicos
 *   incluidos viram VERSOES do plano. Mudar = nova versao; a assinatura
 *   guarda a versao contratada (preco antigo nunca e reescrito). O preco e os
 *   servicos que estavam no plano viram a versao 1.
 * - subscriptions: identificador publico (vai no Stripe como metadado),
 *   versao do plano, origem, adesao pelo agendamento, sessao de pagamento,
 *   cancelamento (quem, quando, por que, efetivo), ultima fotografia do
 *   Stripe aplicada (fora de ordem) e trava (version).
 * - subscription_events: historico SO DE INCLUSAO de tudo o que acontece com
 *   a assinatura (ativacao, renovacao, falha, cancelamento, reembolso...).
 * - subscription_refunds: reembolsos (so inclusao), sempre apontando o
 *   pagamento original, que nunca e apagado nem editado.
 * - gateway_events: o evento recebido inteiro (para reprocessar), estado do
 *   processamento, tentativas e erro.
 * - attendance_discounts.subscription_id: o beneficio aplicado no atendimento.
 * - Comissao de assinante: as colunas do profissional viram regras de
 *   comissao versionadas (alvo "subscription"), como na Fase 7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->integer('price_cents');
            $table->string('interval', 8)->default('month');
            $table->string('gateway_price_id')->nullable();
            // Sentinela: plan_id enquanto esta e a versao atual do plano.
            $table->unsignedBigInteger('current_plan_id')->nullable()->unique();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['plan_id', 'version']);
        });

        Schema::create('plan_version_services', function (Blueprint $table) {
            $table->foreignId('plan_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->primary(['plan_version_id', 'service_id']);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->string('description')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        // Planos existentes (importados) viram a versao 1.
        $agora = now();
        foreach (DB::table('plans')->get(['id', 'price_cents', 'gateway_price_id', 'created_at']) as $p) {
            $versao = DB::table('plan_versions')->insertGetId([
                'plan_id' => $p->id, 'version' => 1, 'price_cents' => $p->price_cents, 'interval' => 'month',
                'gateway_price_id' => $p->gateway_price_id, 'current_plan_id' => $p->id,
                'starts_at' => $p->created_at ?? $agora, 'reason' => 'Plano existente antes das versões',
                'created_at' => $agora, 'updated_at' => $agora,
            ]);
            foreach (DB::table('plan_services')->where('plan_id', $p->id)->pluck('service_id') as $servico) {
                DB::table('plan_version_services')->insert(['plan_version_id' => $versao, 'service_id' => $servico]);
            }
        }
        Schema::dropIfExists('plan_services');
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['price_cents', 'gateway_price_id']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->uuid('public_id')->nullable()->unique();
            $table->foreignId('plan_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('origin', 16)->default('import'); // booking | panel | import
            $table->foreignId('signup_appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('checkout_session_id')->nullable()->unique();
            $table->text('checkout_url')->nullable();
            $table->timestamp('checkout_expires_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancel_requested_at')->nullable();
            $table->string('cancel_source', 16)->nullable(); // customer | staff | stripe | system
            $table->string('cancel_reason')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('cancel_effective_on')->nullable();
            $table->timestamp('gateway_period_end_at')->nullable();
            // Instante (do Stripe) do ultimo evento da assinatura aplicado: evento mais antigo nao sobrescreve.
            $table->timestamp('gateway_synced_at')->nullable();
            $table->unsignedInteger('version')->default(0);
        });
        foreach (DB::table('subscriptions')->get(['id', 'plan_id']) as $s) {
            DB::table('subscriptions')->where('id', $s->id)->update([
                'public_id' => (string) Str::uuid(),
                'plan_version_id' => $s->plan_id !== null ? DB::table('plan_versions')->where('plan_id', $s->plan_id)->where('version', 1)->value('id') : null,
            ]);
        }

        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->foreignId('plan_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('payment_intent_id')->nullable()->index();
            $table->string('charge_id')->nullable()->index();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
        });

        Schema::create('subscription_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->integer('amount_cents');
            $table->string('status', 16); // succeeded | pending | failed
            $table->string('reason')->nullable();
            $table->string('source', 16); // staff | stripe
            $table->string('gateway_refund_id')->nullable()->unique();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('request_key', 64)->nullable()->unique();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('gateway_events', function (Blueprint $table) {
            $table->string('status', 16)->default('processed'); // received | processed | failed
            $table->string('result', 16)->nullable(); // applied | stale | ignored | unmatched | legacy
            $table->string('object_type', 32)->nullable();
            $table->string('object_id')->nullable()->index();
            $table->timestamp('event_created_at')->nullable();
            $table->boolean('livemode')->default(false);
            $table->longText('payload')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
        });
        DB::table('gateway_events')->update(['status' => 'processed', 'result' => 'legacy']);

        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->string('kind', 32);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->string('source', 16); // customer | staff | stripe | system | import
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->foreignId('gateway_event_id')->nullable()->constrained()->nullOnDelete();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['subscription_id', 'id']);
        });

        Schema::table('attendance_discounts', function (Blueprint $table) {
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
        });

        // Comissao de assinante: regras versionadas (alvo "subscription").
        foreach (DB::table('professionals')->get(['id', 'subscription_commission_mode', 'subscription_commission_rate_bp', 'subscription_commission_amount_cents']) as $p) {
            $tipo = match ((string) $p->subscription_commission_mode) {
                'percent' => 'percent', 'fixed' => 'fixed', 'none' => 'none', default => null,
            };
            if ($tipo === null) {
                continue; // padrao: vale a regra normal do profissional sobre o preco de tabela
            }
            DB::table('commission_rules')->insert([
                'target' => 'subscription', 'professional_id' => $p->id, 'service_id' => null, 'type' => $tipo,
                'rate_bp' => $tipo === 'percent' ? min(10000, (int) $p->subscription_commission_rate_bp) : null,
                'amount_cents' => $tipo === 'fixed' ? max(0, (int) $p->subscription_commission_amount_cents) : null,
                'scope_key' => "subscription|p{$p->id}|s*", 'current_scope' => "subscription|p{$p->id}|s*",
                'starts_at' => $agora, 'reason' => 'Regra de assinatura que estava no cadastro do profissional', 'created_at' => $agora, 'updated_at' => $agora,
            ]);
        }
        Schema::table('professionals', function (Blueprint $table) {
            $table->dropColumn(['subscription_commission_mode', 'subscription_commission_rate_bp', 'subscription_commission_amount_cents']);
        });
    }

    public function down(): void
    {
        Schema::table('professionals', function (Blueprint $table) {
            $table->string('subscription_commission_mode', 16)->default('default');
            $table->unsignedSmallInteger('subscription_commission_rate_bp')->nullable();
            $table->integer('subscription_commission_amount_cents')->nullable();
        });
        foreach (DB::table('commission_rules')->where('target', 'subscription')->whereNotNull('current_scope')->whereNotNull('professional_id')->get() as $r) {
            DB::table('professionals')->where('id', $r->professional_id)->update([
                'subscription_commission_mode' => $r->type,
                'subscription_commission_rate_bp' => $r->type === 'percent' ? $r->rate_bp : null,
                'subscription_commission_amount_cents' => $r->type === 'fixed' ? $r->amount_cents : null,
            ]);
        }
        DB::table('commission_rules')->where('target', 'subscription')->delete();

        Schema::table('attendance_discounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_id');
        });
        Schema::dropIfExists('subscription_events');
        Schema::table('gateway_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_id');
            $table->dropIndex(['object_id']);
            $table->dropColumn(['status', 'result', 'object_type', 'object_id', 'event_created_at', 'livemode', 'payload', 'attempts', 'last_error', 'received_at']);
        });
        Schema::dropIfExists('subscription_refunds');
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_version_id');
            $table->dropIndex(['payment_intent_id']);
            $table->dropIndex(['charge_id']);
            $table->dropColumn(['payment_intent_id', 'charge_id', 'period_start', 'period_end']);
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropUnique(['checkout_session_id']);
            $table->dropConstrainedForeignId('plan_version_id');
            $table->dropConstrainedForeignId('signup_appointment_id');
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropConstrainedForeignId('cancelled_by_user_id');
            $table->dropColumn(['public_id', 'origin', 'checkout_session_id', 'checkout_url', 'checkout_expires_at', 'activated_at', 'cancel_at_period_end',
                'cancel_requested_at', 'cancel_source', 'cancel_reason', 'cancel_effective_on', 'gateway_period_end_at', 'gateway_synced_at', 'version']);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->integer('price_cents')->default(0);
            $table->string('gateway_price_id')->nullable();
        });
        Schema::create('plan_services', function (Blueprint $table) {
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->primary(['plan_id', 'service_id']);
        });
        foreach (DB::table('plan_versions')->orderBy('version')->get() as $v) {
            DB::table('plans')->where('id', $v->plan_id)->update(['price_cents' => $v->price_cents, 'gateway_price_id' => $v->gateway_price_id]);
            DB::table('plan_services')->where('plan_id', $v->plan_id)->delete();
            foreach (DB::table('plan_version_services')->where('plan_version_id', $v->id)->pluck('service_id') as $s) {
                DB::table('plan_services')->insert(['plan_id' => $v->plan_id, 'service_id' => $s]);
            }
        }
        Schema::table('plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn('description');
        });
        Schema::dropIfExists('plan_version_services');
        Schema::dropIfExists('plan_versions');
    }
};
