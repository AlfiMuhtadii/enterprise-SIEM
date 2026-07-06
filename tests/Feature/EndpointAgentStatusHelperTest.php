<?php

namespace Tests\Feature;

use App\Services\EndpointAgentService;
use Tests\TestCase;

/**
 * Refinement: shared online/offline computation extracted from the SOC dashboard,
 * API, agent and endpoint controllers (previously duplicated 5+ times).
 *
 * TEST-UNTRAITED: audited — intentionally DB-free (pure static-method
 * assertions on EndpointAgentService::computeStatus()). No DB trait needed.
 */
class EndpointAgentStatusHelperTest extends TestCase
{
    public function test_recent_heartbeat_is_online(): void
    {
        $this->assertSame('online', EndpointAgentService::computeStatus(now(), 180));
    }

    public function test_old_heartbeat_is_offline(): void
    {
        $this->assertSame('offline', EndpointAgentService::computeStatus(now()->subHour(), 180));
    }

    public function test_null_last_seen_is_offline(): void
    {
        $this->assertSame('offline', EndpointAgentService::computeStatus(null, 180));
    }

    public function test_threshold_boundary_is_inclusive(): void
    {
        // exactly at the threshold counts as online (<=)
        $this->assertSame('online', EndpointAgentService::computeStatus(now()->subSeconds(180), 180));
        $this->assertSame('offline', EndpointAgentService::computeStatus(now()->subSeconds(181), 180));
    }

    public function test_falls_back_to_configured_threshold_when_omitted(): void
    {
        config(['soc.agent_offline_after_seconds' => 60]);
        $this->assertSame('online', EndpointAgentService::computeStatus(now()->subSeconds(30)));
        $this->assertSame('offline', EndpointAgentService::computeStatus(now()->subSeconds(120)));
    }

    public function test_accepts_string_timestamp(): void
    {
        $this->assertSame('online', EndpointAgentService::computeStatus(now()->format('Y-m-d H:i:sP'), 180));
    }
}
