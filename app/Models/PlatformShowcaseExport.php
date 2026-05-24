<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformShowcaseExport extends Model
{
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'export_id', 'export_type', 'export_format', 'artifact_hash',
        'is_deterministic', 'is_advisory', 'is_fabricated', 'export_content',
    ];

    protected $casts = [
        'is_deterministic' => 'boolean',
        'is_advisory'      => 'boolean',
        'is_fabricated'    => 'boolean',
        'export_content'   => 'array',
    ];

    const EXPORT_TYPES = [
        'capability_matrix',
        'architecture_summary',
        'demo_readiness_report',
        'detection_quality_summary',
        'telemetry_quality_summary',
        'analyst_triage_summary',
        'xdr_maturity_scorecard',
        'platform_showcase',
    ];

    const EXPORT_FORMATS = ['json', 'markdown'];
}
