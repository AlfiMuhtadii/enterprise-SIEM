<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class FalsePositiveEvolutionReport extends Model
{
    public const FP_VERDICTS = ['improving', 'stable', 'worsening', 'critical'];

    protected $fillable = [
        'report_id', 'tenant_id', 'window_type', 'fp_rate_start', 'fp_rate_end',
        'fp_trend_slope', 'suppression_effectiveness_avg', 'replay_disagreement_rate',
        'confidence_drift_avg', 'recurring_benign_count', 'fp_verdict',
        'is_advisory', 'evolution_evidence',
    ];

    protected $casts = [
        'fp_rate_start'                => 'float',
        'fp_rate_end'                  => 'float',
        'fp_trend_slope'               => 'float',
        'suppression_effectiveness_avg'=> 'float',
        'replay_disagreement_rate'     => 'float',
        'confidence_drift_avg'         => 'float',
        'is_advisory'                  => 'boolean',
        'evolution_evidence'           => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('FalsePositiveEvolutionReport is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
