<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CardinalityPressureReport extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'report_id', 'field_name', 'source_table', 'cardinality_count', 'growth_rate_pct',
        'pressure_type', 'severity', 'bounded', 'details', 'created_at',
    ];

    protected $casts = [
        'cardinality_count' => 'integer',
        'growth_rate_pct'   => 'float',
        'bounded'           => 'boolean',
        'details'           => 'array',
        'created_at'        => 'datetime',
    ];

    public const PRESSURE_TYPES = [
        'entity_explosion', 'label_explosion', 'graph_node_growth',
        'process_telemetry_growth', 'attack_mapping_expansion',
    ];

    public const SEVERITIES = ['informational', 'warning', 'critical'];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('cardinality_pressure_reports is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
