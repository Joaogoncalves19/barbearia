<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cupons e vales-presente. Codigos unicos (o sistema antigo permitia
     * repetidos). Cupom: codigo sempre em maiusculas.
     */
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('discount_type', 16); // percent | fixed
            $table->unsignedSmallInteger('percent_bp')->nullable();
            $table->integer('amount_cents')->nullable();
            $table->unsignedInteger('max_uses')->nullable(); // nulo = ilimitado
            $table->unsignedInteger('uses_count')->default(0);
            $table->date('expires_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('gift_cards', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->integer('amount_cents');
            $table->string('status', 16);
            $table->timestamp('issued_at')->nullable();
            $table->date('expires_on')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->string('purchaser_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_cards');
        Schema::dropIfExists('coupons');
    }
};
