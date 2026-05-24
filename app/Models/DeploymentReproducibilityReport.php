<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeploymentReproducibilityReport extends Model
{
    protected $fillable = [
        'reproducibility_report_id', 'deployment_scope', 'reproducibility_score',
        'environment_matched', 'artifact_hash_verified', 'configuration_deterministic',
        'replay_continuity_verified', 'is_advisory', 'autonomous_deployment',
        'rc_version', 'reproducibility_evidence',
    ];

    protected $casts = [
        'reproducibility_score'       => 'float',
        'environment_matched'         => 'boolean',
        'artifact_hash_verified'      => 'boolean',
        'configuration_deterministic' => 'boolean',
        'replay_continuity_verified'  => 'boolean',
        'is_advisory'                 => 'boolean',
        'autonomous_deployment'       => 'boolean',
        'reproducibility_evidence'    => 'array',
    ];

    public const DEPLOYMENT_SCOPES = ['full_stack', 'partial', 'canary', 'single_service'];
}
