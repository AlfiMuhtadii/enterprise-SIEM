<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AlertRecurrenceReport extends Model
{
    protected $fillable = [
        'report_id', 'rule_id', 'tenant_id', 'recurrence_count',
        'window_hours', 'recurrence_rate', 'suppression_candidate',
        'replay_consistent', 'is_advisory', 'recurrence_evidence',
    ];

    protected $casts = [
        'recurrence_rate'      => 'float',
        'suppression_candidate'=> 'boolean',
        'replay_consistent'    => 'boolean',
        'is_advisory'          => 'boolean',
        'recurrence_evidence'  => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('AlertRecurrenceReport is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
