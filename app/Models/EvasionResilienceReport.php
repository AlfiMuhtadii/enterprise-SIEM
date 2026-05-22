<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvasionResilienceReport extends Model
{
    protected $fillable = [
        'report_id', 'evasion_type', 'target_rule_id', 'detection_survived',
        'confidence_degradation', 'resilience_score', 'degradation_factors', 'tested_by',
    ];

    protected $casts = [
        'detection_survived'     => 'boolean',
        'confidence_degradation' => 'float',
        'resilience_score'       => 'float',
        'degradation_factors'    => 'array',
    ];

    public const EVASION_TYPES = [
        'telemetry_gap', 'delayed_telemetry', 'partial_process_chain',
        'encoded_command', 'obfuscated_script', 'intermittent_replay',
        'missing_parent_process',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('EvasionResilienceReport is append-only and cannot be updated.');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
            $this->updated_at = now();
        }
        return parent::save($options);
    }
}
