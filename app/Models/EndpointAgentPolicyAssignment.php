<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only: history of fleet policy assignments to agents.
 * Never updated after insert.
 */
class EndpointAgentPolicyAssignment extends Model
{
    public $timestamps = false;

    public const REASON_MANUAL        = 'manual';
    public const REASON_BULK_ROLLOUT  = 'bulk_rollout';
    public const REASON_ROLLBACK      = 'rollback';
    public const REASON_RE_ENROLLMENT = 're_enrollment';

    public const ASSIGNMENT_REASONS = [
        self::REASON_MANUAL,
        self::REASON_BULK_ROLLOUT,
        self::REASON_ROLLBACK,
        self::REASON_RE_ENROLLMENT,
    ];

    protected $table = 'endpoint_agent_policy_assignments';

    protected $fillable = [
        'assignment_id', 'agent_id', 'policy_id', 'policy_version',
        'config_hash', 'assignment_reason', 'applied_to_agent',
        'trace_id', 'assigned_at', 'assigned_by', 'created_at',
    ];

    protected $casts = [
        'applied_to_agent' => 'boolean',
        'assigned_at'      => 'datetime',
        'created_at'       => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(EndpointAgent::class, 'agent_id');
    }
}
