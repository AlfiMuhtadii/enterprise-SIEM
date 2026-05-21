<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReplayAmplificationReport extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'report_id', 'consumer_group', 'topic', 'amplification_ratio',
        'original_event_count', 'replayed_event_count', 'storage_amplification_mb',
        'exceeds_safe_threshold', 'safe_threshold', 'triggered_by', 'created_at',
    ];

    protected $casts = [
        'amplification_ratio'      => 'float',
        'original_event_count'     => 'integer',
        'replayed_event_count'     => 'integer',
        'storage_amplification_mb' => 'float',
        'exceeds_safe_threshold'   => 'boolean',
        'safe_threshold'           => 'float',
        'created_at'               => 'datetime',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('replay_amplification_reports is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
