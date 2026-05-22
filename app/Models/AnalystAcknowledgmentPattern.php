<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AnalystAcknowledgmentPattern extends Model
{
    public const ACK_TYPES = ['dismissed_fp', 'confirmed_tp', 'escalated', 'deferred'];

    protected $fillable = [
        'pattern_id', 'analyst_id', 'tenant_id', 'rule_id',
        'acknowledgment_type', 'response_latency_seconds',
        'replay_consistent', 'is_advisory', 'context',
    ];

    protected $casts = [
        'response_latency_seconds' => 'float',
        'replay_consistent'        => 'boolean',
        'is_advisory'              => 'boolean',
        'context'                  => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('AnalystAcknowledgmentPattern is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
