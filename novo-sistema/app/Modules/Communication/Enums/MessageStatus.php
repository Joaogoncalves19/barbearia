<?php

namespace App\Modules\Communication\Enums;

/**
 * Situacao de um e-mail no registro central (fila.md):
 * queued -> sending -> sent
 *                   -> queued (erro: nova tentativa com espera crescente)
 *                   -> failed (esgotou as tentativas; pode ser reenviado)
 * queued -> suppressed (consentimento/supressao na hora do envio)
 * queued -> skipped (nao se aplica mais: agendamento cancelado, remarcado...)
 */
enum MessageStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Suppressed = 'suppressed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Na fila',
            self::Sending => 'Enviando',
            self::Sent => 'Enviado',
            self::Failed => 'Falhou',
            self::Suppressed => 'Bloqueado (consentimento/descadastro)',
            self::Skipped => 'Não se aplica mais',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Sent, self::Suppressed, self::Skipped], true);
    }
}
