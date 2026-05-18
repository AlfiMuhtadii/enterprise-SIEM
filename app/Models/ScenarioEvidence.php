<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScenarioEvidence extends Model
{
    protected $fillable = [
        'scenario_run_id',
        'stage',
        'stage_type',
        'event_id',
        'trace_id',
        'rule_id',
        'severity',
        'status',
        'payload',
        'processed_at',
        'latency_ms',
    ];

    protected $casts = [
        'payload'      => 'array',
        'processed_at' => 'datetime',
        'latency_ms'   => 'integer',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ScenarioRun::class, 'scenario_run_id');
    }

    public function statusIcon(): string
    {
        return match ($this->status) {
            'detected'   => '✓',
            'processing' => '⟳',
            'failed'     => '✗',
            default      => '…',
        };
    }
}
