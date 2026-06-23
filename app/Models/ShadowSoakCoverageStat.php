<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShadowSoakCoverageStat extends Model
{
    protected $table = 'shadow_soak_coverage_stats';

    protected $fillable = [
        'stat_id', 'run_id', 'domain',
        'total_shadow_rules', 'rules_with_findings', 'coverage_rate',
        'rules_without_findings', 'tenant_id',
    ];

    protected $casts = [
        'total_shadow_rules'     => 'integer',
        'rules_with_findings'    => 'integer',
        'coverage_rate'          => 'float',
        'rules_without_findings' => 'array',
    ];
}
