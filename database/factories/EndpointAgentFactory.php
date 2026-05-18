<?php

namespace Database\Factories;

use App\Models\EndpointAgent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class EndpointAgentFactory extends Factory
{
    protected $model = EndpointAgent::class;

    public function definition(): array
    {
        $hostId = 'host-' . $this->faker->uuid();
        return [
            'agent_id'              => 'agent-' . (string) Str::uuid(),
            'host_id'               => $hostId,
            'host_fingerprint'      => hash('sha256', $hostId),
            'hostname'              => $this->faker->domainWord() . '-server',
            'enrollment_token_hash' => hash('sha256', 'test-token'),
            'agent_version'         => '1.0.0',
            'ip_address'            => $this->faker->localIpv4(),
            'platform'              => 'Linux',
            'os_family'             => 'Linux',
            'health_state'          => EndpointAgent::HEALTH_ONLINE,
            'status'                => 'online',
            'enrolled_at'           => now(),
            'last_seen_at'          => now(),
        ];
    }
}
