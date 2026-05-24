<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReleaseCandidateAudit extends Model
{
    protected $table = 'release_candidate_audit';

    protected $fillable = [
        'rc_audit_id', 'audit_event_type', 'actor_type', 'rc_version',
        'autonomous_action_taken', 'destructive_action_taken',
        'replay_safe', 'audit_evidence',
    ];

    protected $casts = [
        'autonomous_action_taken'  => 'boolean',
        'destructive_action_taken' => 'boolean',
        'replay_safe'              => 'boolean',
        'audit_evidence'           => 'array',
    ];

    public const AUDIT_EVENT_TYPES = [
        'rc_initiated', 'freeze_enforced', 'artifact_generated',
        'blocker_raised', 'validation_passed', 'rc_released', 'rc_superseded',
    ];

    public const ACTOR_TYPES = ['operator', 'analyst', 'system'];
}
