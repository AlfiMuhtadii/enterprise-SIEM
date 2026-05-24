<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelemetryQualityScorecard extends Model
{
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'scorecard_id', 'tenant_id', 'trace_id_completeness', 'event_id_completeness',
        'timestamp_consistency', 'host_agent_presence', 'process_lineage_completeness',
        'malformed_event_ratio', 'orphaned_event_ratio', 'enrichment_coverage',
        'tenant_id_consistency', 'overall_quality_score', 'events_sampled',
        'is_advisory', 'quality_breakdown',
    ];

    protected $casts = [
        'is_advisory'       => 'boolean',
        'quality_breakdown' => 'array',
    ];
}
