<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvestigationErgonomicView extends Model
{
    public const STATUSES    = ['active', 'bookmarked', 'archived', 'replaying'];
    public const MAX_DEPTH   = 10;

    protected $fillable = [
        'view_id', 'investigation_id', 'analyst_id', 'tenant_id', 'status',
        'evidence_count', 'bookmark_count', 'timeline_compressed',
        'chain_summarized', 'bounded_traversal', 'is_advisory', 'view_state',
    ];

    protected $casts = [
        'timeline_compressed' => 'boolean',
        'chain_summarized'    => 'boolean',
        'bounded_traversal'   => 'boolean',
        'is_advisory'         => 'boolean',
        'view_state'          => 'array',
    ];
}
