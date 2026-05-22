<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DetectionConfidenceReport extends Model
{
    protected $fillable = [
        'report_id', 'rule_id', 'confidence_score', 'true_positive_count',
        'false_positive_count', 'replay_sample_size', 'fp_rate',
        'assessment_method', 'contributing_factors', 'evaluated_by',
    ];

    protected $casts = [
        'confidence_score'     => 'float',
        'fp_rate'              => 'float',
        'contributing_factors' => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('DetectionConfidenceReport is append-only and cannot be updated.');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
            $this->updated_at = now();
        }
        return parent::save($options);
    }
}
