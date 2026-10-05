<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 10 — comunicacao e avaliacoes (emails.md, lembretes.md, campanhas.md,
 * avaliacoes.md):
 *
 * - email_messages: o registro CENTRAL de todo e-mail do dominio (fila de
 *   saida): categoria (transacional, marketing, conta), modelo, destinatario,
 *   parametros (so identificadores e dados nao sensiveis; o corpo e montado
 *   na hora do envio), chave de unicidade (o mesmo e-mail nunca sai duas
 *   vezes), situacao, tentativas e erro.
 * - campaigns (ampliada) + campaign_recipients: campanha com publico
 *   fotografado, um destinatario por cliente.
 * - appointment_reminders: lembrete e do HORARIO (unico por agendamento,
 *   tipo e horario): remarcar abre um lembrete novo; o antigo nunca sai.
 * - appointments.presence_confirmed_at: confirmacao de presenca pelo link.
 * - customers.email_reminders_enabled: preferencia de lembrete por e-mail
 *   (transacional opcional; marketing segue o consentimento).
 * - customer_notifications: tipo, link e chave de unicidade.
 * - reviews: atendimento (unico), moderacao (pendente/aprovada/recusada) e
 *   destaque; review_replies: uma resposta por avaliacao.
 * - gateway_events.payload_purged_at: retencao de 12 meses (P9-10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('category', 16); // transactional | marketing | account
            $table->string('template', 48);
            $table->string('to_email');
            $table->string('to_name')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject')->nullable();
            $table->json('params')->nullable();
            $table->string('dedupe_key', 191)->nullable()->unique();
            $table->string('related_type', 32)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->string('status', 16); // queued | sending | sent | failed | suppressed | skipped
            $table->string('skip_reason')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'queued_at']);
            $table->index(['related_type', 'related_id']);
            $table->index(['customer_id', 'created_at']);
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->text('body')->nullable();
            $table->json('segment_params')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('skipped_count')->default(0);
            $table->timestamp('cancelled_at')->nullable();
            $table->string('request_key', 64)->nullable()->unique();
            $table->boolean('is_legacy')->default(false);
        });
        DB::table('campaigns')->update(['is_legacy' => true]);

        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->string('status', 16); // queued | sent | failed | skipped
            $table->string('skip_reason')->nullable();
            $table->foreignId('email_message_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['campaign_id', 'customer_id']);
            $table->index(['campaign_id', 'status']);
        });

        Schema::table('appointment_reminders', function (Blueprint $table) {
            $table->dropUnique(['appointment_id', 'kind']);
            $table->timestamp('scheduled_for')->nullable(); // horario do agendamento lembrado
            $table->foreignId('email_message_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('notified_in_app')->default(false);
            $table->unique(['appointment_id', 'kind', 'scheduled_for']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('presence_confirmed_at')->nullable();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('email_reminders_enabled')->default(true);
        });

        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->string('kind', 32)->nullable();
            $table->string('link')->nullable();
            $table->string('dedupe_key', 191)->nullable()->unique();
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->foreignId('attendance_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('approved'); // pending | approved | rejected
            $table->foreignId('moderated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->string('moderation_reason')->nullable();
            $table->foreignId('featured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_legacy')->default(false);
            $table->index(['status', 'reviewed_at']);
        });
        // Avaliacoes ja existentes (importadas) eram exibidas no sistema antigo.
        DB::table('reviews')->update(['status' => 'approved', 'is_legacy' => true]);

        Schema::table('review_replies', function (Blueprint $table) {
            $table->unique('review_id');
        });

        Schema::table('gateway_events', function (Blueprint $table) {
            $table->timestamp('payload_purged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gateway_events', function (Blueprint $table) {
            $table->dropColumn('payload_purged_at');
        });
        Schema::table('review_replies', function (Blueprint $table) {
            $table->dropUnique(['review_id']);
        });
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex(['status', 'reviewed_at']);
            $table->dropUnique(['attendance_id']);
            $table->dropConstrainedForeignId('attendance_id');
            $table->dropConstrainedForeignId('moderated_by_user_id');
            $table->dropConstrainedForeignId('featured_by_user_id');
            $table->dropColumn(['status', 'moderated_at', 'moderation_reason', 'is_legacy']);
        });
        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->dropUnique(['dedupe_key']);
            $table->dropColumn(['kind', 'link', 'dedupe_key']);
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('email_reminders_enabled');
        });
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('presence_confirmed_at');
        });
        Schema::table('appointment_reminders', function (Blueprint $table) {
            $table->dropUnique(['appointment_id', 'kind', 'scheduled_for']);
            $table->dropConstrainedForeignId('email_message_id');
            $table->dropColumn(['scheduled_for', 'notified_in_app']);
        });
        // Volta a regra antiga (um por tipo): remove repeticoes de remarcacao.
        foreach (DB::table('appointment_reminders')->select('appointment_id', 'kind', DB::raw('MIN(id) as manter'))->groupBy('appointment_id', 'kind')->get() as $g) {
            DB::table('appointment_reminders')->where('appointment_id', $g->appointment_id)->where('kind', $g->kind)->where('id', '<>', $g->manter)->delete();
        }
        Schema::table('appointment_reminders', function (Blueprint $table) {
            $table->unique(['appointment_id', 'kind']);
        });
        Schema::dropIfExists('campaign_recipients');
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropUnique(['request_key']);
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropConstrainedForeignId('sent_by_user_id');
            $table->dropColumn(['name', 'body', 'segment_params', 'skipped_count', 'cancelled_at', 'request_key', 'is_legacy']);
        });
        Schema::dropIfExists('email_messages');
    }
};
