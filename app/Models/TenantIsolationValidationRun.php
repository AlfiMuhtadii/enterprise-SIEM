<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantIsolationValidationRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'run_id', 'tenant_scope', 'check_type', 'status', 'violations_found',
        'violation_details', 'cross_tenant_detected', 'triggered_by', 'trace_id', 'created_at',
    ];

    protected $casts = [
        'violations_found'     => 'integer',
        'violation_details'    => 'array',
        'cross_tenant_detected'=> 'boolean',
        'created_at'           => 'datetime',
    ];

    public const CHECK_TYPES = [
        'query_scope', 'evidence_bound', 'graph_traversal', 'replay_pack', 'export_scope',
    ];

    public const STATUSES = ['passed', 'failed', 'warning'];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('tenant_isolation_validation_runs is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
