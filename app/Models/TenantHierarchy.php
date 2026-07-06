<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantHierarchy extends Model
{
    protected $table = 'tenant_hierarchy';

    protected $fillable = [
        'parent_tenant_id',
        'child_tenant_id',
        'is_active',
        'linked_by',
        'unlinked_by',
        'linked_at',
        'unlinked_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'linked_at' => 'datetime',
        'unlinked_at' => 'datetime',
    ];
}
