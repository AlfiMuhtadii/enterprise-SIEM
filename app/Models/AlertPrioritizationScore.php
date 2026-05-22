<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AlertPrioritizationScore extends Model
{
    public const PRIORITY_TIERS = ['critical', 'high', 'medium', 'low'];

    protected $fillable = [
        'score_id', 'alert_id', 'tenant_id', 'rule_id',
        'base_severity_score', 'replay_confidence_factor', 'recurrence_factor',
        'escalation_frequency_factor', 'final_priority_score', 'priority_tier',
        'replay_validated', 'is_advisory', 'scoring_factors',
    ];

    protected $casts = [
        'base_severity_score'          => 'float',
        'replay_confidence_factor'     => 'float',
        'recurrence_factor'            => 'float',
        'escalation_frequency_factor'  => 'float',
        'final_priority_score'         => 'float',
        'replay_validated'             => 'boolean',
        'is_advisory'                  => 'boolean',
        'scoring_factors'              => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('AlertPrioritizationScore is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
