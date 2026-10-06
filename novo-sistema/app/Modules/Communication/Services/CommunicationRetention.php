<?php

namespace App\Modules\Communication\Services;

use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\System\Services\AuditTrail;
use Illuminate\Support\Facades\DB;

/**
 * Retencao dos registros de comunicacao (decisao do dono P10-03;
 * retencao-lgpd.md). Registros operacionais ficam 12 meses; depois:
 *
 * - email_messages: sai o endereco, o nome, o assunto (pode ter o nome) e o
 *   erro tecnico (pode ter o endereco). Fica o necessario para auditoria:
 *   modelo, tipo, situacao, datas, tentativas, provedor, vinculos (cliente,
 *   agendamento, campanha) e os parametros (so identificadores). Prova, por
 *   exemplo, que a campanha so saiu com consentimento, sem guardar o e-mail.
 * - campaign_recipients: sai o endereco; fica a situacao e o motivo.
 * - customer_notifications: apagados (avisos de conta nao tem valor de
 *   auditoria; o acontecimento continua no historico de origem).
 *
 * Automatica (diaria, app:communication retention), idempotente e auditada.
 * A prova de consentimento (consent_records) NAO entra aqui: e obrigacao legal.
 * Atualizacao direta (query): o model do registro so deixa mudar a situacao.
 */
final class CommunicationRetention
{
    public const MONTHS = 12;

    public const REDACTED = '[removido]';

    /**
     * @return array{emails: int, destinatarios: int, avisos: int}
     */
    public function run(): array
    {
        $limite = BusinessTime::now()->subMonths(self::MONTHS);
        $agora = BusinessTime::now();

        $emails = DB::table('email_messages')->whereNull('purged_at')->where('created_at', '<', $limite)
            ->whereNotIn('status', [MessageStatus::Queued->value, MessageStatus::Sending->value])
            ->update(['to_email' => self::REDACTED, 'to_name' => null, 'subject' => null, 'last_error' => null, 'purged_at' => $agora, 'updated_at' => $agora]);
        $destinatarios = DB::table('campaign_recipients')->whereNull('purged_at')->where('created_at', '<', $limite)
            ->whereNotIn('status', ['queued', 'dispatching'])
            ->update(['email' => self::REDACTED, 'purged_at' => $agora, 'updated_at' => $agora]);
        $avisos = DB::table('customer_notifications')->where('created_at', '<', $limite)->delete();

        $n = ['emails' => $emails, 'destinatarios' => $destinatarios, 'avisos' => $avisos];
        if (array_sum($n) > 0) {
            AuditTrail::record('retention.communication', null, null,
                'Retenção da comunicação (mais de '.self::MONTHS." meses): {$emails} e-mail(s) e {$destinatarios} destinatário(s) anonimizados, {$avisos} aviso(s) apagado(s).",
                [...$n, 'criados_antes_de' => $limite->toIso8601String()]);
        }

        return $n;
    }
}
