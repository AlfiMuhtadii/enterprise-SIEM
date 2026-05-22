<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class TenantBoundaryViolationReport extends Model
{
    public const VIOLATION_TYPES = [
        'graph_crossover', 'replay_contamination', 'export_leakage',
        'context_override', 'namespace_crossover',
    ];

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    protected $fillable = [
        'report_id', 'tenant_id', 'violation_type', 'source_tenant_id',
        'target_tenant_id', 'description', 'severity', 'evidence_refs', 'is_advisory',
    ];

    protected $casts = [
        'evidence_refs' => 'array',
        'is_advisory'   => 'boolean',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('TenantBoundaryViolationReport is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
