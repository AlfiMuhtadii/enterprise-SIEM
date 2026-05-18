<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScenarioRun extends Model
{
    protected $fillable = [
        'scenario_id',
        'user_id',
        'status',
        'run_mode',
        'trace_id',
        'config',
        'results',
        'alerts_detected',
        'detection_passed',
        'validation_result',
        'failure_reason',
        'recommendation',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'config'           => 'array',
        'results'          => 'array',
        'detection_passed' => 'boolean',
        'started_at'       => 'datetime',
        'completed_at'     => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(ScenarioEvidence::class);
    }

    public function definition(): ?array
    {
        $scenarios = collect(config('scenarios.scenarios', []));
        return $scenarios->firstWhere('id', $this->scenario_id);
    }

    public function durationSeconds(): ?int
    {
        if (!$this->started_at || !$this->completed_at) {
            return null;
        }
        return (int) $this->started_at->diffInSeconds($this->completed_at);
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'completed' => 'badge-success',
            'running'   => 'badge-info',
            'failed'    => 'badge-danger',
            'stopped'   => 'badge-warning',
            default     => 'badge-muted',
        };
    }

    public function validationBadgeClass(): string
    {
        return match ($this->validation_result) {
            'PASS'    => 'badge-success',
            'PARTIAL' => 'badge-warning',
            'FAIL'    => 'badge-danger',
            default   => 'badge-muted',
        };
    }
}
