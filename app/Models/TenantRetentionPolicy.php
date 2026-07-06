<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantRetentionPolicy extends Model
{
    protected $fillable = [
        'tenant_id', 'events_days', 'alerts_days', 'incidents_days', 'updated_by',
    ];

    protected $casts = [
        'events_days' => 'integer',
        'alerts_days' => 'integer',
        'incidents_days' => 'integer',
    ];
}
