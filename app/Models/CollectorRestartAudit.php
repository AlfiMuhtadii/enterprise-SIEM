<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollectorRestartAudit extends Model
{
    protected $table = 'collector_restart_audit';

    protected $fillable = [
        'audit_id', 'agent_id', 'host_id', 'restart_reason', 'restart_count_24h',
        'operator_initiated', 'crash_induced', 'prior_health_state',
    ];

    protected $casts = [
        'operator_initiated' => 'boolean',
        'crash_induced'      => 'boolean',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('CollectorRestartAudit is append-only and cannot be updated.');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
            $this->updated_at = now();
        }
        return parent::save($options);
    }
}
