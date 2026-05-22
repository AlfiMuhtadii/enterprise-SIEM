<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class TenantIsolationAudit extends Model
{
    protected $fillable = [
        'audit_id', 'tenant_id', 'scope', 'verdict', 'isolation_ok',
        'checks_total', 'checks_passed', 'checks_failed', 'findings',
        'operator_id', 'is_advisory',
    ];

    protected $casts = [
        'isolation_ok' => 'boolean',
        'is_advisory'  => 'boolean',
        'findings'     => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('TenantIsolationAudit is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
