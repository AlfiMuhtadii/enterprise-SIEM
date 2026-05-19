<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class DlqReplayJob extends Model
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_SIMULATED  = 'simulated';
    public const STATUS_EXECUTING  = 'executing';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_CANCELLED  = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SIMULATED,
        self::STATUS_EXECUTING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    public const MAX_BATCH_LIMIT = 500; // hard cap on replay batch size

    protected $fillable = [
        'job_id', 'topic', 'source_partition', 'from_offset', 'to_offset',
        'batch_limit', 'dry_run', 'status', 'deduplication_key',
        'rows_replayed', 'rows_skipped', 'risk_score', 'actor_id', 'error_message',
    ];

    protected $casts = [
        'dry_run'     => 'boolean',
        'risk_score'  => 'float',
    ];

    public static function generateJobId(): string
    {
        return 'dlq-' . Str::uuid();
    }

    public function events(): HasMany
    {
        return $this->hasMany(DlqReplayEvent::class, 'job_id', 'job_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
    }
}
