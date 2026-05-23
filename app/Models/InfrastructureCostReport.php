<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InfrastructureCostReport extends Model
{
    public const COST_WINDOWS = ['daily', 'weekly', 'monthly'];

    protected $fillable = [
        'cost_report_id',
        'cluster_id',
        'cost_window',
        'storage_cost_estimate',
        'ingestion_cost_estimate',
        'replay_amplification_cost',
        'ha_overhead_cost',
        'total_cost_estimate',
        'utilization_efficiency_pct',
        'is_advisory',
        'replay_safe',
        'cost_evidence',
    ];

    protected $casts = [
        'storage_cost_estimate'      => 'float',
        'ingestion_cost_estimate'    => 'float',
        'replay_amplification_cost'  => 'float',
        'ha_overhead_cost'           => 'float',
        'total_cost_estimate'        => 'float',
        'utilization_efficiency_pct' => 'float',
        'is_advisory'                => 'boolean',
        'replay_safe'                => 'boolean',
        'cost_evidence'              => 'array',
    ];
}
