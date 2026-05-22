<?php

namespace Tests\Feature;

use App\Models\DnsEvent;
use App\Models\FirewallEvent;
use App\Models\NetworkBehavioralFinding;
use App\Models\NetworkDestinationProfile;
use App\Models\ProxyEvent;
use App\Models\User;
use App\Services\NetworkAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DNS / Proxy / Firewall Analytics Phase 1 tests.
 *
 * Explicitly asserts:
 * - No active network blocking
 * - No firewall rule push
 * - No IP/domain block execution
 * - No DPI inspection
 * - No autonomous response
 * - Shadow-only enforcement for all rules
 * - Append-only storage
 * - No blocking action created
 * - No firewall rule mutation
 * - No packet sniffing
 * - Normalization preserves trace_id
 * - DNS/proxy/firewall analytics are deterministic
 */
class DnsProxyFirewallAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function svc(): NetworkAnalyticsService
    {
        return app(NetworkAnalyticsService::class);
    }

    private function makeDnsRaw(array $overrides = []): array
    {
        return array_merge([
            'event_id'      => DnsEvent::generateEventId(),
            'host_id'       => 'host-net-test',
            'source_ip'     => '10.0.0.5',
            'user'          => 'analyst@corp.local',
            'queried_domain'=> 'example.com',
            'query_type'    => 'A',
            'response_code' => 'NOERROR',
            'resolved_ips'  => ['93.184.216.34'],
            'trace_id'      => 'trace-' . Str::uuid(),
            'occurred_at'   => now()->toIso8601String(),
        ], $overrides);
    }

    private function makeProxyRaw(array $overrides = []): array
    {
        return array_merge([
            'event_id'    => ProxyEvent::generateEventId(),
            'host_id'     => 'host-net-test',
            'source_ip'   => '10.0.0.5',
            'user'        => 'analyst@corp.local',
            'url'         => 'https://example.com/path',
            'domain'      => 'example.com',
            'http_method' => 'GET',
            'status_code' => 200,
            'user_agent'  => 'Mozilla/5.0',
            'bytes_out'   => 1024,
            'bytes_in'    => 4096,
            'trace_id'    => 'trace-' . Str::uuid(),
            'occurred_at' => now()->toIso8601String(),
        ], $overrides);
    }

    private function makeFirewallRaw(array $overrides = []): array
    {
        return array_merge([
            'event_id'         => FirewallEvent::generateEventId(),
            'host_id'          => 'host-net-test',
            'source_ip'        => '10.0.0.5',
            'destination_ip'   => '8.8.8.8',
            'destination_port' => 443,
            'protocol'         => 'tcp',
            'action'           => 'allow',
            'bytes_out'        => 2048,
            'bytes_in'         => 8192,
            'trace_id'         => 'trace-' . Str::uuid(),
            'occurred_at'      => now()->toIso8601String(),
        ], $overrides);
    }

    // -----------------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------------

    public function test_dns_events_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('dns_events'));
    }

    public function test_proxy_events_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('proxy_events'));
    }

    public function test_firewall_events_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('firewall_events'));
    }

    public function test_network_behavioral_findings_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('network_behavioral_findings'));
    }

    public function test_network_destination_profiles_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('network_destination_profiles'));
    }

    public function test_dns_events_is_append_only(): void
    {
        $this->assertFalse(Schema::hasColumn('dns_events', 'updated_at'));
    }

    public function test_proxy_events_is_append_only(): void
    {
        $this->assertFalse(Schema::hasColumn('proxy_events', 'updated_at'));
    }

    public function test_firewall_events_is_append_only(): void
    {
        $this->assertFalse(Schema::hasColumn('firewall_events', 'updated_at'));
    }

    public function test_network_behavioral_findings_is_append_only(): void
    {
        $this->assertFalse(Schema::hasColumn('network_behavioral_findings', 'updated_at'));
    }

    // -----------------------------------------------------------------------
    // Telemetry schema validation
    // -----------------------------------------------------------------------

    public function test_dns_schema_file_exists(): void
    {
        $path = base_path('docs/contracts/telemetry/dns/dns-events.v1.schema.json');
        $this->assertFileExists($path);
        $schema = json_decode(file_get_contents($path), true);
        $this->assertSame('dns-events.v1', $schema['$id']);
        $this->assertSame('dns', $schema['properties']['telemetry_type']['const']);
    }

    public function test_proxy_schema_file_exists(): void
    {
        $path = base_path('docs/contracts/telemetry/proxy/proxy-events.v1.schema.json');
        $this->assertFileExists($path);
        $schema = json_decode(file_get_contents($path), true);
        $this->assertSame('proxy-events.v1', $schema['$id']);
        $this->assertSame('proxy', $schema['properties']['telemetry_type']['const']);
    }

    public function test_firewall_schema_file_exists(): void
    {
        $path = base_path('docs/contracts/telemetry/firewall/firewall-events.v1.schema.json');
        $this->assertFileExists($path);
        $schema = json_decode(file_get_contents($path), true);
        $this->assertSame('firewall-events.v1', $schema['$id']);
        $this->assertSame('firewall', $schema['properties']['telemetry_type']['const']);
    }

    // -----------------------------------------------------------------------
    // Ingestion — trace_id preserved, idempotent
    // -----------------------------------------------------------------------

    public function test_dns_ingestion_preserves_trace_id(): void
    {
        $traceId = 'trace-' . Str::uuid();
        $event   = $this->svc()->ingestDnsEvent($this->makeDnsRaw(['trace_id' => $traceId]));
        $this->assertSame($traceId, $event->trace_id);
    }

    public function test_proxy_ingestion_preserves_trace_id(): void
    {
        $traceId = 'trace-' . Str::uuid();
        $event   = $this->svc()->ingestProxyEvent($this->makeProxyRaw(['trace_id' => $traceId]));
        $this->assertSame($traceId, $event->trace_id);
    }

    public function test_firewall_ingestion_preserves_trace_id(): void
    {
        $traceId = 'trace-' . Str::uuid();
        $event   = $this->svc()->ingestFirewallEvent($this->makeFirewallRaw(['trace_id' => $traceId]));
        $this->assertSame($traceId, $event->trace_id);
    }

    public function test_dns_ingestion_is_idempotent(): void
    {
        $raw = $this->makeDnsRaw();
        $this->svc()->ingestDnsEvent($raw);
        $this->svc()->ingestDnsEvent($raw); // duplicate
        $this->assertSame(1, DnsEvent::where('event_id', $raw['event_id'])->count());
    }

    public function test_proxy_ingestion_is_idempotent(): void
    {
        $raw = $this->makeProxyRaw();
        $this->svc()->ingestProxyEvent($raw);
        $this->svc()->ingestProxyEvent($raw);
        $this->assertSame(1, ProxyEvent::where('event_id', $raw['event_id'])->count());
    }

    public function test_firewall_ingestion_is_idempotent(): void
    {
        $raw = $this->makeFirewallRaw();
        $this->svc()->ingestFirewallEvent($raw);
        $this->svc()->ingestFirewallEvent($raw);
        $this->assertSame(1, FirewallEvent::where('event_id', $raw['event_id'])->count());
    }

    public function test_dns_nxdomain_flag_set_correctly(): void
    {
        $event = $this->svc()->ingestDnsEvent($this->makeDnsRaw(['response_code' => 'NXDOMAIN']));
        $this->assertTrue((bool) $event->is_nxdomain);
    }

    public function test_proxy_deny_flag_set_for_403(): void
    {
        $event = $this->svc()->ingestProxyEvent($this->makeProxyRaw(['status_code' => 403]));
        $this->assertTrue((bool) $event->is_denied);
    }

    public function test_firewall_deny_flag_set_for_deny_action(): void
    {
        $event = $this->svc()->ingestFirewallEvent($this->makeFirewallRaw(['action' => 'deny']));
        $this->assertTrue((bool) $event->is_deny);
    }

    // -----------------------------------------------------------------------
    // DNS analytics — deterministic
    // -----------------------------------------------------------------------

    public function test_suspicious_dns_tld_detected(): void
    {
        $hostId = 'host-dns-tld-test';
        $this->svc()->ingestDnsEvent($this->makeDnsRaw(['host_id' => $hostId, 'queried_domain' => 'evil.tk']));

        $findings = $this->svc()->detectSuspiciousDnsTld($hostId, 300);
        $this->assertNotEmpty($findings);
        $this->assertSame('suspicious_dns_tld', $findings[0]->finding_type);
        $this->assertTrue((bool) $findings[0]->advisory_only);
    }

    public function test_dns_txt_query_pattern_detected(): void
    {
        $hostId = 'host-txt-test';
        $this->svc()->ingestDnsEvent($this->makeDnsRaw(['host_id' => $hostId, 'query_type' => 'TXT', 'queried_domain' => 'a.example.com']));
        $this->svc()->ingestDnsEvent($this->makeDnsRaw(['host_id' => $hostId, 'query_type' => 'TXT', 'queried_domain' => 'b.example.com']));

        $findings = $this->svc()->detectDnsTxtQueryPattern($hostId, 300);
        $this->assertNotEmpty($findings);
        $this->assertSame('dns_txt_query_pattern', $findings[0]->finding_type);
    }

    public function test_repeated_nxdomain_detected(): void
    {
        $hostId = 'host-nx-test';
        for ($i = 0; $i < 5; $i++) {
            $this->svc()->ingestDnsEvent($this->makeDnsRaw([
                'host_id'       => $hostId,
                'queried_domain'=> "fake{$i}.dga.example",
                'response_code' => 'NXDOMAIN',
            ]));
        }

        $findings = $this->svc()->detectRepeatedNxdomain($hostId, 300);
        $this->assertNotEmpty($findings);
        $this->assertSame('repeated_nxdomain', $findings[0]->finding_type);
    }

    public function test_dns_analytics_are_deterministic(): void
    {
        $hostId = 'host-det-test';
        $this->svc()->ingestDnsEvent($this->makeDnsRaw(['host_id' => $hostId, 'queried_domain' => 'bad.tk']));

        $f1 = $this->svc()->detectSuspiciousDnsTld($hostId, 300);
        $f2 = $this->svc()->detectSuspiciousDnsTld($hostId, 300);
        $this->assertCount(count($f1), $f2, 'DNS analytics must be deterministic');
    }

    // -----------------------------------------------------------------------
    // Proxy analytics — deterministic
    // -----------------------------------------------------------------------

    public function test_suspicious_user_agent_detected(): void
    {
        $hostId = 'host-ua-test';
        $this->svc()->ingestProxyEvent($this->makeProxyRaw(['host_id' => $hostId, 'user_agent' => 'sqlmap/1.0 (python3)']));

        $findings = $this->svc()->detectSuspiciousUserAgent($hostId, 300);
        $this->assertNotEmpty($findings);
        $this->assertSame('suspicious_user_agent', $findings[0]->finding_type);
        $this->assertTrue((bool) $findings[0]->advisory_only);
    }

    public function test_repeated_403_detected(): void
    {
        $hostId = 'host-deny-test';
        for ($i = 0; $i < 5; $i++) {
            $this->svc()->ingestProxyEvent($this->makeProxyRaw(['host_id' => $hostId, 'status_code' => 403]));
        }

        $findings = $this->svc()->detectRepeated403401($hostId, 300);
        $this->assertNotEmpty($findings);
        $this->assertSame('repeated_403_401', $findings[0]->finding_type);
    }

    public function test_proxy_analytics_deterministic(): void
    {
        $hostId = 'host-proxy-det';
        $this->svc()->ingestProxyEvent($this->makeProxyRaw(['host_id' => $hostId, 'user_agent' => 'curl/7.8']));

        $f1 = $this->svc()->detectSuspiciousUserAgent($hostId, 300);
        $f2 = $this->svc()->detectSuspiciousUserAgent($hostId, 300);
        $this->assertCount(count($f1), $f2);
    }

    // -----------------------------------------------------------------------
    // Firewall analytics — deterministic
    // -----------------------------------------------------------------------

    public function test_repeated_denied_outbound_detected(): void
    {
        $hostId = 'host-fw-deny-test';
        for ($i = 0; $i < 5; $i++) {
            $this->svc()->ingestFirewallEvent($this->makeFirewallRaw([
                'host_id' => $hostId,
                'action'  => 'deny',
            ]));
        }

        $findings = $this->svc()->detectRepeatedDeniedOutbound($hostId, 300);
        $this->assertNotEmpty($findings);
        $this->assertSame('repeated_denied_outbound', $findings[0]->finding_type);
        $this->assertTrue((bool) $findings[0]->advisory_only);
    }

    public function test_unusual_destination_port_detected(): void
    {
        $hostId = 'host-port-test';
        $this->svc()->ingestFirewallEvent($this->makeFirewallRaw([
            'host_id'          => $hostId,
            'destination_port' => 4444,
        ]));

        $findings = $this->svc()->detectUnusualDestinationPort($hostId, 300);
        $this->assertNotEmpty($findings);
        $this->assertSame('unusual_destination_port', $findings[0]->finding_type);
    }

    public function test_firewall_analytics_deterministic(): void
    {
        $hostId = 'host-fw-det';
        for ($i = 0; $i < 5; $i++) {
            $this->svc()->ingestFirewallEvent($this->makeFirewallRaw(['host_id' => $hostId, 'action' => 'deny']));
        }

        $f1 = $this->svc()->detectRepeatedDeniedOutbound($hostId, 300);
        $f2 = $this->svc()->detectRepeatedDeniedOutbound($hostId, 300);
        $this->assertCount(count($f1), $f2);
    }

    // -----------------------------------------------------------------------
    // Entity graph relationships
    // -----------------------------------------------------------------------

    public function test_dns_event_creates_destination_profile(): void
    {
        $this->svc()->ingestDnsEvent($this->makeDnsRaw(['queried_domain' => 'newdomain.example.com']));
        $this->assertDatabaseHas('network_destination_profiles', ['destination_key' => 'newdomain.example.com']);
    }

    // -----------------------------------------------------------------------
    // Threat hunting pivots
    // -----------------------------------------------------------------------

    public function test_pivot_domain_to_hosts(): void
    {
        $this->svc()->ingestDnsEvent($this->makeDnsRaw(['host_id' => 'host-a', 'queried_domain' => 'pivot.example.com']));

        $result = $this->svc()->pivotDomainToHosts('pivot.example.com');
        $this->assertContains('host-a', $result['hosts_via_dns']);
        $this->assertTrue($result['advisory_only']);
        $this->assertTrue($result['no_blocking']);
    }

    public function test_pivot_ip_to_hosts(): void
    {
        $this->svc()->ingestFirewallEvent($this->makeFirewallRaw(['host_id' => 'host-b', 'destination_ip' => '203.0.113.50']));

        $result = $this->svc()->pivotIpToHosts('203.0.113.50');
        $this->assertContains('host-b', $result['hosts_via_firewall']);
        $this->assertTrue($result['advisory_only']);
    }

    public function test_pivot_user_to_domains(): void
    {
        $this->svc()->ingestDnsEvent($this->makeDnsRaw(['user' => 'bob@corp.local', 'queried_domain' => 'user-pivot.example.com']));

        $result = $this->svc()->pivotUserToDomains('bob@corp.local');
        $this->assertContains('user-pivot.example.com', $result['dns_domains']);
        $this->assertTrue($result['advisory_only']);
    }

    // -----------------------------------------------------------------------
    // Shadow-only enforcement
    // -----------------------------------------------------------------------

    public function test_all_network_shadow_rules_use_shadow_network_topic(): void
    {
        $registry = json_decode(file_get_contents(base_path('docs/detection/rules/registry.v1.json')), true);
        $networkRules = array_filter($registry['rules'], fn ($r) => ($r['domain'] ?? '') === 'network');

        // 9 core network analytics + 2 UEBA network-domain rules + 1 lateral movement (SSH propagation)
        $this->assertCount(12, $networkRules, 'Expected 12 network domain rules (9 core + 2 UEBA Phase 1 + 1 Detection Depth Phase 2)');
        foreach ($networkRules as $rule) {
            $this->assertSame('shadow', $rule['status'], "{$rule['rule_id']} must be shadow");
            $this->assertTrue($rule['shadow_only'], "{$rule['rule_id']} must have shadow_only=true");
            // Core network rules use xdr.alerts.shadow.network; UEBA network rules use xdr.alerts.shadow.ueba
            $this->assertStringStartsWith('xdr.alerts.shadow', $rule['output_topic'],
                "{$rule['rule_id']} must use a shadow output topic");
        }

        // Core network analytics rules (non-UEBA) still use xdr.alerts.shadow.network
        $coreNetworkRules = array_filter($networkRules, fn ($r) => !str_starts_with($r['rule_id'] ?? '', 'UEBA_'));
        $this->assertCount(10, $coreNetworkRules, 'Expected 10 core network analytics rules (9 original + 1 lateral SSH Phase 2)');
        foreach ($coreNetworkRules as $rule) {
            $this->assertSame('xdr.alerts.shadow.network', $rule['output_topic'],
                "{$rule['rule_id']} must use xdr.alerts.shadow.network");
        }
    }

    public function test_all_findings_have_advisory_only_true(): void
    {
        $hostId = 'host-advisory-test';
        $this->svc()->ingestDnsEvent($this->makeDnsRaw(['host_id' => $hostId, 'queried_domain' => 'suspicious.tk']));
        $findings = $this->svc()->detectSuspiciousDnsTld($hostId, 300);

        foreach ($findings as $f) {
            $this->assertTrue((bool) $f->advisory_only, 'All findings must have advisory_only=true');
            $this->assertArrayHasKey('no_blocking', $f->evidence ?? []);
        }
    }

    // -----------------------------------------------------------------------
    // Safety boundary assertions
    // -----------------------------------------------------------------------

    public function test_no_active_blocking_in_analytics_service(): void
    {
        $src = file_get_contents(app_path('Services/NetworkAnalyticsService.php'));
        $this->assertStringNotContainsString('firewall_rule_push',   $src);
        $this->assertStringNotContainsString('block_domain',         $src);
        $this->assertStringNotContainsString('block_ip',             $src);
        $this->assertStringNotContainsString('dpi_inspect',          $src);
        $this->assertStringNotContainsString('autonomous_response',  $src);
    }

    public function test_no_packet_sniffing_in_analytics_service(): void
    {
        $src = file_get_contents(app_path('Services/NetworkAnalyticsService.php'));
        $this->assertStringNotContainsString('pcap',       $src);
        $this->assertStringNotContainsString('SOCK_RAW',   $src);
        $this->assertStringNotContainsString('AF_PACKET',  $src);
    }

    public function test_no_firewall_rule_mutation(): void
    {
        $src = file_get_contents(app_path('Services/NetworkAnalyticsService.php'));
        $this->assertStringNotContainsString('push_rule',    $src);
        $this->assertStringNotContainsString('delete_rule',  $src);
        $this->assertStringNotContainsString('modify_acl',   $src);
    }

    public function test_no_ip_block_in_findings(): void
    {
        $hostId = 'host-no-block-test';
        $this->svc()->ingestFirewallEvent($this->makeFirewallRaw(['host_id' => $hostId, 'destination_port' => 4444]));
        $findings = $this->svc()->detectUnusualDestinationPort($hostId, 300);

        foreach ($findings as $f) {
            $evidence = $f->evidence ?? [];
            $this->assertArrayNotHasKey('block_ip',     $evidence, 'Findings must not contain block_ip action');
            $this->assertArrayNotHasKey('push_rule',    $evidence, 'Findings must not contain push_rule action');
            $this->assertArrayNotHasKey('deny_action',  $evidence, 'Findings must not contain deny_action');
        }
    }

    // -----------------------------------------------------------------------
    // Routes and views
    // -----------------------------------------------------------------------

    public function test_network_dashboard_accessible(): void
    {
        $this->actingAs($this->admin())
             ->get(route('network.dashboard'))
             ->assertOk();
    }

    public function test_dns_investigation_accessible(): void
    {
        $this->actingAs($this->admin())
             ->get(route('network.dns'))
             ->assertOk();
    }

    public function test_proxy_investigation_accessible(): void
    {
        $this->actingAs($this->admin())
             ->get(route('network.proxy'))
             ->assertOk();
    }

    public function test_firewall_investigation_accessible(): void
    {
        $this->actingAs($this->admin())
             ->get(route('network.firewall'))
             ->assertOk();
    }

    public function test_behavioral_findings_accessible(): void
    {
        $this->actingAs($this->admin())
             ->get(route('network.findings'))
             ->assertOk();
    }

    public function test_threat_hunting_pivots_accessible(): void
    {
        $this->actingAs($this->admin())
             ->get(route('network.hunt'))
             ->assertOk();
    }

    public function test_network_routes_require_authentication(): void
    {
        $this->get(route('network.dashboard'))->assertRedirect('/login');
    }

    public function test_api_dashboard_returns_advisory_note(): void
    {
        $this->actingAs($this->admin())
             ->getJson(route('api.network.dashboard'))
             ->assertOk()
             ->assertJsonFragment(['advisory_note' => 'Network analytics are shadow-only and advisory. No blocking or containment is executed.']);
    }

    public function test_api_findings_returns_advisory_flag(): void
    {
        $this->actingAs($this->admin())
             ->getJson(route('api.network.findings'))
             ->assertOk()
             ->assertJsonFragment(['advisory_note' => 'Network analytics are shadow-only and advisory. No blocking or containment is executed.']);
    }

    public function test_dns_entropy_calculation_deterministic(): void
    {
        $e1 = DnsEvent::calculateEntropy('abcdefghijk');
        $e2 = DnsEvent::calculateEntropy('abcdefghijk');
        $this->assertSame($e1, $e2, 'Entropy calculation must be deterministic');
    }

    public function test_high_entropy_domain_entropy_above_threshold(): void
    {
        $entropy = DnsEvent::calculateEntropy('xkf7bq9z2mvw3p1');
        $this->assertGreaterThan(3.0, $entropy, 'Random-looking label should have high entropy');
    }
}
