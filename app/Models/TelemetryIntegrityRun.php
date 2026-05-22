<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelemetryIntegrityRun extends Model
{
    protected $fillable = [
        'run_id', 'agent_id', 'host_id', 'checksum_valid', 'sequence_valid',
        'replay_safe', 'events_checked', 'corruption_count', 'verdict', 'integrity_details',
    ];

    protected $casts = [
        'checksum_valid'    => 'boolean',
        'sequence_valid'    => 'boolean',
        'replay_safe'       => 'boolean',
        'integrity_details' => 'array',
    ];

    public const VERDICT_PASS    = 'pass';
    public const VERDICT_FAIL    = 'fail';
    public const VERDICT_PARTIAL = 'partial';

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('TelemetryIntegrityRun is append-only and cannot be updated.');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
            $this->updated_at = now();
        }
        return parent::save($options);
    }
}
