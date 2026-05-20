<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Suppression record for a detection rule.
 * Approval-gated — no automatic suppression without operator action.
 * Suppressions do not delete original evidence.
 * Suppressions do not mutate historical alerts.
 */
class DetectionSuppression extends Model
{
    protected $fillable = [
        'suppression_id', 'rule_id', 'scope', 'scope_value', 'reason',
        'created_by', 'approved_by', 'approval_state', 'approved_at',
        'expires_at', 'is_active', 'fp_report_id',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'approved_at' => 'datetime',
        'expires_at'  => 'datetime',
    ];

    // Scope types
    public const SCOPE_GLOBAL      = 'global';
    public const SCOPE_HOST        = 'host_scoped';
    public const SCOPE_USER        = 'user_scoped';
    public const SCOPE_IP          = 'ip_scoped';

    // Approval states
    public const STATE_PENDING  = 'pending';
    public const STATE_APPROVED = 'approved';
    public const STATE_REJECTED = 'rejected';
    public const STATE_EXPIRED  = 'expired';
    public const STATE_REVOKED  = 'revoked';

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public static function generateSuppressionId(): string
    {
        return 'dsp-' . substr(str_replace('-', '', (string) \Illuminate\Support\Str::uuid()), 0, 36);
    }
}
