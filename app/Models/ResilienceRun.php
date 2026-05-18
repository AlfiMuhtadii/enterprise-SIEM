<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ResilienceRun extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PASSED  = 'passed';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_ERROR   = 'error';

    public const TYPE_ACTIVE     = 'active';
    public const TYPE_SIMULATION = 'simulation';

    protected $fillable = [
        'run_id', 'scenario_id', 'scenario_name', 'scenario_type',
        'status', 'recovery_validated', 'replay_safe', 'trace_preserved',
        'endpoint_shadow_isolated', 'no_duplicates', 'findings', 'report_path',
        'started_at', 'completed_at',
    ];

    protected $casts = [
        'findings'               => 'array',
        'recovery_validated'     => 'boolean',
        'replay_safe'            => 'boolean',
        'trace_preserved'        => 'boolean',
        'endpoint_shadow_isolated' => 'boolean',
        'no_duplicates'          => 'boolean',
        'started_at'             => 'datetime',
        'completed_at'           => 'datetime',
    ];

    public function metrics(): HasMany
    {
        return $this->hasMany(ResilienceMetric::class, 'run_id');
    }

    public function durationSeconds(): ?float
    {
        if (!$this->started_at || !$this->completed_at) {
            return null;
        }
        return $this->started_at->diffInSeconds($this->completed_at, true);
    }

    public static function generateRunId(): string
    {
        $year = now()->year;
        $last = DB::table('resilience_runs')
            ->where('run_id', 'like', "RES-{$year}-%")
            ->orderByDesc('run_id')
            ->value('run_id');

        $seq = $last
            ? (int) substr($last, -5) + 1
            : 1;

        return sprintf('RES-%d-%05d', $year, $seq);
    }

    public function passed(): bool
    {
        return $this->status === self::STATUS_PASSED;
    }
}
