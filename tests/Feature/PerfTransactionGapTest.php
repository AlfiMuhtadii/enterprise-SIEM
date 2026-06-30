<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PERF-TRANSACTION-GAP — the SLA escalation state change (incident update +
 * activity log) is wrapped in a single transaction so it commits atomically.
 */
class PerfTransactionGapTest extends TestCase
{
    use RefreshDatabase;

    private function seedOverdueIncident(string $id, string $status = 'open'): void
    {
        DB::table('security_incidents')->insert([
            'incident_id' => $id,
            'title' => 'Overdue',
            'status' => $status,
            'severity' => 'high',
            'confidence' => 0.9,
            'first_seen_at' => now()->subHours(3),
            'last_seen_at' => now()->subHour(),
            'sla_due_at' => now()->subMinutes(15),
            'escalation_level' => 0,
            'affected_entities' => json_encode([]),
            'timeline' => json_encode([]),
            'mitre_mapping' => json_encode([]),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_escalation_commits_incident_update_and_activity_together(): void
    {
        Http::fake();
        Config::set('notifications_soc.webhook_url', null);
        Config::set('notifications_soc.slack_url', null);
        Config::set('notifications_soc.discord_url', null);

        $this->seedOverdueIncident('INC-TX-1');

        $this->artisan('soc:sla-escalate', ['--notify' => 0])->assertExitCode(0);

        // both sides of the atomic block are present
        $this->assertDatabaseHas('security_incidents', [
            'incident_id' => 'INC-TX-1',
            'escalation_level' => 1,
            'status' => 'triaged',
        ]);
        $this->assertDatabaseHas('security_incident_activities', [
            'incident_id' => 'INC-TX-1',
            'activity_type' => 'sla_escalation',
        ]);
        // exactly one activity row per escalation (no duplicate / partial writes)
        $this->assertSame(1, DB::table('security_incident_activities')
            ->where('incident_id', 'INC-TX-1')->count());
    }

    public function test_escalation_handles_multiple_incidents_atomically(): void
    {
        Http::fake();
        $this->seedOverdueIncident('INC-TX-A');
        $this->seedOverdueIncident('INC-TX-B', 'investigating');

        $this->artisan('soc:sla-escalate', ['--notify' => 0])->assertExitCode(0);

        foreach (['INC-TX-A', 'INC-TX-B'] as $id) {
            $this->assertDatabaseHas('security_incidents', ['incident_id' => $id, 'escalation_level' => 1]);
            $this->assertDatabaseHas('security_incident_activities', ['incident_id' => $id, 'activity_type' => 'sla_escalation']);
        }
        $this->assertSame(2, DB::table('security_incident_activities')->where('activity_type', 'sla_escalation')->count());
    }
}
