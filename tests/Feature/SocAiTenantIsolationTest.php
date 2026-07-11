<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TenantContextAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AI-2 — SocAiController::generate()/review() previously checked only the
 * target incident's *existence* (not ownership) and looked up AI suggestions
 * globally by suggestion_id, with zero tenant context resolved anywhere.
 * Any authenticated user could request an AI suggestion against another
 * tenant's incident, or review/accept-into-knowledge-base another tenant's
 * pending suggestion. `soc_knowledge_base` tenant scoping (the downstream
 * feedback-loop write) is a separate, already-tracked finding (KB-1) and is
 * deliberately left untouched here.
 */
class SocAiTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function seedIncident(string $incidentId, ?string $tenantId): void
    {
        DB::table('security_incidents')->insert([
            'incident_id' => $incidentId,
            'title' => 'AI Tenancy Test Incident',
            'status' => 'open',
            'severity' => 'high',
            'confidence' => 0.9,
            'tenant_id' => $tenantId,
            'first_seen_at' => now()->subMinutes(10),
            'last_seen_at' => now(),
            'affected_entities' => json_encode(['host-ai']),
            'timeline' => json_encode([]),
            'mitre_mapping' => json_encode([]),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedSuggestion(string $suggestionId, string $incidentId, ?string $tenantId, string $status = 'pending_review'): void
    {
        DB::table('ai_analyst_suggestions')->insert([
            'suggestion_id' => $suggestionId,
            'target_type' => 'incident',
            'target_id' => $incidentId,
            'tenant_id' => $tenantId,
            'suggestion_type' => 'incident_summary',
            'provider' => 'local',
            'status' => $status,
            'requested_by' => 'analyst@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function analystInTenantA(): User
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        app(TenantContextAuthority::class)->grantMembership($analyst->id, 'tenant-a', $analyst->id);

        return $analyst;
    }

    public function test_generate_returns_404_for_incident_in_other_tenant(): void
    {
        $this->seedIncident('inc-tenant-b', 'tenant-b');
        $analyst = $this->analystInTenantA();

        $response = $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->post('/soc/incidents/inc-tenant-b/ai', ['suggestion_type' => 'incident_summary']);

        $response->assertStatus(404);
        $this->assertDatabaseMissing('ai_analyst_suggestions', ['target_id' => 'inc-tenant-b']);
    }

    public function test_generate_succeeds_for_own_tenant_incident_and_stamps_tenant_id(): void
    {
        $this->seedIncident('inc-tenant-a', 'tenant-a');
        $analyst = $this->analystInTenantA();

        $response = $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->post('/soc/incidents/inc-tenant-a/ai', ['suggestion_type' => 'incident_summary']);

        $response->assertRedirect();
        $suggestion = DB::table('ai_analyst_suggestions')->where('target_id', 'inc-tenant-a')->first();
        $this->assertNotNull($suggestion);
        $this->assertSame('tenant-a', $suggestion->tenant_id);
    }

    public function test_generate_succeeds_for_legacy_null_tenant_incident(): void
    {
        $this->seedIncident('inc-legacy', null);
        $analyst = $this->analystInTenantA();

        $response = $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->post('/soc/incidents/inc-legacy/ai', ['suggestion_type' => 'incident_summary']);

        $response->assertRedirect();
        $this->assertDatabaseHas('ai_analyst_suggestions', ['target_id' => 'inc-legacy']);
    }

    public function test_generate_unscoped_caller_can_still_reach_any_incident(): void
    {
        // No X-Tenant-ID header sent at all -- matches every other
        // ENT-TENANCY-* fix's "null request tenant = unscoped/legacy caller"
        // permissive behavior, not a new restriction.
        $this->seedIncident('inc-tenant-b', 'tenant-b');
        $analyst = User::factory()->create(['role' => 'analyst']);

        $response = $this->actingAs($analyst)
            ->post('/soc/incidents/inc-tenant-b/ai', ['suggestion_type' => 'incident_summary']);

        $response->assertRedirect();
        $this->assertDatabaseHas('ai_analyst_suggestions', ['target_id' => 'inc-tenant-b']);
    }

    public function test_review_returns_404_for_suggestion_in_other_tenant(): void
    {
        $this->seedIncident('inc-b', 'tenant-b');
        $this->seedSuggestion('sugg-b', 'inc-b', 'tenant-b');
        $analyst = $this->analystInTenantA();

        $response = $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->post('/soc/ai/sugg-b/review', ['status' => 'accepted']);

        $response->assertStatus(404);
        $this->assertDatabaseHas('ai_analyst_suggestions', ['suggestion_id' => 'sugg-b', 'status' => 'pending_review']);
    }

    public function test_review_does_not_leak_into_knowledge_base_for_other_tenant_suggestion(): void
    {
        $this->seedIncident('inc-b', 'tenant-b');
        $this->seedSuggestion('sugg-b', 'inc-b', 'tenant-b');
        $analyst = $this->analystInTenantA();

        $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->post('/soc/ai/sugg-b/review', ['status' => 'accepted']);

        $this->assertDatabaseMissing('soc_knowledge_base', ['related_incident_id' => 'inc-b']);
    }

    public function test_review_succeeds_for_own_tenant_suggestion(): void
    {
        $this->seedIncident('inc-a', 'tenant-a');
        $this->seedSuggestion('sugg-a', 'inc-a', 'tenant-a');
        $analyst = $this->analystInTenantA();

        $response = $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->post('/soc/ai/sugg-a/review', ['status' => 'accepted', 'review_note' => 'looks right']);

        $response->assertRedirect();
        $this->assertDatabaseHas('ai_analyst_suggestions', ['suggestion_id' => 'sugg-a', 'status' => 'accepted']);
    }

    public function test_review_succeeds_for_legacy_null_tenant_suggestion(): void
    {
        $this->seedIncident('inc-legacy', null);
        $this->seedSuggestion('sugg-legacy', 'inc-legacy', null);
        $analyst = $this->analystInTenantA();

        $response = $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->post('/soc/ai/sugg-legacy/review', ['status' => 'accepted']);

        $response->assertRedirect();
        $this->assertDatabaseHas('ai_analyst_suggestions', ['suggestion_id' => 'sugg-legacy', 'status' => 'accepted']);
    }

    public function test_review_returns_404_for_unknown_suggestion(): void
    {
        $analyst = $this->analystInTenantA();

        $response = $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->post('/soc/ai/does-not-exist/review', ['status' => 'accepted']);

        $response->assertStatus(404);
    }
}
