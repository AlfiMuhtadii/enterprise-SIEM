<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SocNotificationSlaTest extends TestCase
{
    use RefreshDatabase;

    public function test_sla_escalation_updates_overdue_incident_and_logs_notification_delivery(): void
    {
        Http::fake(['https://example.test/*' => Http::response(['ok' => true], 200)]);
        Config::set('notifications_soc.webhook_url', 'https://example.test/webhook');
        Config::set('notifications_soc.slack_url', null);
        Config::set('notifications_soc.discord_url', null);

        DB::table('security_incidents')->insert([
            'incident_id' => 'INC-SLA-1',
            'title' => 'Overdue critical',
            'status' => 'open',
            'severity' => 'critical',
            'confidence' => 0.9,
            'first_seen_at' => now()->subHours(2),
            'last_seen_at' => now()->subHour(),
            'sla_due_at' => now()->subMinutes(10),
            'escalation_level' => 0,
            'affected_entities' => json_encode([]),
            'timeline' => json_encode([]),
            'mitre_mapping' => json_encode([]),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('soc:sla-escalate')->assertExitCode(0);

        $this->assertDatabaseHas('security_incidents', [
            'incident_id' => 'INC-SLA-1',
            'escalation_level' => 1,
            'status' => 'triaged',
        ]);
        $this->assertDatabaseHas('notification_delivery_logs', [
            'target_type' => 'webhook',
            'event_type' => 'sla_breach',
            'incident_id' => 'INC-SLA-1',
            'status' => 'delivered',
        ]);
    }
}
