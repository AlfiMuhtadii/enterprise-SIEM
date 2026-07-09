<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only anomaly scores — explainable, evidence-linked, advisory-only.
 * Never updated after insert. Replay-safe by design.
 */
class BaselineAnomalyScore extends Model
{
    public $timestamps = false;   // only created_at, no updated_at

    // Anomaly types — each maps to one behavioral baseline dimension
    public const ANOMALY_TYPES = [
        'unusual_login_time',
        'unusual_source_ip_diversity',
        'abnormal_failed_login_ratio',
        'unusual_saas_action_frequency',
        'unusual_process_execution_frequency',
        'abnormal_network_destination_frequency',
        'abnormal_bytes_out',
        'unusual_host_usage',
        'peer_group_behavior_deviation',
    ];

    // Scoring methods — all deterministic, reproducible
    public const SCORING_METHODS = [
        'robust_z_score',
        'percentile_rank',
        'frequency_rarity',
        'peer_group_deviation',
        'rolling_average_deviation',
    ];

    // Confidence thresholds
    public const CONFIDENCE_HIGH   = 0.80;
    public const CONFIDENCE_MEDIUM = 0.60;
    public const CONFIDENCE_LOW    = 0.40;

    protected $table = 'baseline_anomaly_scores';

    protected $fillable = [
        'score_id',
        'entity_key',
        'entity_type',
        'tenant_id',
        'anomaly_type',
        'dimension',
        'observed_value',
        'baseline_value',
        'deviation',
        'z_score',
        'percentile_rank',
        'scoring_method',
        'confidence',
        'evidence_references',
        'trace_ids',
        'peer_group_key',
        'peer_group_deviation',
        'is_advisory',
        'acted_on',
        'scored_at',
        'created_at',
    ];

    protected $casts = [
        'observed_value'     => 'float',
        'baseline_value'     => 'float',
        'deviation'          => 'float',
        'z_score'            => 'float',
        'percentile_rank'    => 'float',
        'confidence'         => 'float',
        'evidence_references'=> 'array',
        'trace_ids'          => 'array',
        'peer_group_deviation'=> 'float',
        'is_advisory'        => 'boolean',
        'acted_on'           => 'boolean',
        'scored_at'          => 'datetime',
        'created_at'         => 'datetime',
    ];
}
