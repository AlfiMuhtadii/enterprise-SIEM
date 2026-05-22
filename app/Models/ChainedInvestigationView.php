<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChainedInvestigationView extends Model
{
    public const STATUSES   = ['active', 'archived', 'replaying'];
    public const MAX_DEPTH  = 10;

    protected $fillable = [
        'view_id', 'tenant_id', 'investigation_id', 'status', 'depth',
        'node_count', 'edge_count', 'bounded_traversal', 'is_advisory', 'view_state',
    ];

    protected $casts = [
        'bounded_traversal' => 'boolean',
        'is_advisory'       => 'boolean',
        'view_state'        => 'array',
    ];
}
