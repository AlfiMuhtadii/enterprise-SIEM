<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EntityBehaviorBaseline extends Model
{
    // UEBA dimensions — deterministic behavioral metrics
    public const DIMENSIONS = [
        'login_frequency',
        'failed_login_ratio',
        'active_hours',
        'source_ip_diversity',
        'host_usage',
        'saas_action_frequency',
        'network_destination_frequency',
        'process_execution_frequency',
        'bytes_out_volume',
        'bytes_in_volume',
        'alert_frequency',
        'finding_frequency',
    ];

    // Entity types that participate in UEBA baseline analytics
    public const ENTITY_TYPES = ['user', 'host', 'ip', 'domain', 'process'];

    protected $table = 'entity_behavior_baselines';

    protected $fillable = [
        'entity_id',
        'entity_type',
        'entity_key',
        'dimension',
        'baseline_mean',
        'baseline_median',
        'baseline_stddev',
        'baseline_mad',
        'baseline_p10',
        'baseline_p90',
        'sample_count',
        'window_days',
        'peer_group_key',
        'advisory_only',
        'window_start',
        'window_end',
        'computed_at',
    ];

    protected $casts = [
        'baseline_mean'   => 'float',
        'baseline_median' => 'float',
        'baseline_stddev' => 'float',
        'baseline_mad'    => 'float',
        'baseline_p10'    => 'float',
        'baseline_p90'    => 'float',
        'sample_count'    => 'integer',
        'window_days'     => 'integer',
        'advisory_only'   => 'boolean',
        'window_start'    => 'datetime',
        'window_end'      => 'datetime',
        'computed_at'     => 'datetime',
    ];

    public function peerGroup(): ?PeerGroupProfile
    {
        if (!$this->peer_group_key) {
            return null;
        }
        return PeerGroupProfile::where('peer_group_key', $this->peer_group_key)->first();
    }
}
