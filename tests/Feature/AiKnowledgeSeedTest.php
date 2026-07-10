<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AiKnowledgeSeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AiKnowledgeSeedTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // loadFixtures()
    // -----------------------------------------------------------------------

    public function test_load_fixtures_returns_array(): void
    {
        $fixtures = app(AiKnowledgeSeedService::class)->loadFixtures();
        $this->assertIsArray($fixtures);
        $this->assertGreaterThanOrEqual(5, count($fixtures));
    }

    public function test_each_fixture_has_required_fields(): void
    {
        foreach (app(AiKnowledgeSeedService::class)->loadFixtures() as $f) {
            $this->assertArrayHasKey('fixture_id', $f);
            $this->assertArrayHasKey('title', $f);
            $this->assertArrayHasKey('category', $f);
            $this->assertArrayHasKey('content', $f);
        }
    }

    public function test_fixture_categories_cover_required_types(): void
    {
        $categories = array_column(app(AiKnowledgeSeedService::class)->loadFixtures(), 'category');
        $this->assertContains('detection_rule', $categories);
        $this->assertContains('soc_procedure', $categories);
    }

    public function test_max_fixtures_constant_is_positive(): void
    {
        $this->assertGreaterThan(0, AiKnowledgeSeedService::MAX_FIXTURES);
    }

    // -----------------------------------------------------------------------
    // seed() — dry-run
    // -----------------------------------------------------------------------

    public function test_dry_run_returns_correct_outcome(): void
    {
        $result = app(AiKnowledgeSeedService::class)->seed('test', true);
        $this->assertSame('DRY_RUN', $result['outcome']);
    }

    public function test_dry_run_does_not_insert_into_knowledge_base(): void
    {
        app(AiKnowledgeSeedService::class)->seed('test', true);
        $this->assertDatabaseCount('soc_knowledge_base', 0);
    }

    public function test_dry_run_still_persists_seed_run_row(): void
    {
        $result = app(AiKnowledgeSeedService::class)->seed('test', true);
        $this->assertDatabaseHas('rag_seed_runs', [
            'run_id'  => $result['run_id'],
            'dry_run' => true,
            'outcome' => 'DRY_RUN',
        ]);
    }

    // -----------------------------------------------------------------------
    // seed() — write mode
    // -----------------------------------------------------------------------

    public function test_seed_write_inserts_into_knowledge_base(): void
    {
        app(AiKnowledgeSeedService::class)->seed('test', false);
        $count = DB::table('soc_knowledge_base')->where('related_rule_id', 'like', 'rag-seed-%')->count();
        $this->assertGreaterThanOrEqual(5, $count);
    }

    public function test_seed_write_returns_done_outcome(): void
    {
        $result = app(AiKnowledgeSeedService::class)->seed('test', false);
        $this->assertSame('DONE', $result['outcome']);
        $this->assertSame(0, $result['fixtures_failed']);
    }

    public function test_seed_write_persists_run_row(): void
    {
        $result = app(AiKnowledgeSeedService::class)->seed('test@example.com', false);
        $this->assertDatabaseHas('rag_seed_runs', [
            'run_id'    => $result['run_id'],
            'dry_run'   => false,
            'outcome'   => 'DONE',
        ]);
    }

    public function test_seed_write_persists_document_log(): void
    {
        $result = app(AiKnowledgeSeedService::class)->seed('test', false);
        $logCount = DB::table('rag_seed_document_log')->where('run_id', $result['run_id'])->count();
        $this->assertGreaterThanOrEqual(5, $logCount);
    }

    public function test_seed_is_idempotent(): void
    {
        $svc = app(AiKnowledgeSeedService::class);
        $r1  = $svc->seed('test', false);
        $r2  = $svc->seed('test', false);

        // Second run: all skipped (already exists in KB)
        $this->assertSame(0, $r2['fixtures_seeded']);
        $this->assertGreaterThan(0, $r2['fixtures_skipped']);
    }

    public function test_seed_updates_fixture_definitions(): void
    {
        app(AiKnowledgeSeedService::class)->seed('test', false);
        $fixtures = app(AiKnowledgeSeedService::class)->loadFixtures();
        $first    = $fixtures[0];
        $this->assertDatabaseHas('rag_seed_fixtures', ['fixture_id' => $first['fixture_id']]);
    }

    // -----------------------------------------------------------------------
    // AI-KB-FEED-INGEST: bundled offline MITRE ATT&CK technique-coverage fixtures
    // -----------------------------------------------------------------------

    public function test_fixtures_include_bundled_mitre_technique_coverage(): void
    {
        $fixtures = app(AiKnowledgeSeedService::class)->loadFixtures();
        $mitre = array_filter($fixtures, fn (array $f) => str_starts_with($f['fixture_id'], 'ti-mitre-'));

        $this->assertGreaterThanOrEqual(100, count($mitre), 'expected one KB fixture per unique MITRE technique in the rule registry');
        foreach ($mitre as $f) {
            $this->assertSame('threat_intel', $f['category']);
            $this->assertSame('detection-rule-registry-v1-bundled-offline', $f['source']);
        }
    }

    public function test_mitre_fixture_content_is_labelled_as_bundled_not_live_feed(): void
    {
        $fixtures = app(AiKnowledgeSeedService::class)->loadFixtures();
        $t1110 = collect($fixtures)->firstWhere('fixture_id', 'ti-mitre-t1110');

        $this->assertNotNull($t1110, 'expected a fixture for MITRE T1110');
        $this->assertStringContainsString('not a live MITRE ATT&CK API/TAXII feed or RSS ingestion', $t1110['content']);
        $this->assertStringContainsString('IDENTITY_MFA_FAILURE_BURST', $t1110['content'], 'expected content derived from this platform\'s own rule registry, not invented text');
    }

    public function test_mitre_fixtures_seed_into_knowledge_base(): void
    {
        app(AiKnowledgeSeedService::class)->seed('test', false);

        $count = DB::table('soc_knowledge_base')
            ->where('related_rule_id', 'like', 'rag-seed-ti-mitre-%')
            ->count();

        $this->assertGreaterThanOrEqual(100, $count);
    }

    // -----------------------------------------------------------------------
    // getSeededCount()
    // -----------------------------------------------------------------------

    public function test_get_seeded_count_zero_on_fresh_db(): void
    {
        $this->assertSame(0, app(AiKnowledgeSeedService::class)->getSeededCount());
    }

    public function test_get_seeded_count_after_seed(): void
    {
        app(AiKnowledgeSeedService::class)->seed('test', false);
        $this->assertGreaterThan(0, app(AiKnowledgeSeedService::class)->getSeededCount());
    }

    // -----------------------------------------------------------------------
    // getFixtures() / getSeedHistory()
    // -----------------------------------------------------------------------

    public function test_get_fixtures_returns_collection(): void
    {
        app(AiKnowledgeSeedService::class)->seed('test', false);
        $this->assertGreaterThanOrEqual(1, app(AiKnowledgeSeedService::class)->getFixtures()->count());
    }

    public function test_get_seed_history_returns_collection(): void
    {
        app(AiKnowledgeSeedService::class)->seed('test', false);
        $this->assertGreaterThanOrEqual(1, app(AiKnowledgeSeedService::class)->getSeedHistory()->count());
    }

    // -----------------------------------------------------------------------
    // Database tables
    // -----------------------------------------------------------------------

    public function test_rag_seed_fixtures_table_exists(): void
    {
        $this->assertDatabaseCount('rag_seed_fixtures', 0);
    }

    public function test_rag_seed_runs_table_exists(): void
    {
        $this->assertDatabaseCount('rag_seed_runs', 0);
    }

    public function test_rag_seed_document_log_table_exists(): void
    {
        $this->assertDatabaseCount('rag_seed_document_log', 0);
    }

    // -----------------------------------------------------------------------
    // HTTP routes
    // -----------------------------------------------------------------------

    public function test_admin_can_view_knowledge_seed_index(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/soc/ai/knowledge-seed')->assertOk();
    }

    public function test_analyst_can_view_knowledge_seed_index(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($analyst)->get('/soc/ai/knowledge-seed')->assertOk();
    }

    public function test_viewer_can_view_knowledge_seed_index(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)->get('/soc/ai/knowledge-seed')->assertOk();
    }

    public function test_unauthenticated_redirected(): void
    {
        $this->get('/soc/ai/knowledge-seed')->assertRedirect('/login');
    }

    public function test_admin_can_seed_via_http(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/soc/ai/knowledge-seed', ['dry_run' => '0'])
            ->assertRedirect(route('soc.ai.knowledge-seed.index'));
        $this->assertGreaterThan(0, DB::table('soc_knowledge_base')->count());
    }

    public function test_admin_can_dry_run_via_http(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/soc/ai/knowledge-seed', ['dry_run' => '1'])
            ->assertRedirect(route('soc.ai.knowledge-seed.index'));
        $this->assertSame(0, DB::table('soc_knowledge_base')->count());
    }

    public function test_analyst_cannot_seed_via_http(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($analyst)->post('/soc/ai/knowledge-seed', ['dry_run' => '0'])
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // Advisory-only constraints
    // -----------------------------------------------------------------------

    public function test_seed_does_not_delete_existing_knowledge_base_entries(): void
    {
        DB::table('soc_knowledge_base')->insert([
            'kb_id'            => 'pre-existing-001',
            'title'            => 'Pre-existing entry',
            'entry_type'       => 'soc_procedure',
            'content_markdown' => 'Pre-existing content',
            'created_by'       => 'test',
            'created_at'       => now()->format('Y-m-d H:i:sP'),
            'updated_at'       => now()->format('Y-m-d H:i:sP'),
        ]);
        app(AiKnowledgeSeedService::class)->seed('test', false);
        $this->assertDatabaseHas('soc_knowledge_base', ['title' => 'Pre-existing entry']);
    }
}
