<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SecurityRetentionArchiveService;
use App\Support\Rbac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DATA-TIERING (phase 2b): ArchiveSearchController closes the CLI-only gap
 * from ArchiveSearchService (phase 2) with an RBAC-gated browser route.
 *
 * The controller hard-codes the same default archive dir
 * SecurityRetentionCommand uses (storage/app/archives) — no injectable
 * override exists for a browser route (unlike the CLI's --archive-dir), so
 * these tests write fixtures into that real default path and clean up only
 * what they created.
 */
class ArchiveSearchControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $archiveDir;

    private string $testTenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->archiveDir = storage_path('app/archives');
        // Unique per-test tenant id so cleanup only ever removes fixtures
        // this test itself created, never a real archive directory.
        $this->testTenantId = 'archive-search-controller-test-'.uniqid();
    }

    protected function tearDown(): void
    {
        $dir = "{$this->archiveDir}/security_alerts/{$this->testTenantId}";
        if (is_dir($dir)) {
            foreach (glob("{$dir}/*.jsonl.gz") ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
        parent::tearDown();
    }

    private function seedAndArchiveAlert(string $alertType): void
    {
        DB::table('security_alerts')->insert([
            'alert_id' => 'alert-'.uniqid('', true),
            'detected_at' => now()->subDays(100),
            'alert_type' => $alertType,
            'detector_name' => 'TEST',
            'detector_version' => 'v1',
            'severity' => 'high',
            'tenant_id' => $this->testTenantId,
            'score' => 0.9,
            'evidence' => json_encode(['probe' => 'archive-search-controller-test']),
            'raw_event' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        (new SecurityRetentionArchiveService($this->archiveDir))
            ->archiveAndDelete('security_alerts', 'detected_at', now(), $this->testTenantId);
    }

    // ── RBAC ─────────────────────────────────────────────────────────────

    public function test_route_requires_auth(): void
    {
        $this->get('/archive-search')->assertRedirect('/login');
    }

    public function test_viewer_has_search_view_permission(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->assertTrue(Rbac::can($viewer, 'search.view'));
    }

    public function test_route_accessible_to_viewer_with_no_table_param(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)->get('/archive-search')->assertOk();
    }

    // ── Search behavior ──────────────────────────────────────────────────

    public function test_route_renders_results_for_matching_table(): void
    {
        $this->seedAndArchiveAlert('ROUTE_ARCHIVE_TEST_TYPE');
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)
            ->withHeaders(['X-Tenant-ID' => $this->testTenantId])
            ->get('/archive-search?table=security_alerts')
            ->assertOk()
            ->assertSee('ROUTE_ARCHIVE_TEST_TYPE');
    }

    public function test_route_applies_filters_query_param(): void
    {
        $this->seedAndArchiveAlert('FILTER_MATCH_TYPE');
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)
            ->withHeaders(['X-Tenant-ID' => $this->testTenantId])
            ->get('/archive-search?table=security_alerts&filters=alert_type=FILTER_MATCH_TYPE')
            ->assertOk()
            ->assertSee('FILTER_MATCH_TYPE');
    }

    public function test_route_shows_no_matches_message_for_nonexistent_table(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)
            ->get('/archive-search?table=security_alerts_that_does_not_exist_'.uniqid())
            ->assertOk()
            ->assertSee('No matches');
    }

    public function test_route_shows_summary_counters(): void
    {
        $this->seedAndArchiveAlert('SUMMARY_COUNTER_TYPE');
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)
            ->withHeaders(['X-Tenant-ID' => $this->testTenantId])
            ->get('/archive-search?table=security_alerts')
            ->assertOk()
            ->assertSee('results: 1');
    }
}
