<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only: spool health snapshots from agent heartbeat reports.
 * Records local telemetry durability state per heartbeat cycle.
 * Never updated after insert.
 */
class EndpointSpoolSnapshot extends Model
{
    public $timestamps = false;

    public const SPOOL_CAP_BYTES = 10 * 1024 * 1024; // 10 MiB — matches Python agent constant

    protected $table = 'endpoint_spool_snapshots';

    protected $fillable = [
        'snapshot_id', 'agent_id', 'queued_events', 'dropped_events',
        'retry_count', 'spool_disk_bytes', 'oldest_spool_age_seconds',
        'events_per_sec', 'buffer_depth', 'spool_capped', 'disk_pressure',
        'trace_id', 'recorded_at', 'created_at',
    ];

    protected $casts = [
        'queued_events'             => 'integer',
        'dropped_events'            => 'integer',
        'retry_count'               => 'integer',
        'spool_disk_bytes'          => 'integer',
        'oldest_spool_age_seconds'  => 'integer',
        'events_per_sec'            => 'float',
        'buffer_depth'              => 'integer',
        'spool_capped'              => 'boolean',
        'disk_pressure'             => 'boolean',
        'recorded_at'               => 'datetime',
        'created_at'                => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(EndpointAgent::class, 'agent_id');
    }

    public function spoolUtilizationPercent(): float
    {
        if ($this->spool_disk_bytes <= 0) {
            return 0.0;
        }
        return round(($this->spool_disk_bytes / self::SPOOL_CAP_BYTES) * 100.0, 2);
    }
}
