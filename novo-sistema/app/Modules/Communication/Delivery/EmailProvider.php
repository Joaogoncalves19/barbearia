<?php

namespace App\Modules\Communication\Delivery;

use App\Modules\Communication\Exceptions\EmailDeliveryFailed;
use App\Modules\Communication\Models\EmailMessage;
use App\Modules\Communication\Templates\RenderedEmail;

/**
 * Provedor de entrega de e-mail (D-05; emails.md §7). A UNICA fronteira com o
 * fornecedor: fila, modelos, regras de envio, preferencias, novas tentativas
 * e historico nao sabem qual provedor esta configurado. Trocar de fornecedor =
 * nova classe que implementa esta interface + EMAIL_PROVIDER no ambiente.
 *
 * Regras para qualquer provedor:
 * - credenciais so da configuracao (variaveis de ambiente), nunca no log;
 * - tempo limite curto (nenhum worker fica preso no provedor);
 * - a chave de idempotencia e o identificador publico do registro (o mesmo
 *   registro reenviado nao vira dois e-mails no provedor, quando ele suporta);
 * - erro vira EmailDeliveryFailed com mensagem SEM credenciais.
 */
interface EmailProvider
{
    /** Nome curto gravado no registro (ex.: "mailer", "resend"). */
    public function name(): string;

    /**
     * Entrega o e-mail montado. Devolve o identificador do provedor, se houver.
     *
     * @throws EmailDeliveryFailed
     */
    public function send(EmailMessage $record, RenderedEmail $email): ?string;
}
