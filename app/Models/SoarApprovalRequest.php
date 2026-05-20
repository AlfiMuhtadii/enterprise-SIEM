<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SoarApprovalRequest extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'approval_id', 'plan_id', 'playbook_id', 'approval_type', 'decision',
        'requested_by', 'decided_by', 'rationale', 'gate_snapshot',
        'decided_at', 'created_at',
    ];

    protected $casts = [
        'gate_snapshot' => 'array',
        'decided_at'    => 'datetime',
        'created_at'    => 'datetime',
    ];

    public const DECISIONS    = ['pending', 'approved', 'rejected'];
    public const TYPES        = ['initial', 'secondary', 'escalation'];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('soar_approval_requests is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
