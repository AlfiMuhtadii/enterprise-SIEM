<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'delivery_id', 'notification_event_id', 'status', 'attempt_number',
        'http_status_code', 'response_body', 'error_detail', 'simulated',
        'trace_id', 'attempted_at',
    ];

    protected $casts = [
        'attempt_number'   => 'integer',
        'http_status_code' => 'integer',
        'simulated'        => 'boolean',
        'attempted_at'     => 'datetime',
    ];

    public const STATUS_PENDING   = 'pending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_SKIPPED   = 'skipped';

    public function notificationEvent(): BelongsTo
    {
        return $this->belongsTo(NotificationEvent::class, 'notification_event_id');
    }
}
