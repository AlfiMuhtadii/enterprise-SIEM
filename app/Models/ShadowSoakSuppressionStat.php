<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShadowSoakSuppressionStat extends Model
{
    protected $table = 'shadow_soak_suppression_stats';

    protected $fillable = [
        'stat_id', 'run_id', 'domain',
        'total_findings', 'suppressed_count', 'suppression_rate',
        'within_threshold', 'tenant_id',
    ];

    protected $casts = [
        'total_findings'    => 'integer',
        'suppressed_count'  => 'integer',
        'suppression_rate'  => 'float',
        'within_threshold'  => 'boolean',
    ];
}
