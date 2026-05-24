<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReleaseBlockerReport extends Model
{
    protected $fillable = [
        'blocker_report_id', 'blocker_category', 'blocker_severity',
        'blocker_description', 'blocker_state', 'release_blocked',
        'autonomous_resolution', 'is_advisory', 'rc_version', 'blocker_evidence',
    ];

    protected $casts = [
        'release_blocked'      => 'boolean',
        'autonomous_resolution'=> 'boolean',
        'is_advisory'          => 'boolean',
        'blocker_evidence'     => 'array',
    ];

    public const BLOCKER_CATEGORIES = [
        'validation_failure', 'soak_incomplete', 'gate_failed',
        'freeze_violated', 'artifact_corrupt', 'reproducibility_failure',
    ];

    public const BLOCKER_SEVERITIES = ['low', 'medium', 'high', 'critical'];
    public const BLOCKER_STATES     = ['open', 'mitigated', 'resolved', 'waived'];
}
