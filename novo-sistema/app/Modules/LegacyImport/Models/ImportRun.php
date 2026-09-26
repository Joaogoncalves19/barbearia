<?php

namespace App\Modules\LegacyImport\Models;

use App\Modules\LegacyImport\Enums\ImportMode;
use App\Modules\LegacyImport\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportRun extends Model
{
    protected $table = 'import_runs';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => ImportMode::class,
            'status' => ImportStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'counters' => 'array',
        ];
    }

    /**
     * @return HasMany<ImportIssue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(ImportIssue::class);
    }
}
