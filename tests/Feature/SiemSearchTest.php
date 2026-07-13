<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SiemSearchService;
use App\Support\Rbac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Traits\AssertsAdvisoryOnlyConstraints;
use Tests\TestCase;

class SiemSearchTest extends TestCase
{
    use RefreshDatabase, AssertsAdvisoryOnlyConstraints;

    private SiemSearchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SiemSearchService::class);
    }

    protected function getAdvisoryServiceClass(): string
    {
        return SiemSearchService::class;
    }

    private function seedAlert(array $overrides = []): void
    {
        DB::table('security_alerts')->insert(array_merge([
            'alert_id' => 'alert-'.uniqid(),
            'detected_at' => now(),
            'alert_type' => 'BRUTE_FORCE',
            'detector_name' => 'TEST',
            'detector_version' => 'v1',
            'severity' => 'high',
            'ip' => '10.1.1.1',
            'tenant_id' => 't1',
            'score' => 0.9,
            'evidence' => json_encode(['note' => 'suspicious login', 'password' => 'hunter2']),
            'raw_event' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    // =========================================================================
    // Query validation / bounds
    // =========================================================================

    public function test_query_below_min_length_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->search('t1', 'a');
    }

    /** SIEM-QUERYSTRING-DOS: no unbounded-length query reaches OpenSearch. */
    public function test_query_above_max_length_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->search('t1', str_repeat('a', SiemSearchService::MAX_QUERY_LENGTH + 1));
    }

    /** SIEM-QUERYSTRING-DOS: simple_query_string has no leading-wildcard/regex DoS surface. */
    public function test_opensearch_request_uses_simple_query_string_with_timeout(): void
    {
        Http::fake(['*' => Http::response(['hits' => ['hits' => []]], 200)]);

        $this->service->search('t1', 'brute');

        Http::assertSent(function ($request) {
            $body = $request->data();
            $hasSimpleQueryString = isset($body['query']['bool']['must'][0]['simple_query_string']);
            $hasNoLegacyQueryString = !isset($body['query']['bool']['must'][0]['query_string']);
            $hasTimeout = isset($body['timeout']);

            return $hasSimpleQueryString && $hasNoLegacyQueryString && $hasTimeout;
        });
    }

    public function test_max_results_is_clamped_to_bound(): void
    {
        Http::fake(['*' => Http::response(['hits' => ['hits' => []]], 200)]);
        // No exception thrown even when requesting far above the bound.
        $result = $this->service->search('t1', 'brute', SiemSearchService::MAX_RESULTS + 500);
        $this->assertSame('opensearch', $result['source']);
    }

    public function test_window_days_is_clamped_to_bound(): void
    {
        Http::fake(['*' => Http::response(['hits' => ['hits' => []]], 200)]);
        $result = $this->service->search('t1', 'brute', null, SiemSearchService::MAX_QUERY_WINDOW_DAYS + 100);
        $this->assertSame(SiemSearchService::MAX_QUERY_WINDOW_DAYS, $result['window_days']);
    }

    // =========================================================================
    // OpenSearch path
    // =========================================================================

    public function test_opensearch_success_returns_redacted_results(): void
    {
        Http::fake([
            '*' => Http::response([
                'hits' => [
                    'hits' => [
                        ['_source' => [
                            'alert_type' => 'BRUTE_FORCE',
                            'severity' => 'high',
                            'ip' => '10.1.1.1',
                            'detected_at' => now()->toIso8601String(),
                            'detector_name' => 'TEST',
                            'evidence' => ['note' => 'x', 'password' => 'hunter2'],
                        ]],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->search('t1', 'brute');

        $this->assertSame('opensearch', $result['source']);
        $this->assertSame(1, $result['total']);
        $row = $result['results']->first();
        $this->assertSame('BRUTE_FORCE', $row->alert_type);
        $this->assertSame(\App\Support\TraceRedactor::REDACTED, $row->evidence['password']);
    }

    /** ENT-SEC-OPENSEARCH-OPEN: verify_tls config wiring. */
    public function test_opensearch_verify_tls_defaults_true(): void
    {
        $this->assertTrue(config('xdr.infrastructure.opensearch.verify_tls'));
    }

    public function test_opensearch_verify_tls_disabled_does_not_break_search(): void
    {
        config(['xdr.infrastructure.opensearch.verify_tls' => false]);
        Http::fake(['*' => Http::response(['hits' => ['hits' => []]], 200)]);

        $result = $this->service->search('t1', 'brute');

        $this->assertSame('opensearch', $result['source']);
    }

    public function test_opensearch_failure_falls_back_to_postgres(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);
        $this->seedAlert(['alert_type' => 'BRUTE_FORCE_XYZ']);

        $result = $this->service->search('t1', 'BRUTE_FORCE_XYZ');

        $this->assertSame('postgres_fallback', $result['source']);
        $this->assertSame(1, $result['total']);
    }

    public function test_opensearch_exception_falls_back_to_postgres(): void
    {
        Http::fake(['*' => function () {
            throw new ConnectionException('refused');
        }]);
        $this->seedAlert(['alert_type' => 'BRUTE_FORCE_ABC']);

        $result = $this->service->search('t1', 'BRUTE_FORCE_ABC');

        $this->assertSame('postgres_fallback', $result['source']);
        $this->assertSame(1, $result['total']);
    }

    // =========================================================================
    // Postgres fallback behavior
    // =========================================================================

    public function test_postgres_fallback_matches_alert_type(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);
        $this->seedAlert(['alert_type' => 'CREDENTIAL_STUFFING']);
        $this->seedAlert(['alert_type' => 'UNRELATED_TYPE']);

        $result = $this->service->search('t1', 'CREDENTIAL');

        $this->assertSame(1, $result['total']);
    }

    public function test_postgres_fallback_matches_evidence_json(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);
        $this->seedAlert(['evidence' => json_encode(['note' => 'rare-marker-xyz'])]);

        $result = $this->service->search('t1', 'rare-marker-xyz');

        $this->assertSame(1, $result['total']);
    }

    public function test_postgres_fallback_redacts_sensitive_evidence_fields(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);
        $this->seedAlert(['alert_type' => 'REDACT_TEST_TYPE']);

        $result = $this->service->search('t1', 'REDACT_TEST_TYPE');
        $row = $result['results']->first();

        $this->assertSame(\App\Support\TraceRedactor::REDACTED, $row->evidence['password']);
    }

    public function test_postgres_fallback_is_tenant_scoped(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);
        $this->seedAlert(['alert_type' => 'TENANT_SCOPE_TEST', 'tenant_id' => 'tenant-a']);

        $result = $this->service->search('tenant-b', 'TENANT_SCOPE_TEST');

        $this->assertSame(0, $result['total']);
    }

    public function test_postgres_fallback_respects_window_days(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);
        $this->seedAlert(['alert_type' => 'OLD_ALERT_TYPE', 'detected_at' => now()->subDays(60)]);

        $result = $this->service->search('t1', 'OLD_ALERT_TYPE', null, 30);

        $this->assertSame(0, $result['total']);
    }

    // =========================================================================
    // RBAC
    // =========================================================================

    public function test_admin_has_search_view_permission(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertTrue(Rbac::can($admin, 'search.view'));
    }

    public function test_analyst_has_search_view_permission(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->assertTrue(Rbac::can($analyst, 'search.view'));
    }

    public function test_viewer_has_search_view_permission(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->assertTrue(Rbac::can($viewer, 'search.view'));
    }

    // =========================================================================
    // Routes
    // =========================================================================

    public function test_index_route_requires_auth(): void
    {
        $this->get('/siem-search')->assertRedirect('/login');
    }

    public function test_index_route_accessible_to_viewer_with_empty_query(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)->get('/siem-search')->assertOk();
    }

    public function test_index_route_renders_results_for_query(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->seedAlert(['alert_type' => 'ROUTE_SEARCH_TEST']);

        $this->actingAs($viewer)
            ->get('/siem-search?q=ROUTE_SEARCH_TEST')
            ->assertOk()
            ->assertSee('ROUTE_SEARCH_TEST');
    }

    public function test_index_route_shows_error_for_too_short_query(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)
            ->get('/siem-search?q=a')
            ->assertOk()
            ->assertSee('at least');
    }

    // =========================================================================
    // PERF-SIEM-FALLBACK-SCAN: index-level fix so ILIKE fallback search
    // doesn't force a full sequential scan under enterprise alert volume.
    // The performance claim itself is verified separately via a live
    // EXPLAIN ANALYZE benchmark (not a CI-run test -- seeding enough rows
    // for the planner to naturally prefer an index plan would make this
    // test slow and flaky); this test only proves the schema migration
    // itself landed correctly.
    // =========================================================================

    public function test_pg_trgm_extension_and_indexes_exist_for_all_ilike_columns(): void
    {
        $extension = DB::selectOne("SELECT extname FROM pg_extension WHERE extname = 'pg_trgm'");
        $this->assertNotNull($extension, 'pg_trgm extension must be installed');

        $indexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'security_alerts'"))
            ->pluck('indexname');

        foreach ([
            'security_alerts_tenant_detected_idx',
            'security_alerts_evidence_trgm_idx',
            'security_alerts_raw_event_trgm_idx',
            'security_alerts_alert_type_trgm_idx',
            'security_alerts_ip_trgm_idx',
            'security_alerts_detector_name_trgm_idx',
        ] as $expected) {
            $this->assertTrue($indexes->contains($expected), "missing index: {$expected}");
        }
    }
}
