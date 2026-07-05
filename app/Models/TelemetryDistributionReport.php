<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelemetryDistributionReport extends Model
{
    public const DISTRIBUTION_STATES = ['balanced', 'imbalanced', 'overloaded', 'recovering'];

    // SIM-LAYER-REALITY-GATE: model-level defaults so freshly created
    // instances carry the simulated/computed label immediately in-memory
    // (Eloquent does not re-fetch DB column defaults after INSERT).
    protected $attributes = [
        'is_simulated' => true,
        'evidence_basis' => 'computed',
    ];

    protected $fillable = [
        'distribution_report_id',
        'cluster_id',
        'distribution_state',
        'ingestion_rate_eps',
        'queue_pressure_pct',
        'cross_cluster_lag_ms',
        'replay_amplification_ratio',
        'load_balanced',
        'bounded_redistribution',
        'replay_safe',
        'is_advisory',
        'distribution_evidence',
        'is_simulated',
        'evidence_basis',
    ];

    protected $casts = [
        'is_simulated' => 'boolean',
        'ingestion_rate_eps'         => 'float',
        'queue_pressure_pct'         => 'float',
        'cross_cluster_lag_ms'       => 'float',
        'replay_amplification_ratio' => 'float',
        'load_balanced'              => 'boolean',
        'bounded_redistribution'     => 'boolean',
        'replay_safe'                => 'boolean',
        'is_advisory'                => 'boolean',
        'distribution_evidence'      => 'array',
    ];
}
