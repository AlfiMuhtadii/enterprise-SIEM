<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class OperationalFatigueIndicator extends Model
{
    public const SEVERITIES = ['none', 'low', 'medium', 'high'];

    protected $fillable = [
        'indicator_id', 'analyst_id', 'tenant_id', 'shift_id',
        'dismissal_acceleration_rate', 'avg_review_time_seconds',
        'baseline_review_time_seconds', 'consecutive_dismissals',
        'fatigue_detected', 'fatigue_severity', 'is_advisory', 'evidence',
    ];

    protected $casts = [
        'dismissal_acceleration_rate' => 'float',
        'avg_review_time_seconds'     => 'float',
        'baseline_review_time_seconds'=> 'float',
        'fatigue_detected'            => 'boolean',
        'is_advisory'                 => 'boolean',
        'evidence'                    => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('OperationalFatigueIndicator is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
