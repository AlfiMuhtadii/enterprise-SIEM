<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class TenantExportValidationRun extends Model
{
    protected $fillable = [
        'validation_id', 'tenant_id', 'export_id', 'export_scope',
        'scope_ok', 'integrity_ok', 'checksum', 'approval_required',
        'approved', 'approved_by', 'expires_at', 'verdict', 'is_advisory',
    ];

    protected $casts = [
        'scope_ok'         => 'boolean',
        'integrity_ok'     => 'boolean',
        'approval_required'=> 'boolean',
        'approved'         => 'boolean',
        'is_advisory'      => 'boolean',
        'expires_at'       => 'datetime',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('TenantExportValidationRun is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
