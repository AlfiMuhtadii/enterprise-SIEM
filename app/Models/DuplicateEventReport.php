<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DuplicateEventReport extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'report_id', 'event_fingerprint', 'source_topic', 'trace_id',
        'original_event_id', 'duplicate_event_id', 'detection_reason',
        'severity', 'suppressed', 'analytics_protected', 'created_at',
    ];

    protected $casts = [
        'suppressed'          => 'boolean',
        'analytics_protected' => 'boolean',
        'created_at'          => 'datetime',
    ];

    public const SEVERITY_INFORMATIONAL = 'informational';
    public const SEVERITY_WARNING       = 'warning';
    public const SEVERITY_CRITICAL      = 'critical';

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('duplicate_event_reports is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
