<?php

namespace App\Modules\Communication\Delivery;

use App\Modules\Communication\Exceptions\EmailDeliveryFailed;
use App\Modules\Communication\Mail\CommunicationMail;
use App\Modules\Communication\Models\EmailMessage;
use App\Modules\Communication\Services\Outbox;
use App\Modules\Communication\Templates\RenderedEmail;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Resend (D-05), pela API HTTP (POST /emails), sem SDK: poucas chamadas,
 * testavel sem rede (Http::fake), mesmo padrao do Stripe (T9-01).
 *
 * - Chave: RESEND_API_KEY (so no ambiente). Sem chave, nada sai e o registro
 *   fica com o erro "provedor nao configurado" (tenta de novo; falha no fim).
 * - Idempotency-Key = identificador publico do registro: reenvio do mesmo
 *   registro nao vira dois e-mails no Resend (janela de 24 h do provedor),
 *   alem da reivindicacao do Outbox.
 * - O corpo e o MESMO HTML do CommunicationMail (layout da identidade), com os
 *   cabecalhos de descadastro do marketing.
 * - Tempo limite curto; erro sem a chave e sem o corpo da resposta inteiro.
 */
final class ResendProvider implements EmailProvider
{
    public function name(): string
    {
        return 'resend';
    }

    public function send(EmailMessage $record, RenderedEmail $email): ?string
    {
        $chave = (string) config('services.resend.key');
        if ($chave === '') {
            throw new EmailDeliveryFailed('Provedor Resend não configurado (RESEND_API_KEY vazia).');
        }

        $mail = new CommunicationMail($email, $record->public_id);
        $cabecalhos = ['X-Barbearia-Message' => $record->public_id];
        if ($email->oneClickUrl !== null) {
            $cabecalhos['List-Unsubscribe'] = '<'.$email->oneClickUrl.'>';
            $cabecalhos['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        } elseif ($email->unsubscribeUrl !== null) {
            $cabecalhos['List-Unsubscribe'] = '<'.$email->unsubscribeUrl.'>';
        }
        $de = (string) config('mail.from.address');
        $nome = trim((string) config('mail.from.name'));
        $para = $record->to_name !== null && $record->to_name !== '' ? $this->address($record->to_email, $record->to_name) : $record->to_email;

        try {
            $resposta = Http::withToken($chave)
                ->withHeaders(['Idempotency-Key' => $record->public_id])
                ->acceptJson()
                ->timeout((int) config('services.resend.timeout', 10))
                ->connectTimeout(5)
                ->post(rtrim((string) config('services.resend.api_base', 'https://api.resend.com'), '/').'/emails', [
                    'from' => $nome !== '' ? $this->address($de, $nome) : $de,
                    'to' => [$para],
                    'subject' => $email->subject,
                    'html' => $mail->render(),
                    'headers' => $cabecalhos,
                ]);
        } catch (ConnectionException $e) {
            throw new EmailDeliveryFailed('Resend inacessível: '.Outbox::safeError($e));
        }

        if (! $resposta->successful()) {
            $detalhe = is_string($resposta->json('message')) ? mb_substr((string) $resposta->json('message'), 0, 200) : '';
            throw new EmailDeliveryFailed(mb_substr('Resend HTTP '.$resposta->status().($detalhe !== '' ? ': '.str_replace($chave, '***', $detalhe) : ''), 0, 500));
        }
        $id = $resposta->json('id');

        return is_string($id) ? mb_substr($id, 0, 100) : null;
    }

    /** "Nome <email>" com o nome sem aspas nem quebras. */
    private function address(string $email, string $name): string
    {
        $limpo = trim((string) preg_replace('/["<>\r\n]+/', ' ', $name));

        return $limpo !== '' ? '"'.$limpo.'" <'.$email.'>' : $email;
    }
}
