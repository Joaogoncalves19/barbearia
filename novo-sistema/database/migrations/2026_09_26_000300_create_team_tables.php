<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profissionais e disponibilidade. O profissional pode existir sem login
     * (user_id nulo). Percentuais em pontos-base (10000 = 100%).
     */
    public function up(): void
    {
        Schema::create('professionals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('display_name');
            $table->string('photo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_bookable')->default(true);
            $table->integer('sort_order')->default(0);
            $table->unsignedSmallInteger('commission_rate_bp')->default(0);
            $table->boolean('commission_on_products')->default(false);
            $table->string('subscription_commission_mode', 16)->default('default');
            $table->unsignedSmallInteger('subscription_commission_rate_bp')->nullable();
            $table->integer('subscription_commission_amount_cents')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('professional_service', function (Blueprint $table) {
            $table->foreignId('professional_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->primary(['professional_id', 'service_id']);
        });

        Schema::create('professional_package', function (Blueprint $table) {
            $table->foreignId('professional_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->primary(['professional_id', 'package_id']);
        });

        Schema::create('working_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 0 = domingo ... 6 = sabado
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();

            $table->unique(['professional_id', 'weekday', 'starts_at']);
        });

        Schema::create('schedule_breaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday')->nullable(); // nulo = todos os dias
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('time_off', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('kind', 32);
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['professional_id', 'starts_on']);
        });

        Schema::create('blocked_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['professional_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_slots');
        Schema::dropIfExists('time_off');
        Schema::dropIfExists('schedule_breaks');
        Schema::dropIfExists('working_hours');
        Schema::dropIfExists('professional_package');
        Schema::dropIfExists('professional_service');
        Schema::dropIfExists('professionals');
    }
};
