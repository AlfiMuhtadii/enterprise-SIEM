<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DegradedModeEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_id', 'service_name', 'degraded_reason', 'event_type',
        'description', 'operator_acknowledged', 'auto_cleared',
        'trace_id', 'metadata', 'created_at',
    ];

    protected $casts = [
        'operator_acknowledged'=> 'boolean',
        'auto_cleared'         => 'boolean',
        'metadata'             => 'array',
        'created_at'           => 'datetime',
    ];

    public const REASONS = [
        'dependency_unavailable',
        'storage_write_degraded',
        'stream_lag_degraded',
        'worker_stalled',
        'replay_throttled',
        'dlq_pressure',
        'search_unavailable',
        'ai_retrieval_unavailable',
    ];

    public const EVENT_TYPE_ENTERED            = 'entered';
    public const EVENT_TYPE_CLEARED            = 'cleared';
    public const EVENT_TYPE_VALIDATION_REQUIRED= 'validation_required';

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('degraded_mode_events is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
