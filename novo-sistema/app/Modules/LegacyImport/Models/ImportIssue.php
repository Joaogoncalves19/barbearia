<?php

namespace App\Modules\LegacyImport\Models;

use App\Modules\LegacyImport\Enums\IssueClassification;
use App\Modules\LegacyImport\Enums\IssueSeverity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportIssue extends Model
{
    protected $table = 'import_issues';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'classification' => IssueClassification::class,
            'severity' => IssueSeverity::class,
            'context' => 'array',
            'needs_decision' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ImportRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'import_run_id');
    }
}
