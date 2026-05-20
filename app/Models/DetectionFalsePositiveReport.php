<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only analyst false-positive report.
 * Never deleted — historical FP reports feed quality scoring.
 */
class DetectionFalsePositiveReport extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'report_id', 'rule_id', 'reported_by', 'reason_type', 'reason_detail',
        'alert_id', 'trace_id', 'event_evidence',
        'recommends_suppression', 'suppression_scope', 'analyst_verdict', 'created_at',
    ];

    protected $casts = [
        'event_evidence'         => 'array',
        'recommends_suppression' => 'boolean',
        'created_at'             => 'datetime',
    ];

    // Reason taxonomy
    public const REASON_BENIGN_ACTIVITY  = 'benign_activity';
    public const REASON_NOISY_CONDITION  = 'noisy_condition';
    public const REASON_MISCONFIGURATION = 'misconfiguration';
    public const REASON_CONTEXT_GAP      = 'context_gap';
    public const REASON_OTHER            = 'other';

    public const REASON_TYPES = [
        self::REASON_BENIGN_ACTIVITY,
        self::REASON_NOISY_CONDITION,
        self::REASON_MISCONFIGURATION,
        self::REASON_CONTEXT_GAP,
        self::REASON_OTHER,
    ];

    // Verdicts
    public const VERDICT_UNDER_REVIEW = 'under_review';
    public const VERDICT_CONFIRMED_FP = 'confirmed_fp';
    public const VERDICT_REJECTED     = 'rejected';

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public static function generateReportId(): string
    {
        return 'dfp-' . substr(str_replace('-', '', (string) \Illuminate\Support\Str::uuid()), 0, 36);
    }
}
