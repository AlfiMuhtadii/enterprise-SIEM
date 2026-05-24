<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerformanceHotspotReport extends Model
{
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'report_id', 'slow_chain_count', 'expensive_graph_count',
        'replay_amplification_count', 'high_cardinality_count', 'noisy_tenant_count',
        'alert_spike_count', 'expensive_hunt_count', 'hotspot_severity_score',
        'advisory_only', 'no_destructive_load_test', 'hotspot_details',
    ];

    protected $casts = [
        'advisory_only'           => 'boolean',
        'no_destructive_load_test' => 'boolean',
        'hotspot_details'         => 'array',
    ];
}
