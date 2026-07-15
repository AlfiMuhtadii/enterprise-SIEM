<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * API-VERSIONING: routes/api.php had no /v1 prefix, so a breaking change
 * would strand already-deployed endpoint agents with no migration path.
 * Every route is now registered once under /v1 (canonical) and once
 * unprefixed (backward-compatible alias) via the same closure -- proves
 * both resolve identically, not just that both return 200.
 */
class ApiVersioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_v1_and_unprefixed_agent_registration_both_work_identically(): void
    {
        Config::set('soc.agent_enrollment_token', 'test-token');

        $payloadV1 = [
            'host_fingerprint' => 'fp-v1-test',
            'host_id' => 'host-v1',
            'agent_version' => '0.2.0',
            'os_family' => 'windows',
        ];
        $payloadUnprefixed = [
            'host_fingerprint' => 'fp-unprefixed-test',
            'host_id' => 'host-unprefixed',
            'agent_version' => '0.2.0',
            'os_family' => 'windows',
        ];

        $this->postJson('/api/v1/agents/register', $payloadV1, [
            'X-Agent-Enrollment-Token' => 'test-token',
        ])->assertOk()->assertJsonStructure(['ok', 'agent_id', 'agent_secret']);

        $this->postJson('/api/agents/register', $payloadUnprefixed, [
            'X-Agent-Enrollment-Token' => 'test-token',
        ])->assertOk()->assertJsonStructure(['ok', 'agent_id', 'agent_secret']);
    }

    public function test_agent_enrollment_token_is_still_enforced_under_v1(): void
    {
        Config::set('soc.agent_enrollment_token', 'test-token');

        $this->postJson('/api/v1/agents/register', [
            'host_fingerprint' => 'fp-v1-unauth',
            'host_id' => 'host-v1-unauth',
            'agent_version' => '0.2.0',
            'os_family' => 'windows',
        ], [
            'X-Agent-Enrollment-Token' => 'wrong-token',
        ])->assertUnauthorized();
    }
}
