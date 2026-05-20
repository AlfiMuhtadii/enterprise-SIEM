<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PiiAccessAudit extends Model
{
    protected $table = 'pii_access_audit';

    public $timestamps = false;

    protected $fillable = [
        'audit_id', 'field_name', 'pii_category', 'accessed_by_user', 'accessed_by_role',
        'source_entity', 'access_context', 'was_masked', 'masking_policy_applied',
        'trace_id', 'created_at',
    ];

    protected $casts = [
        'was_masked'              => 'boolean',
        'masking_policy_applied'  => 'boolean',
        'created_at'              => 'datetime',
    ];

    public const PII_CATEGORIES = [
        'email', 'name', 'ip_address', 'credential', 'token', 'session_id', 'custom',
    ];

    public const ACCESS_CONTEXTS = [
        'export', 'api_view', 'investigation', 'trace_view', 'report',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('pii_access_audit is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
