<?php

namespace App\Modules\Communication\Enums;

/**
 * Categoria do e-mail (consentimento.md):
 * - Transactional: necessario ao servico (confirmacao, alteracao,
 *   cancelamento, lembrete, comprovante, assinatura, pedido de avaliacao).
 *   NAO depende do consentimento de marketing; so nao sai para endereco
 *   bloqueado por devolucao/reclamacao, e o lembrete respeita a preferencia
 *   do cliente.
 * - Marketing: campanhas, promocoes, ofertas. So com consentimento
 *   CONCEDIDO ("desconhecido" nao autoriza) e fora da lista de supressao.
 */
enum MessageCategory: string
{
    case Transactional = 'transactional';
    case Marketing = 'marketing';

    public function label(): string
    {
        return match ($this) {
            self::Transactional => 'Transacional',
            self::Marketing => 'Marketing',
        };
    }
}
