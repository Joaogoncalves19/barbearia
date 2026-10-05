<?php

namespace App\Modules\Communication\Exceptions;

use RuntimeException;

/**
 * Falha de entrega ao provedor, com a mensagem JA SEM credenciais
 * (Outbox::safeError). E a unica excecao que sai do Outbox para o worker: o
 * erro cru do provedor (que pode trazer usuario, servidor ou senha) nunca
 * chega ao log, ao failed_jobs nem ao registro.
 */
final class EmailDeliveryFailed extends RuntimeException {}
