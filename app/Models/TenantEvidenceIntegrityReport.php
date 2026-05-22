<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class TenantEvidenceIntegrityReport extends Model
{
    protected $fillable = [
        'report_id', 'tenant_id', 'evidence_refs_checked',
        'integrity_ok', 'integrity_failed', 'cross_tenant_refs',
        'verdict', 'failed_refs', 'is_advisory',
    ];

    protected $casts = [
        'is_advisory'  => 'boolean',
        'failed_refs'  => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('TenantEvidenceIntegrityReport is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
