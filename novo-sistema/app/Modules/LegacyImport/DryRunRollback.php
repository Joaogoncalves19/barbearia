<?php

namespace App\Modules\LegacyImport;

use RuntimeException;

/** Lancada ao fim da simulacao para desfazer a transacao inteira. */
final class DryRunRollback extends RuntimeException {}
