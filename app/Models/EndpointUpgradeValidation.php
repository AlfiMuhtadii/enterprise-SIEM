<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EndpointUpgradeValidation extends Model
{
    protected $fillable = [
        'validation_id', 'agent_id', 'host_id', 'from_version', 'to_version',
        'package_verified', 'rollback_available', 'telemetry_resumed', 'verdict', 'validated_by',
    ];

    protected $casts = [
        'package_verified'   => 'boolean',
        'rollback_available' => 'boolean',
        'telemetry_resumed'  => 'boolean',
    ];

    public const VERDICT_PASS    = 'pass';
    public const VERDICT_FAIL    = 'fail';
    public const VERDICT_PENDING = 'pending';

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('EndpointUpgradeValidation is append-only and cannot be updated.');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
            $this->updated_at = now();
        }
        return parent::save($options);
    }
}
