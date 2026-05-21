<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReleaseAuditEvent extends Model
{
    protected $fillable = [
        'event_id', 'release_id', 'event_type', 'actor', 'event_payload', 'correlation_id',
    ];

    protected $casts = [
        'event_payload' => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('ReleaseAuditEvent is append-only and cannot be updated.');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
            $this->updated_at = now();
        }
        return parent::save($options);
    }
}
