<?php

namespace Tests\Feature;

use App\Models\EndpointAgent;
use App\Services\EndpointResponseCommandService;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PerAgentHmacSecretTest extends TestCase
{
    use RefreshDatabase;

    private EndpointResponseCommandService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(EndpointResponseCommandService::class);
    }

    /** CMD-SHARED-HMAC: endpoint_agents table has hmac_secret column */
    public function test_endpoint_agents_has_hmac_secret_column(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('endpoint_agents', 'hmac_secret')
        );
    }

    /** hmac_secret is nullable by default */
    public function test_hmac_secret_is_nullable(): void
    {
        $agent = EndpointAgent::factory()->create(['hmac_secret' => null]);
        $this->assertNull($agent->fresh()->hmac_secret);
    }

    /** Per-agent hmac_secret is stored and retrievable */
    public function test_per_agent_secret_is_stored(): void
    {
        $agent = EndpointAgent::factory()->create(['hmac_secret' => 'per-agent-secret-abc']);
        $this->assertSame('per-agent-secret-abc', $agent->fresh()->hmac_secret);
    }

    /** isSignatureValid uses per-agent secret when available */
    public function test_signature_valid_with_per_agent_secret(): void
    {
        $secret = 'per-agent-secret-xyz';
        $agent  = EndpointAgent::factory()->create(['hmac_secret' => $secret]);
        $payload = 'test-payload';
        $sig     = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        $this->assertTrue(
            $this->svc->isSignatureValid($sig, $payload, $agent->agent_id)
        );
    }

    /** Signature using shared token fails when per-agent secret is set */
    public function test_shared_token_rejected_when_per_agent_secret_set(): void
    {
        config(['soc.agent_enrollment_token' => 'shared-token']);
        $agent   = EndpointAgent::factory()->create(['hmac_secret' => 'different-agent-secret']);
        $payload = 'test-payload';
        $sig     = 'sha256=' . hash_hmac('sha256', $payload, 'shared-token');

        $this->assertFalse(
            $this->svc->isSignatureValid($sig, $payload, $agent->agent_id)
        );
    }

    /** Falls back to shared enrollment token when hmac_secret is null */
    public function test_falls_back_to_shared_token_when_no_per_agent_secret(): void
    {
        config(['soc.agent_enrollment_token' => 'shared-token-fallback']);
        $agent   = EndpointAgent::factory()->create(['hmac_secret' => null]);
        $payload = 'test-payload';
        $sig     = 'sha256=' . hash_hmac('sha256', $payload, 'shared-token-fallback');

        $this->assertTrue(
            $this->svc->isSignatureValid($sig, $payload, $agent->agent_id)
        );
    }

    /** Returns false when no per-agent secret and no shared token configured */
    public function test_returns_false_with_no_secret_configured(): void
    {
        config(['soc.agent_enrollment_token' => '']);
        $agent   = EndpointAgent::factory()->create(['hmac_secret' => null]);
        $payload = 'test-payload';
        $sig     = 'sha256=' . hash_hmac('sha256', $payload, '');

        $this->assertFalse(
            $this->svc->isSignatureValid($sig, $payload, $agent->agent_id)
        );
    }

    /** Returns false for empty signature regardless of secret */
    public function test_empty_signature_always_false(): void
    {
        $agent = EndpointAgent::factory()->create(['hmac_secret' => 'some-secret']);
        $this->assertFalse(
            $this->svc->isSignatureValid('', 'test-payload', $agent->agent_id)
        );
    }

    /** isSignatureValid still works without agentId (backward compat) */
    public function test_is_signature_valid_without_agent_id(): void
    {
        config(['soc.agent_enrollment_token' => 'shared-fallback']);
        $payload = 'test';
        $sig     = 'sha256=' . hash_hmac('sha256', $payload, 'shared-fallback');
        $this->assertTrue($this->svc->isSignatureValid($sig, $payload));
    }

    /** hmac_secret is in EndpointAgent fillable list */
    public function test_hmac_secret_is_fillable(): void
    {
        $agent = new EndpointAgent();
        $this->assertContains('hmac_secret', $agent->getFillable());
    }

    /** tenant_id is in EndpointAgent fillable list (AGENT-TENANCY-GAP) */
    public function test_tenant_id_is_fillable(): void
    {
        $agent = new EndpointAgent();
        $this->assertContains('tenant_id', $agent->getFillable());
    }
}
