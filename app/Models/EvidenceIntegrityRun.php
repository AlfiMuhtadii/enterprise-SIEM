<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvidenceIntegrityRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'run_id', 'scope', 'scope_id', 'status', 'total_records_checked',
        'integrity_failures', 'linkage_failures', 'trace_continuity_ok',
        'integrity_hash', 'check_summary', 'triggered_by', 'trace_id',
        'completed_at', 'created_at',
    ];

    protected $casts = [
        'total_records_checked'=> 'integer',
        'integrity_failures'   => 'integer',
        'linkage_failures'     => 'integer',
        'trace_continuity_ok'  => 'boolean',
        'check_summary'        => 'array',
        'completed_at'         => 'datetime',
        'created_at'           => 'datetime',
    ];

    public const SCOPES = [
        'investigation', 'hunt_result', 'trace', 'detection_rule',
        'cross_domain', 'full_sweep',
    ];

    public const STATUS_RUNNING = 'running';
    public const STATUS_PASSED  = 'passed';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_WARNING = 'warning';

    public static function computeIntegrityHash(array $records): string
    {
        $canonical = array_map(fn($r) => is_array($r) ? $r : $r->toArray(), $records);
        ksort($canonical);
        return hash('sha256', json_encode($canonical));
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('evidence_integrity_runs is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
