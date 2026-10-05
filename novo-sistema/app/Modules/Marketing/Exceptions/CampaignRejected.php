<?php

namespace App\Modules\Marketing\Exceptions;

use RuntimeException;

/** Acao de campanha recusada; nada foi gravado. */
final class CampaignRejected extends RuntimeException {}
