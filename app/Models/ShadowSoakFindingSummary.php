<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShadowSoakFindingSummary extends Model
{
    protected $table = 'shadow_soak_finding_summaries';

    protected $fillable = [
        'summary_id', 'run_id', 'domain', 'rule_id',
        'finding_count', 'high_confidence_count', 'avg_confidence',
        'max_severity', 'has_suppressed', 'tenant_id',
    ];

    protected $casts = [
        'finding_count'          => 'integer',
        'high_confidence_count'  => 'integer',
        'avg_confidence'         => 'float',
        'has_suppressed'         => 'boolean',
    ];
}
