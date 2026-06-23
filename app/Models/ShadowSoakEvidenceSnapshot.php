<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShadowSoakEvidenceSnapshot extends Model
{
    protected $table = 'shadow_soak_evidence_snapshots';

    protected $fillable = [
        'snapshot_id', 'run_id', 'domain',
        'total_findings', 'high_confidence_findings', 'high_confidence_rate',
        'suppressed_findings', 'suppression_rate',
        'rules_covered', 'rule_coverage_rate',
        'severity_distribution', 'tenant_id',
    ];

    protected $casts = [
        'total_findings'          => 'integer',
        'high_confidence_findings'=> 'integer',
        'high_confidence_rate'    => 'float',
        'suppressed_findings'     => 'integer',
        'suppression_rate'        => 'float',
        'rules_covered'           => 'integer',
        'rule_coverage_rate'      => 'float',
        'severity_distribution'   => 'array',
    ];
}
