<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class TenantGraphIsolationReport extends Model
{
    protected $fillable = [
        'report_id', 'tenant_id', 'graph_id', 'isolation_ok',
        'nodes_validated', 'edges_validated', 'cross_tenant_edges_detected',
        'shared_evidence_detected', 'traversal_depth', 'verdict', 'is_advisory',
    ];

    protected $casts = [
        'isolation_ok' => 'boolean',
        'is_advisory'  => 'boolean',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('TenantGraphIsolationReport is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
