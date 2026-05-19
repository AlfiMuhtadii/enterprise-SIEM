<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EscalationEvent extends Model
{
    public const UPDATED_AT = null; // append-only

    // Event types
    public const EVENT_CREATED       = 'created';
    public const EVENT_ACKNOWLEDGED  = 'acknowledged';
    public const EVENT_ROUTED        = 'routed';
    public const EVENT_REASSIGNED    = 'reassigned';
    public const EVENT_RESOLVED      = 'resolved';
    public const EVENT_TIMED_OUT     = 'timed_out';
    public const EVENT_WORKFLOW_STATE= 'workflow_state';

    // Analyst workflow states
    public const STATE_QUEUED            = 'queued';
    public const STATE_ASSIGNED          = 'assigned';
    public const STATE_TRIAGED           = 'triaged';
    public const STATE_INVESTIGATING     = 'investigating';
    public const STATE_ESCALATED         = 'escalated';
    public const STATE_AWAITING_RESPONSE = 'awaiting_response';
    public const STATE_MONITORING        = 'monitoring';
    public const STATE_RESOLVED          = 'resolved';
    public const STATE_CLOSED            = 'closed';

    public const WORKFLOW_STATES = [
        self::STATE_QUEUED, self::STATE_ASSIGNED, self::STATE_TRIAGED,
        self::STATE_INVESTIGATING, self::STATE_ESCALATED, self::STATE_AWAITING_RESPONSE,
        self::STATE_MONITORING, self::STATE_RESOLVED, self::STATE_CLOSED,
    ];

    // Deterministic valid transitions
    public const STATE_TRANSITIONS = [
        self::STATE_QUEUED            => [self::STATE_ASSIGNED, self::STATE_TRIAGED],
        self::STATE_ASSIGNED          => [self::STATE_TRIAGED, self::STATE_QUEUED],
        self::STATE_TRIAGED           => [self::STATE_INVESTIGATING, self::STATE_ESCALATED, self::STATE_RESOLVED],
        self::STATE_INVESTIGATING     => [self::STATE_ESCALATED, self::STATE_AWAITING_RESPONSE, self::STATE_MONITORING, self::STATE_RESOLVED],
        self::STATE_ESCALATED         => [self::STATE_INVESTIGATING, self::STATE_AWAITING_RESPONSE, self::STATE_RESOLVED],
        self::STATE_AWAITING_RESPONSE => [self::STATE_INVESTIGATING, self::STATE_ESCALATED, self::STATE_MONITORING, self::STATE_RESOLVED],
        self::STATE_MONITORING        => [self::STATE_RESOLVED, self::STATE_INVESTIGATING],
        self::STATE_RESOLVED          => [self::STATE_CLOSED, self::STATE_INVESTIGATING],
        self::STATE_CLOSED            => [],
    ];

    public const ESCALATION_TIMEOUT_HOURS = [
        'critical' => 1,
        'high'     => 4,
        'medium'   => 24,
        'low'      => 72,
    ];

    protected $fillable = [
        'escalation_id', 'investigation_id', 'event_type', 'workflow_state',
        'from_analyst_id', 'to_analyst_id', 'severity', 'reason',
        'routing_rule', 'acknowledged_at', 'acknowledgement_note', 'trace_id',
    ];

    protected $casts = ['acknowledged_at' => 'datetime'];

    public static function generateEscalationId(): string
    {
        return 'esc-' . Str::uuid();
    }

    public function fromAnalyst(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_analyst_id');
    }

    public function toAnalyst(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_analyst_id');
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::STATE_TRANSITIONS[$from] ?? [], true);
    }
}
