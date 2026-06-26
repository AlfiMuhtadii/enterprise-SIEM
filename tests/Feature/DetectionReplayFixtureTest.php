<?php

namespace Tests\Feature;

use App\Models\DetectionFixtureBatch;
use App\Models\User;
use App\Services\DetectionReplayFixtureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ENTERPRISE-056: Detection Replay Fixture Batch 1
 *
 * Validates: safety constants, fixture validation, dry-run batch, persistence,
 * getBatches, getLatestBatchResults, getFixturePaths, route access, JSON API.
 */
class DetectionReplayFixtureTest extends TestCase
{
    use RefreshDatabase;

    private DetectionReplayFixtureService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DetectionReplayFixtureService::class);
    }

    // ── Safety constants ──────────────────────────────────────────────────────

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(DetectionReplayFixtureService::ADVISORY_ONLY);
    }

    public function test_promotion_blocked_constant_is_true(): void
    {
        $this->assertTrue(DetectionReplayFixtureService::PROMOTION_BLOCKED);
    }

    public function test_batch_1_tier_is_tier_1_immediate(): void
    {
        $this->assertSame('tier_1_immediate', DetectionReplayFixtureService::BATCH_1_TIER);
    }

    // ── Fixture files on disk ─────────────────────────────────────────────────

    public function test_fixture_directory_exists(): void
    {
        $dir = base_path('tests/fixtures/detection/tier1_batch1');
        $this->assertDirectoryExists($dir, 'Fixture directory missing');
    }

    public function test_at_least_12_fixture_json_files_present(): void
    {
        $files = glob(base_path('tests/fixtures/detection/tier1_batch1/*.json'));
        $this->assertGreaterThanOrEqual(12, count($files), 'Expected >= 12 fixture files');
    }

    public function test_fixture_files_are_valid_json_arrays(): void
    {
        $files = glob(base_path('tests/fixtures/detection/tier1_batch1/*.json'));
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            $this->assertIsArray($data, basename($file) . ' is not a JSON array');
            $this->assertNotEmpty($data, basename($file) . ' is empty array');
        }
    }

    // ── validateFixture ───────────────────────────────────────────────────────

    public function test_validate_fixture_returns_valid_for_mfa_burst(): void
    {
        $path   = base_path('tests/fixtures/detection/tier1_batch1/IDENTITY_MFA_FAILURE_BURST.json');
        $result = $this->service->validateFixture($path);
        $this->assertTrue($result['valid'], 'MFA fixture should be valid: ' . $result['reason']);
    }

    public function test_validate_fixture_returns_invalid_for_missing_file(): void
    {
        $result = $this->service->validateFixture('/nonexistent/path.json');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('not found', $result['reason']);
    }

    public function test_validate_fixture_returns_invalid_for_empty_array(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'fixture_') . '.json';
        file_put_contents($tmpFile, '[]');
        $result = $this->service->validateFixture($tmpFile);
        $this->assertFalse($result['valid']);
        unlink($tmpFile);
    }

    // ── getFixturePaths ───────────────────────────────────────────────────────

    public function test_get_fixture_paths_returns_12_entries(): void
    {
        $paths = $this->service->getFixturePaths();
        $this->assertCount(12, $paths);
    }

    public function test_get_fixture_paths_includes_identity_rules(): void
    {
        $paths = $this->service->getFixturePaths();
        $this->assertArrayHasKey('IDENTITY_MFA_FAILURE_BURST', $paths);
        $this->assertArrayHasKey('IDENTITY_IMPOSSIBLE_TRAVEL', $paths);
    }

    // ── dry-run ───────────────────────────────────────────────────────────────

    public function test_dry_run_returns_batch_and_results(): void
    {
        $result = $this->service->runBatch('tier_1_immediate', true);
        $this->assertArrayHasKey('batch', $result);
        $this->assertArrayHasKey('results', $result);
    }

    public function test_dry_run_batch_has_promotion_blocked_true(): void
    {
        $result = $this->service->runBatch('tier_1_immediate', true);
        $this->assertTrue($result['batch']['promotion_blocked']);
    }

    public function test_dry_run_batch_has_is_advisory_true(): void
    {
        $result = $this->service->runBatch('tier_1_immediate', true);
        $this->assertTrue($result['batch']['is_advisory']);
    }

    public function test_dry_run_returns_12_results(): void
    {
        $result = $this->service->runBatch('tier_1_immediate', true);
        $this->assertCount(12, $result['results']);
    }

    public function test_dry_run_does_not_persist(): void
    {
        $this->service->runBatch('tier_1_immediate', true);
        $this->assertDatabaseCount('detection_fixture_batches', 0);
    }

    // ── persistence ───────────────────────────────────────────────────────────

    public function test_run_batch_persists_batch_row(): void
    {
        $this->service->runBatch('tier_1_immediate', false);
        $this->assertDatabaseCount('detection_fixture_batches', 1);
    }

    public function test_run_batch_persists_12_result_rows(): void
    {
        $this->service->runBatch('tier_1_immediate', false);
        $this->assertDatabaseCount('detection_fixture_results', 12);
    }

    public function test_run_batch_sets_tier_correctly(): void
    {
        $this->service->runBatch('tier_1_immediate', false);
        $this->assertDatabaseHas('detection_fixture_batches', ['tier' => 'tier_1_immediate']);
    }

    public function test_run_batch_sets_promotion_blocked_true(): void
    {
        $this->service->runBatch('tier_1_immediate', false);
        $this->assertDatabaseHas('detection_fixture_batches', ['promotion_blocked' => true]);
    }

    // ── getBatches / getLatestBatchResults ────────────────────────────────────

    public function test_get_batches_returns_collection(): void
    {
        $batches = $this->service->getBatches();
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $batches);
    }

    public function test_get_batches_returns_empty_before_any_run(): void
    {
        $this->assertCount(0, $this->service->getBatches());
    }

    public function test_get_batches_returns_1_after_run(): void
    {
        $this->service->runBatch('tier_1_immediate', false);
        $this->assertCount(1, $this->service->getBatches());
    }

    public function test_get_latest_batch_results_returns_empty_before_run(): void
    {
        $this->assertCount(0, $this->service->getLatestBatchResults());
    }

    public function test_get_latest_batch_results_returns_12_after_run(): void
    {
        $this->service->runBatch('tier_1_immediate', false);
        $this->assertCount(12, $this->service->getLatestBatchResults());
    }

    // ── Routes ────────────────────────────────────────────────────────────────

    public function test_fixture_batches_route_redirects_unauthenticated(): void
    {
        $response = $this->get('/detection/fixture-batches');
        $response->assertRedirect();
    }

    public function test_fixture_batches_route_accessible_to_admin(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->get('/detection/fixture-batches');
        $response->assertStatus(200);
    }

    public function test_fixture_batches_json_api_returns_advisory_only(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->getJson('/detection/fixture-batches');
        $response->assertStatus(200);
        $response->assertJsonPath('advisory_only', true);
        $response->assertJsonPath('promotion_blocked', true);
    }
}
