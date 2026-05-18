<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class EndpointBehavioralFinding extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_EXECUTION_CHAIN         = 'suspicious_execution_chain';
    public const TYPE_BEACON_PATTERN          = 'beacon_pattern';
    public const TYPE_LOLBIN_USAGE            = 'lolbin_usage';
    public const TYPE_PERSISTENCE_CORRELATION = 'persistence_correlation';
    public const TYPE_RARE_PARENT_CHILD       = 'rare_parent_child';

    protected $fillable = [
        'finding_id', 'agent_id', 'snapshot_id', 'finding_type',
        'severity', 'confidence', 'title', 'description',
        'evidence', 'trace_id', 'detected_at',
    ];

    protected $casts = [
        'evidence'    => 'array',
        'confidence'  => 'float',
        'detected_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(EndpointAgent::class, 'agent_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(EndpointProcessSnapshot::class, 'snapshot_id');
    }

    public static function generateFindingId(): string
    {
        $year = now()->year;
        $last = DB::table('endpoint_behavioral_findings')
            ->where('finding_id', 'like', "FIND-{$year}-%")
            ->orderByDesc('finding_id')
            ->value('finding_id');
        $seq = $last ? (int) substr($last, -5) + 1 : 1;
        return sprintf('FIND-%d-%05d', $year, $seq);
    }
}
