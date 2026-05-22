<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OfflineRecoveryRun extends Model
{
    protected $fillable = [
        'run_id', 'agent_id', 'host_id', 'offline_duration_seconds',
        'buffered_event_count', 'replayed_event_count', 'dropped_event_count',
        'replay_complete', 'sequence_continuity_ok', 'recovery_verdict',
    ];

    protected $casts = [
        'replay_complete'        => 'boolean',
        'sequence_continuity_ok' => 'boolean',
    ];

    public const VERDICT_COMPLETE = 'complete';
    public const VERDICT_PARTIAL  = 'partial';
    public const VERDICT_FAILED   = 'failed';
    public const VERDICT_PENDING  = 'pending';

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('OfflineRecoveryRun is append-only and cannot be updated.');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
            $this->updated_at = now();
        }
        return parent::save($options);
    }
}
