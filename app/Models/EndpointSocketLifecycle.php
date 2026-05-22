<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class EndpointSocketLifecycle extends Model
{
    protected $table = 'endpoint_socket_lifecycle';

    public const PROTOCOLS = ['tcp', 'udp'];
    public const STATES    = ['listen', 'established', 'closed', 'time_wait'];

    protected $fillable = [
        'socket_id', 'endpoint_id', 'tenant_id', 'process_name',
        'local_address', 'local_port', 'remote_address', 'remote_port',
        'protocol', 'state', 'connection_duration_seconds', 'reconnect_count',
        'suspicious_port', 'is_advisory', 'socket_context',
    ];

    protected $casts = [
        'connection_duration_seconds' => 'float',
        'suspicious_port'             => 'boolean',
        'is_advisory'                 => 'boolean',
        'socket_context'              => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('EndpointSocketLifecycle is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
