<?php

namespace App\Modules\Reviews\Exceptions;

use RuntimeException;

/** Avaliacao ou moderacao recusada; nada foi gravado. */
final class ReviewRejected extends RuntimeException {}
