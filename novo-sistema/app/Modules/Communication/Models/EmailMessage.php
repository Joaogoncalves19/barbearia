<?php

namespace App\Modules\Communication\Models;

use App\Modules\Communication\Enums\MessageCategory;
use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Um e-mail do dominio no registro central (emails.md). Guarda o modelo e os
 * PARAMETROS (identificadores e dados nao sensiveis), nunca o corpo pronto
 * nem senha/token: o corpo e montado na hora do envio, a partir do estado
 * atual (um lembrete de agendamento cancelado nao sai). A chave de unicidade
 * (dedupe_key) garante que o mesmo e-mail do dominio nunca e enfileirado
 * duas vezes. Nunca e apagado.
 *
 * @property int $id
 * @property string $public_id
 * @property MessageCategory $category
 * @property string $template
 * @property string $to_email
 * @property string|null $to_name
 * @property int|null $customer_id
 * @property string|null $subject
 * @property array<string, scalar|null>|null $params
 * @property string|null $dedupe_key
 * @property string|null $related_type
 * @property int|null $related_id
 * @property int|null $campaign_id
 * @property MessageStatus $status
 * @property string|null $skip_reason
 * @property int $attempts
 * @property string|null $last_error
 * @property CarbonInterface|null $queued_at
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $failed_at
 * @property CarbonInterface|null $created_at
 */
class EmailMessage extends Model
{
    protected $table = 'email_messages';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => MessageCategory::class,
            'status' => MessageStatus::class,
            'params' => 'array',
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $m): void {
            $m->public_id ??= (string) Str::uuid();
        });
        static::updating(function (self $m): void {
            if (array_diff(array_keys($m->getDirty()), ['status', 'skip_reason', 'attempts', 'last_error', 'sent_at', 'failed_at', 'queued_at', 'subject', 'updated_at']) !== []) {
                throw DomainRuleViolation::rule('R-HIST', 'Registro de e-mail: só a situação muda.');
            }
        });
        static::deleting(fn () => throw DomainRuleViolation::rule('R-HIST', 'Registro de e-mail é histórico.'));
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function param(string $key): mixed
    {
        return $this->params[$key] ?? null;
    }

    /** "jo***@exemplo.test" para telas e auditoria. */
    public function maskedEmail(): string
    {
        return self::mask($this->to_email);
    }

    public static function mask(string $email): string
    {
        [$local, $dominio] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 2).'***@'.$dominio;
    }
}
