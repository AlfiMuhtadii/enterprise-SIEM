<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditExportAccessLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'log_id', 'export_id', 'access_type', 'accessed_by',
        'ip_address', 'user_agent', 'trace_id', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public const ACCESS_TYPES = ['view', 'download', 'verify_checksum', 'expire'];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('audit_export_access_logs is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
