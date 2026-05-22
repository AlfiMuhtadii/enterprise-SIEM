<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ProductionGovernanceAudit extends Model
{
    protected $table = 'production_governance_audit';

    public const EVENT_TYPES = ['window_created', 'trend_analyzed', 'report_generated', 'drift_detected', 'governance_review'];
    public const OUTCOMES    = ['success', 'advisory', 'degraded', 'failed'];

    protected $fillable = [
        'audit_id', 'tenant_id', 'event_type', 'actor',
        'outcome', 'description', 'is_advisory', 'payload',
    ];

    protected $casts = [
        'is_advisory' => 'boolean',
        'payload'     => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('ProductionGovernanceAudit is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
