<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SocWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyst_can_update_incident_status_and_audit_is_recorded(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->seedIncident('INC-TEST-1');

        $this->actingAs($analyst)->post('/soc/incidents/INC-TEST-1', [
            'status' => 'investigating',
            'severity' => 'high',
            'assigned_analyst' => 'analyst1',
            'action' => 'investigate',
            'note' => 'Investigating suspicious chain.',
        ])->assertRedirect(route('soc.incidents.show', 'INC-TEST-1'));

        $this->assertDatabaseHas('security_incidents', [
            'incident_id' => 'INC-TEST-1',
            'status' => 'investigating',
            'severity' => 'high',
            'assigned_analyst' => 'analyst1',
        ]);
        $this->assertDatabaseHas('security_incident_notes', [
            'incident_id' => 'INC-TEST-1',
            'author' => $analyst->email,
        ]);
        $this->assertDatabaseHas('security_audit_trails', [
            'actor' => $analyst->email,
            'action' => 'incident.update',
            'target_id' => 'INC-TEST-1',
        ]);
    }

    public function test_workflow_api_returns_json(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->seedIncident('INC-TEST-2');

        $this->actingAs($analyst)->postJson('/soc/api/incidents/INC-TEST-2/workflow', [
            'action' => 'triage',
            'status' => 'triaged',
        ])->assertOk()->assertJson(['ok' => true]);
    }

    private function seedIncident(string $incidentId): void
    {
        DB::table('security_incidents')->insert([
            'incident_id' => $incidentId,
            'title' => 'Test incident',
            'status' => 'open',
            'severity' => 'medium',
            'confidence' => 0.7,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'affected_entities' => json_encode(['10.0.0.1']),
            'timeline' => json_encode([]),
            'mitre_mapping' => json_encode(['T1021']),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
