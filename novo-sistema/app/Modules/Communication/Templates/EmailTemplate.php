<?php

namespace App\Modules\Communication\Templates;

use App\Modules\Communication\Enums\MessageCategory;
use App\Modules\Communication\Models\EmailMessage;

/**
 * Um modelo de e-mail (templates.md). Monta o e-mail NA HORA DO ENVIO, a
 * partir do estado atual do dominio, e pode recusar: devolve um texto com o
 * motivo quando o e-mail nao se aplica mais (agendamento cancelado ou
 * remarcado, assinatura que ja mudou de situacao...). Assim, um e-mail
 * enfileirado antes de uma mudanca nunca sai errado.
 */
interface EmailTemplate
{
    public function key(): string;

    public function category(): MessageCategory;

    /** Nome curto para telas (registro de e-mails, pre-visualizacao). */
    public function label(): string;

    public function render(EmailMessage $message): RenderedEmail|string;

    /** Exemplo com dados ficticios, para a pre-visualizacao no painel. */
    public function preview(): RenderedEmail;
}
