<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReleaseCandidateManifest extends Model
{
    protected $fillable = [
        'manifest_id', 'rc_version', 'rc_state', 'manifest_hash',
        'subsystem_count', 'rule_count', 'hunt_domain_count', 'readiness_score',
        'feature_freeze_enforced', 'soak_validated', 'replay_safe',
        'autonomous_release', 'is_advisory', 'manifest_evidence',
    ];

    protected $casts = [
        'subsystem_count'         => 'integer',
        'rule_count'              => 'integer',
        'hunt_domain_count'       => 'integer',
        'readiness_score'         => 'float',
        'feature_freeze_enforced' => 'boolean',
        'soak_validated'          => 'boolean',
        'replay_safe'             => 'boolean',
        'autonomous_release'      => 'boolean',
        'is_advisory'             => 'boolean',
        'manifest_evidence'       => 'array',
    ];

    public const RC_STATES = ['draft', 'frozen', 'validated', 'released', 'superseded'];
}
