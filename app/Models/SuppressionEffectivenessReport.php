<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SuppressionEffectivenessReport extends Model
{
    protected $fillable = [
        'report_id', 'rule_id', 'tenant_id', 'suppression_key',
        'suppressed_count', 'fp_prevented', 'tp_suppressed',
        'effectiveness_score', 'suppression_safe', 'is_advisory', 'evidence',
    ];

    protected $casts = [
        'effectiveness_score' => 'float',
        'suppression_safe'    => 'boolean',
        'is_advisory'         => 'boolean',
        'evidence'            => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('SuppressionEffectivenessReport is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
