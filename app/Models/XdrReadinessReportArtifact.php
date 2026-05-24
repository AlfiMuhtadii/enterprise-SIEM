<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class XdrReadinessReportArtifact extends Model
{
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'artifact_id', 'report_type', 'report_json', 'report_markdown',
        'artifact_hash', 'is_advisory', 'is_fabricated', 'evidence_linked',
        'evidence_refs',
    ];

    protected $casts = [
        'report_json'    => 'array',
        'is_advisory'    => 'boolean',
        'is_fabricated'  => 'boolean',
        'evidence_linked' => 'boolean',
        'evidence_refs'  => 'array',
    ];

    const REPORT_TYPES = [
        'detection_quality',
        'telemetry_quality',
        'analyst_triage_realism',
        'performance_hotspot',
        'noisy_environment',
        'attack_chain_reconstruction',
        'xdr_maturity_scorecard',
    ];
}
