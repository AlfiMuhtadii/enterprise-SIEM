<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AnalystAcknowledgmentAudit extends Model
{
    protected $table = 'analyst_acknowledgment_audit';

    public const ACTIONS = ['dismissed', 'confirmed', 'escalated', 'deferred', 're_queued'];

    protected $fillable = [
        'audit_id', 'analyst_id', 'tenant_id', 'alert_id', 'rule_id',
        'acknowledgment_action', 'latency_seconds', 'repeated_dismissal',
        'dismissal_count', 'replay_consistent', 'is_advisory', 'context',
    ];

    protected $casts = [
        'latency_seconds'    => 'float',
        'repeated_dismissal' => 'boolean',
        'replay_consistent'  => 'boolean',
        'is_advisory'        => 'boolean',
        'context'            => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('AnalystAcknowledgmentAudit is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
