<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class OnboardingApprovalRequest extends Model
{
    public const STATUSES = ['pending', 'approved', 'rejected'];

    protected $fillable = [
        'request_id', 'run_id', 'tenant_id', 'requested_by',
        'status', 'reviewed_by', 'rejection_reason', 'self_approve_blocked', 'is_advisory',
    ];

    protected $casts = [
        'self_approve_blocked' => 'boolean',
        'is_advisory'          => 'boolean',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('OnboardingApprovalRequest is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
