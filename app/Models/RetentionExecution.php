<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class RetentionExecution extends Model
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_DRY_RUN   = 'dry_run';
    public const STATUS_FAILED    = 'failed';

    protected $fillable = [
        'execution_id', 'policy_id', 'dry_run', 'rows_scanned', 'rows_eligible',
        'rows_deleted', 'execution_time_ms', 'status', 'actor_id', 'error_message',
    ];

    protected $casts = ['dry_run' => 'boolean'];

    public static function generateExecutionId(): string
    {
        return 'rexec-' . Str::uuid();
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(RetentionPolicy::class, 'policy_id');
    }
}
