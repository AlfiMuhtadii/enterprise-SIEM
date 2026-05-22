<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ShiftHandoffValidation extends Model
{
    protected $fillable = [
        'handoff_id', 'outgoing_analyst_id', 'incoming_analyst_id', 'tenant_id',
        'shift_id', 'open_investigations_handed_off', 'pending_escalations_handed_off',
        'context_documented', 'replay_validated', 'continuity_preserved',
        'is_advisory', 'handoff_summary',
    ];

    protected $casts = [
        'context_documented'   => 'boolean',
        'replay_validated'     => 'boolean',
        'continuity_preserved' => 'boolean',
        'is_advisory'          => 'boolean',
        'handoff_summary'      => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('ShiftHandoffValidation is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
