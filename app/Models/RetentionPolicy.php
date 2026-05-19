<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class RetentionPolicy extends Model
{
    public const STATUS_ACTIVE       = 'active';
    public const STATUS_PAUSED       = 'paused';
    public const STATUS_DRY_RUN_ONLY = 'dry_run_only';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
        self::STATUS_DRY_RUN_ONLY,
    ];

    // Domains eligible for retention — append-only audit tables are NEVER included
    public const ELIGIBLE_DOMAINS = [
        'endpoint_stream_events',
        'endpoint_behavioral_findings',
        'endpoint_stream_health',
        'endpoint_process_entries',
        'queue_lag_metrics',
        'stream_pressure_metrics',
        'service_health_snapshots',
    ];

    protected $fillable = [
        'policy_id', 'domain', 'target_table', 'time_column', 'retention_days',
        'max_rows', 'batch_size', 'status', 'safe_archive_mode',
        'append_only_protected', 'description',
    ];

    protected $casts = [
        'safe_archive_mode'      => 'boolean',
        'append_only_protected'  => 'boolean',
    ];

    public static function generatePolicyId(): string
    {
        return 'ret-' . Str::uuid();
    }

    public function executions(): HasMany
    {
        return $this->hasMany(RetentionExecution::class, 'policy_id');
    }

    public function isEligibleForDeletion(): bool
    {
        return $this->status === self::STATUS_ACTIVE && !$this->append_only_protected;
    }
}
