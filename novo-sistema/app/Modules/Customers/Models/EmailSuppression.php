<?php

namespace App\Modules\Customers\Models;

use App\Modules\Customers\Support\Email;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E-mail que nao pode receber marketing, com ou sem cadastro. Tem prioridade
 * sobre qualquer consentimento.
 */
class EmailSuppression extends Model
{
    protected $table = 'email_suppressions';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'suppressed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $s): void {
            $s->email = mb_strtolower(trim($s->email));
        });
    }

    public static function isSuppressed(?string $email): bool
    {
        $email = Email::normalize($email);

        return $email !== null && static::where('email', $email)->exists();
    }
}
