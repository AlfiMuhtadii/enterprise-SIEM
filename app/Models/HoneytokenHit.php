<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HoneytokenHit extends Model
{
    protected $table = 'honeytoken_hits';

    protected $fillable = [
        'honeytoken_id',
        'alert_id',
        'tenant_id',
        'telemetry_event_id',
        'matched_field',
        'matched_value',
        'matched_at',
        'metadata',
    ];

    protected $casts = [
        'matched_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function honeytoken()
    {
        return $this->belongsTo(Honeytoken::class, 'honeytoken_id', 'honeytoken_id');
    }
}
