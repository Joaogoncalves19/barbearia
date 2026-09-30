<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 5: agenda.
     *
     * - business_hours: horario de funcionamento da BARBEARIA. Horas de parede
     *   no fuso da barbearia (config barbearia.display_timezone). Varios
     *   intervalos no mesmo dia sao permitidos (ex.: fecha para almoco); dia
     *   sem linha = fechado.
     * - blocked_slots.professional_id anulavel: bloqueio SEM profissional vale
     *   para a barbearia inteira (feriado, evento, manutencao).
     * - blocked_slots/time_off.created_by_user_id: quem criou (a auditoria
     *   guarda o resto).
     * - professionals.schedule_version: linha de bloqueio da agenda do
     *   profissional. Toda reserva/remarcacao/cancelamento incrementa este
     *   numero dentro da transacao ANTES de revalidar a disponibilidade:
     *   duas reservas simultaneas para o mesmo profissional ficam em fila
     *   (bloqueio de linha no MySQL, de escrita no SQLite). E a protecao
     *   estrutural contra dupla reserva.
     * - appointments.customer_reschedules: quantas vezes o PROPRIO cliente
     *   remarcou (limite configuravel). created_by_user_id: quem da equipe criou.
     */
    public function up(): void
    {
        Schema::create('business_hours', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('weekday'); // 0 = domingo ... 6 = sabado
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();

            $table->unique(['weekday', 'starts_at']);
        });

        Schema::table('blocked_slots', function (Blueprint $table) {
            $table->foreignId('professional_id')->nullable()->change();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['starts_at', 'ends_at']);
        });

        Schema::table('time_off', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('professionals', function (Blueprint $table) {
            $table->unsignedInteger('schedule_version')->default(0);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->unsignedSmallInteger('customer_reschedules')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn('customer_reschedules');
        });

        Schema::table('professionals', function (Blueprint $table) {
            $table->dropColumn('schedule_version');
        });

        Schema::table('time_off', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_user_id');
        });

        Schema::table('blocked_slots', function (Blueprint $table) {
            $table->dropIndex(['starts_at', 'ends_at']);
            $table->dropConstrainedForeignId('created_by_user_id');
        });

        Schema::dropIfExists('business_hours');
    }
};
