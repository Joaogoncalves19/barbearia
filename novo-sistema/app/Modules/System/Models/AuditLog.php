<?php

namespace App\Modules\System\Models;

use App\Modules\Shared\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use AppendOnly;

    protected $table = 'audit_logs';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }
}
