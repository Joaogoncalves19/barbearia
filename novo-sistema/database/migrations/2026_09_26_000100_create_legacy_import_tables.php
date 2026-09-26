<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rastreabilidade da migracao do sistema antigo.
     *
     * legacy_references: (tabela antiga, id antigo) -> (entidade nova, id novo).
     * E o que torna o importador idempotente e auditavel.
     */
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 16); // dry_run | import
            $table->string('status', 16); // running | completed | failed | rolled_back
            $table->string('source_path');
            $table->char('source_sha256', 64);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->json('counters')->nullable();
            $table->string('report_path')->nullable();
            $table->timestamps();
        });

        Schema::create('import_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_run_id')->constrained()->cascadeOnDelete();
            $table->string('source_table', 64);
            $table->string('source_id')->nullable();
            $table->string('classification', 24);
            $table->string('severity', 16);
            $table->string('code', 64);
            $table->text('message');
            $table->json('context')->nullable();
            $table->boolean('needs_decision')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['import_run_id', 'code']);
            $table->index(['source_table', 'source_id']);
        });

        Schema::create('legacy_references', function (Blueprint $table) {
            $table->id();
            $table->string('source_table', 64);
            $table->string('source_id');
            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id');
            $table->char('checksum', 64);
            $table->foreignId('import_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['source_table', 'source_id']);
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_references');
        Schema::dropIfExists('import_issues');
        Schema::dropIfExists('import_runs');
    }
};
