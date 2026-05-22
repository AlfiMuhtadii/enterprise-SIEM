<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class FalsePositiveDriftReport extends Model
{
    public const DRIFT_DIRECTIONS = ['increasing', 'decreasing', 'stable'];

    protected $fillable = [
        'report_id', 'rule_id', 'tenant_id', 'fp_rate_current', 'fp_rate_baseline',
        'drift_magnitude', 'drift_direction', 'probable_cause',
        'suppression_recommended', 'is_advisory', 'evidence',
    ];

    protected $casts = [
        'fp_rate_current'        => 'float',
        'fp_rate_baseline'       => 'float',
        'drift_magnitude'        => 'float',
        'suppression_recommended'=> 'boolean',
        'is_advisory'            => 'boolean',
        'evidence'               => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('FalsePositiveDriftReport is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
