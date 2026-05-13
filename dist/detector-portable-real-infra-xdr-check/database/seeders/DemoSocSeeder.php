<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoSocSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        foreach ([
            ['name' => 'SOC Admin', 'email' => 'soc-admin@example.com', 'role' => 'admin'],
            ['name' => 'SOC Analyst', 'email' => 'soc-analyst@example.com', 'role' => 'analyst'],
            ['name' => 'SOC Viewer', 'email' => 'soc-viewer@example.com', 'role' => 'viewer'],
        ] as $user) {
            User::updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'role' => $user['role'],
                    'password' => 'password',
                    'email_verified_at' => $now,
                ]
            );
        }

        $incidents = [
            [
                'incident_id' => 'DEMO-INC-001',
                'title' => 'Suspicious credential attack followed by scan activity',
                'status' => 'investigating',
                'severity' => 'critical',
                'confidence' => 0.92,
                'assigned_analyst' => 'soc-analyst@example.com',
                'first_seen_at' => $now->copy()->subHours(2),
                'last_seen_at' => $now->copy()->subMinutes(12),
                'sla_due_at' => $now->copy()->addMinutes(45),
                'escalation_level' => 1,
                'affected_entities' => json_encode([
                    ['type' => 'ip', 'value' => '203.0.113.50'],
                    ['type' => 'host', 'value' => 'web-prod-01'],
                    ['type' => 'user', 'value' => 'admin@example.com'],
                ]),
                'timeline' => json_encode([
                    ['ts' => $now->copy()->subHours(2)->toIso8601String(), 'event' => 'Repeated login failures'],
                    ['ts' => $now->copy()->subMinutes(40)->toIso8601String(), 'event' => 'Directory scan burst'],
                    ['ts' => $now->copy()->subMinutes(12)->toIso8601String(), 'event' => 'Injection indicator observed'],
                ]),
                'mitre_mapping' => json_encode([
                    ['technique' => 'T1110', 'name' => 'Brute Force'],
                    ['technique' => 'T1595', 'name' => 'Active Scanning'],
                ]),
                'metadata' => json_encode(['demo' => true, 'source' => 'DemoSocSeeder']),
                'resolution_summary' => null,
                'resolved_at' => null,
            ],
            [
                'incident_id' => 'DEMO-INC-002',
                'title' => 'Possible C2 beacon pattern from workstation',
                'status' => 'triaged',
                'severity' => 'high',
                'confidence' => 0.81,
                'assigned_analyst' => 'soc-analyst@example.com',
                'first_seen_at' => $now->copy()->subHours(6),
                'last_seen_at' => $now->copy()->subMinutes(30),
                'sla_due_at' => $now->copy()->addHours(3),
                'escalation_level' => 0,
                'affected_entities' => json_encode([
                    ['type' => 'host', 'value' => 'win-client-07'],
                    ['type' => 'domain', 'value' => 'c2-demo.example'],
                    ['type' => 'ip', 'value' => '198.51.100.88'],
                ]),
                'timeline' => json_encode([
                    ['ts' => $now->copy()->subHours(6)->toIso8601String(), 'event' => 'Periodic DNS queries started'],
                    ['ts' => $now->copy()->subHours(5)->toIso8601String(), 'event' => 'Outbound connection pattern repeated'],
                ]),
                'mitre_mapping' => json_encode([
                    ['technique' => 'T1071', 'name' => 'Application Layer Protocol'],
                    ['technique' => 'T1105', 'name' => 'Ingress Tool Transfer'],
                ]),
                'metadata' => json_encode(['demo' => true, 'source' => 'DemoSocSeeder']),
                'resolution_summary' => null,
                'resolved_at' => null,
            ],
            [
                'incident_id' => 'DEMO-INC-003',
                'title' => 'Known backup job false-positive review',
                'status' => 'false_positive',
                'severity' => 'medium',
                'confidence' => 0.54,
                'assigned_analyst' => 'soc-admin@example.com',
                'first_seen_at' => $now->copy()->subDay(),
                'last_seen_at' => $now->copy()->subHours(20),
                'sla_due_at' => $now->copy()->subHours(18),
                'escalation_level' => 0,
                'affected_entities' => json_encode([
                    ['type' => 'host', 'value' => 'backup-01'],
                    ['type' => 'ip', 'value' => '10.10.10.20'],
                ]),
                'timeline' => json_encode([
                    ['ts' => $now->copy()->subDay()->toIso8601String(), 'event' => 'High-volume internal connection pattern'],
                    ['ts' => $now->copy()->subHours(20)->toIso8601String(), 'event' => 'Matched approved backup baseline'],
                ]),
                'mitre_mapping' => json_encode([]),
                'metadata' => json_encode(['demo' => true, 'source' => 'DemoSocSeeder']),
                'resolution_summary' => 'Confirmed scheduled backup activity and marked as false positive.',
                'resolved_at' => $now->copy()->subHours(19),
            ],
        ];

        foreach ($incidents as $incident) {
            DB::table('security_incidents')->updateOrInsert(
                ['incident_id' => $incident['incident_id']],
                array_merge($incident, ['created_at' => $now, 'updated_at' => $now])
            );
        }

        $alerts = [
            ['DEMO-ALERT-001', 'DEMO-INC-001', 'BRUTE_FORCE_IP', 'critical', '203.0.113.50', 0.95, 'T1110'],
            ['DEMO-ALERT-002', 'DEMO-INC-001', 'SCAN_BURST', 'high', '203.0.113.50', 0.88, 'T1595'],
            ['DEMO-ALERT-003', 'DEMO-INC-001', 'INJECTION_INDICATOR', 'high', '203.0.113.50', 0.91, 'T1190'],
            ['DEMO-ALERT-004', 'DEMO-INC-002', 'C2_DNS_BEACON_PATTERN', 'high', '198.51.100.88', 0.82, 'T1071'],
            ['DEMO-ALERT-005', 'DEMO-INC-002', 'C2_CONNECTION_PATTERN', 'medium', '198.51.100.88', 0.77, 'T1105'],
            ['DEMO-ALERT-006', 'DEMO-INC-003', 'LATERAL_MOVEMENT_SUSPECTED', 'medium', '10.10.10.20', 0.53, ''],
        ];

        foreach ($alerts as $idx => [$alertId, $incidentId, $type, $severity, $ip, $score, $technique]) {
            $evidence = [
                'demo' => true,
                'mitre_attack' => $technique ? [['technique' => $technique]] : [],
                'evidence_chain' => [
                    ['event_id' => 'demo-event-'.$idx, 'host_id' => $incidentId, 'src_ip' => $ip],
                ],
                'confidence' => ['score' => $score, 'severity' => $severity],
            ];
            DB::table('security_alerts')->updateOrInsert(
                ['alert_id' => $alertId],
                [
                    'alert_fingerprint' => hash('sha256', $type.'|'.$ip),
                    'dedup_group' => $type.'|'.$ip,
                    'is_suppressed' => $incidentId === 'DEMO-INC-003',
                    'incident_id' => $incidentId,
                    'detected_at' => $now->copy()->subMinutes(60 - ($idx * 6)),
                    'alert_type' => $type,
                    'detector_name' => 'demo-seed',
                    'detector_version' => 'demo-v1',
                    'severity' => $severity,
                    'ip' => $ip,
                    'score' => $score,
                    'evidence' => json_encode($evidence),
                    'raw_event' => json_encode(['demo' => true]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            DB::table('security_incident_alerts')->updateOrInsert(
                ['incident_id' => $incidentId, 'alert_id' => $alertId],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }

        $telemetry = [
            ['demo-tel-001', 'endpoint', 'login_failed', 'web-prod-01', '203.0.113.50', null, null, 'auth-service'],
            ['demo-tel-002', 'network', 'http_request', 'web-prod-01', '203.0.113.50', '10.0.0.10', 443, 'nginx'],
            ['demo-tel-003', 'dns', 'dns_query', 'win-client-07', '10.0.8.77', '8.8.8.8', 53, 'powershell.exe'],
            ['demo-tel-004', 'network', 'connection_attempt', 'win-client-07', '10.0.8.77', '198.51.100.88', 443, 'powershell.exe'],
            ['demo-tel-005', 'network', 'connection_attempt', 'backup-01', '10.10.10.20', '10.10.20.30', 445, 'backup-agent'],
        ];
        foreach ($telemetry as $idx => [$eventId, $telemetryType, $eventType, $host, $srcIp, $dstIp, $dstPort, $process]) {
            DB::table('telemetry_events')->updateOrInsert(
                ['event_id' => $eventId],
                [
                    'ts' => $now->copy()->subMinutes(45 - ($idx * 3)),
                    'telemetry_type' => $telemetryType,
                    'event_type' => $eventType,
                    'host_id' => $host,
                    'src_ip' => $srcIp,
                    'dst_ip' => $dstIp,
                    'dst_port' => $dstPort,
                    'protocol' => $dstPort === 53 ? 'udp' : 'tcp',
                    'process_name' => $process,
                    'payload' => json_encode(['demo' => true, 'process_name' => $process]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        foreach ([
            ['soc-analyst@example.com', 'incident.assign', 'DEMO-INC-001'],
            ['soc-analyst@example.com', 'incident.status', 'DEMO-INC-001'],
            ['soc-admin@example.com', 'incident.false_positive', 'DEMO-INC-003'],
            ['soc-admin@example.com', 'export.download', 'jsonl'],
        ] as [$actor, $action, $target]) {
            DB::table('security_audit_trails')->insert([
                'occurred_at' => $now->copy()->subMinutes(rand(5, 90)),
                'actor' => $actor,
                'action' => $action,
                'target_type' => str_starts_with($target, 'DEMO-INC') ? 'incident' : 'export',
                'target_id' => $target,
                'before_state' => json_encode(['demo' => true]),
                'after_state' => json_encode(['demo' => true]),
                'meta' => json_encode(['source' => 'DemoSocSeeder']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('security_incident_notes')->updateOrInsert(
            ['incident_id' => 'DEMO-INC-001', 'note_type' => 'note', 'body' => 'Demo note: analyst is validating credential attack scope.'],
            ['author' => 'soc-analyst@example.com', 'metadata' => json_encode(['demo' => true]), 'created_at' => $now, 'updated_at' => $now]
        );
    }
}
