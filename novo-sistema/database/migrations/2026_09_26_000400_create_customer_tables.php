<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clientes finais (guard proprio, separado da equipe).
     *
     * email/phone/cpf sao unicos e anulaveis: valores normalizados
     * (e-mail minusculo, telefone E.164, CPF so digitos). Duplicidades do
     * sistema antigo NUNCA sao mescladas automaticamente: viram
     * customer_merge_candidates.
     *
     * Consentimento de marketing: unknown | granted | revoked. Ausencia de
     * informacao e "unknown", nunca "granted".
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('phone', 20)->nullable()->unique();
            $table->char('cpf', 11)->nullable()->unique();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->date('birth_date')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('status', 16)->default('active');
            $table->string('referral_code', 32)->nullable()->unique();
            $table->foreignId('referred_by_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('marketing_email_consent', 16)->default('unknown');
            $table->timestamp('marketing_consent_updated_at')->nullable();
            $table->foreignId('merged_into_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamp('anonymized_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
        });

        Schema::create('customer_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_label')->nullable();
            $table->string('visibility', 16)->default('team'); // team | professionals
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('customer_favorite_professionals', function (Blueprint $table) {
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['customer_id', 'professional_id']);
        });

        Schema::create('customer_merge_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('duplicate_customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->string('match_field', 24);
            $table->string('match_value');
            $table->string('status', 16)->default('pending');
            $table->foreignId('import_run_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'duplicate_customer_id', 'match_field'], 'merge_candidates_pair_unique');
            $table->index('status');
        });

        Schema::create('consent_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('purpose', 32);
            $table->string('action', 16); // granted | revoked
            $table->string('source', 32);
            $table->timestamp('occurred_at')->nullable();
            $table->string('evidence')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['customer_id', 'purpose']);
            $table->index('email');
        });

        Schema::create('email_suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('reason', 32);
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->text('message');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notifications');
        Schema::dropIfExists('email_suppressions');
        Schema::dropIfExists('consent_records');
        Schema::dropIfExists('customer_merge_candidates');
        Schema::dropIfExists('customer_favorite_professionals');
        Schema::dropIfExists('customer_notes');
        Schema::dropIfExists('customers');
    }
};
