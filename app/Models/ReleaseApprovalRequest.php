<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReleaseApprovalRequest extends Model
{
    protected $fillable = [
        'request_id', 'release_id', 'readiness_run_id', 'rollback_run_id',
        'readiness_summary', 'status', 'requested_by',
    ];

    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('ReleaseApprovalRequest is append-only and cannot be updated.');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
            $this->updated_at = now();
        }
        return parent::save($options);
    }
}
