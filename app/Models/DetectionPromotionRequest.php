<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only promotion request record.
 * Every promotion attempt is recorded — approved, rejected, or withdrawn.
 * No autonomous promotion — every request requires operator review.
 */
class DetectionPromotionRequest extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'request_id', 'rule_id', 'from_stage', 'to_stage',
        'requested_by', 'rationale', 'status', 'reviewed_by', 'review_note',
        'reviewed_at', 'replay_result_id', 'soak_report_path',
        'gate_snapshot', 'fp_summary', 'created_at',
    ];

    protected $casts = [
        'gate_snapshot' => 'array',
        'fp_summary'    => 'array',
        'reviewed_at'   => 'datetime',
        'created_at'    => 'datetime',
    ];

    // Request statuses
    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_WITHDRAWN = 'withdrawn';

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public static function generateRequestId(): string
    {
        return 'dpr-' . substr(str_replace('-', '', (string) \Illuminate\Support\Str::uuid()), 0, 36);
    }
}
