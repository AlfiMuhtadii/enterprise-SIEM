<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only replay execution result.
 * Records the outcome of running a replay pack against a rule.
 */
class DetectionReplayResult extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'result_id', 'pack_id', 'rule_id', 'passed',
        'cases_run', 'cases_passed', 'cases_failed', 'failure_details',
        'evidence_mismatch', 'trace_id_missing', 'unexpected_enforcement',
        'runner', 'triggered_by', 'report_path', 'created_at',
    ];

    protected $casts = [
        'passed'                => 'boolean',
        'evidence_mismatch'     => 'boolean',
        'trace_id_missing'      => 'boolean',
        'unexpected_enforcement'=> 'boolean',
        'failure_details'       => 'array',
        'created_at'            => 'datetime',
    ];

    public function pack(): BelongsTo
    {
        return $this->belongsTo(DetectionReplayPack::class, 'pack_id', 'pack_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public static function generateResultId(): string
    {
        return 'drr-' . substr(str_replace('-', '', (string) \Illuminate\Support\Str::uuid()), 0, 36);
    }
}
