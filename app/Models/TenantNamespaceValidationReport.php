<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class TenantNamespaceValidationReport extends Model
{
    protected $fillable = [
        'report_id', 'tenant_id', 'namespaces_checked',
        'namespaces_valid', 'namespaces_invalid',
        'crossover_detected', 'invalid_namespaces', 'is_advisory',
    ];

    protected $casts = [
        'namespaces_checked'  => 'array',
        'invalid_namespaces'  => 'array',
        'crossover_detected'  => 'boolean',
        'is_advisory'         => 'boolean',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('TenantNamespaceValidationReport is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
