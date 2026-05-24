<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class XdrReadinessCertification extends Model
{
    protected $fillable = [
        'certification_id', 'certification_type', 'certification_state',
        'readiness_score', 'gate_pass_rate', 'all_gates_passed',
        'soak_validated', 'replay_verified', 'advisory_only',
        'autonomous_approval', 'certification_evidence',
    ];

    protected $casts = [
        'readiness_score'      => 'float',
        'gate_pass_rate'       => 'float',
        'all_gates_passed'     => 'boolean',
        'soak_validated'       => 'boolean',
        'replay_verified'      => 'boolean',
        'advisory_only'        => 'boolean',
        'autonomous_approval'  => 'boolean',
        'certification_evidence' => 'array',
    ];

    public const CERTIFICATION_TYPES = ['initial', 'renewal', 'post_incident', 'domain_specific'];
    public const CERTIFICATION_STATES = ['pending', 'in_progress', 'passed', 'failed', 'expired'];
}
