<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InfrastructureCostReport extends Model
{
    public const COST_WINDOWS = ['daily', 'weekly', 'monthly'];

    // SIM-LAYER-REALITY-GATE: model-level defaults so freshly created
    // instances carry the simulated/computed label immediately in-memory
    // (Eloquent does not re-fetch DB column defaults after INSERT).
    protected $attributes = [
        'is_simulated' => true,
        'evidence_basis' => 'computed',
    ];

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
        'is_simulated',
        'evidence_basis',
    ];

    protected $casts = [
        'is_simulated' => 'boolean',
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
