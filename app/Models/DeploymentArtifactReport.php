<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeploymentArtifactReport extends Model
{
    protected $fillable = [
        'artifact_report_id', 'artifact_type', 'artifact_state', 'artifact_hash',
        'artifact_size_kb', 'integrity_verified', 'replay_safe',
        'autonomous_generation', 'is_advisory', 'rc_version', 'artifact_evidence',
    ];

    protected $casts = [
        'artifact_size_kb'      => 'integer',
        'integrity_verified'    => 'boolean',
        'replay_safe'           => 'boolean',
        'autonomous_generation' => 'boolean',
        'is_advisory'           => 'boolean',
        'artifact_evidence'     => 'array',
    ];

    public const ARTIFACT_TYPES  = ['docker_compose', 'env_template', 'validation_bundle', 'startup_checklist', 'replay_bundle'];
    public const ARTIFACT_STATES = ['generated', 'validated', 'signed', 'superseded'];
}
