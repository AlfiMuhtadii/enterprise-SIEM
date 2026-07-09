<?php

namespace Tests\Feature;

use App\Services\StabilityEvidenceFreezeV2Service;
use App\Services\StabilityEvidenceFreezeV3Service;
use App\Services\StabilityEvidenceFreezeV4Service;
use App\Services\StabilityFreezeOverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * META-MODULE-RATIONALIZE (bounded step): the StabilityFreezeOverviewService
 * is a read-only facade over V2/V3/V4's existing, unchanged getLatestFreeze()
 * — these tests verify it never writes anything and correctly reports "never
 * run" / per-version state / the most-recent-across-all-versions summary.
 */
class StabilityFreezeOverviewTest extends TestCase
{
    use RefreshDatabase;

    private StabilityFreezeOverviewService $overview;

    protected function setUp(): void
    {
        parent::setUp();
        $this->overview = app(StabilityFreezeOverviewService::class);
    }

    public function test_overview_reports_all_versions_null_when_none_ever_run(): void
    {
        $result = $this->overview->overview();

        $this->assertNull($result['versions']['v2']);
        $this->assertNull($result['versions']['v3']);
        $this->assertNull($result['versions']['v4']);
        $this->assertNull($result['current']);
    }

    public function test_overview_is_always_advisory(): void
    {
        $result = $this->overview->overview();
        $this->assertTrue($result['is_advisory']);
    }

    public function test_overview_reports_v2_after_v2_freeze_run(): void
    {
        app(StabilityEvidenceFreezeV2Service::class)->freeze(false);

        $result = $this->overview->overview();

        $this->assertNotNull($result['versions']['v2']);
        $this->assertSame('v2', $result['versions']['v2']['summary']['freeze_version']);
        $this->assertNull($result['versions']['v3']);
        $this->assertNull($result['versions']['v4']);
    }

    public function test_current_reflects_only_version_run(): void
    {
        app(StabilityEvidenceFreezeV2Service::class)->freeze(false);

        $result = $this->overview->overview();

        $this->assertNotNull($result['current']);
        $this->assertSame('v2', $result['current']['summary']['freeze_version']);
    }

    public function test_current_picks_most_recently_frozen_version(): void
    {
        app(StabilityEvidenceFreezeV2Service::class)->freeze(false);
        // Ensure a distinct, strictly-later frozen_at timestamp than v2's.
        $this->travel(1)->minutes();
        app(StabilityEvidenceFreezeV3Service::class)->freeze(false);

        $result = $this->overview->overview();

        $this->assertSame('v3', $result['current']['summary']['freeze_version']);
    }

    public function test_all_three_versions_reported_independently_when_all_run(): void
    {
        app(StabilityEvidenceFreezeV2Service::class)->freeze(false);
        $this->travel(1)->minutes();
        app(StabilityEvidenceFreezeV3Service::class)->freeze(false);
        $this->travel(1)->minutes();
        app(StabilityEvidenceFreezeV4Service::class)->freeze(false);

        $result = $this->overview->overview();

        $this->assertSame('v2', $result['versions']['v2']['summary']['freeze_version']);
        $this->assertSame('v3', $result['versions']['v3']['summary']['freeze_version']);
        $this->assertSame('v4', $result['versions']['v4']['summary']['freeze_version']);
        $this->assertSame('v4', $result['current']['summary']['freeze_version']);
    }

    public function test_overview_never_writes_to_any_freeze_table(): void
    {
        app(StabilityEvidenceFreezeV2Service::class)->freeze(false);
        $countBefore = \Illuminate\Support\Facades\DB::table('stability_freeze_runs')->count();

        $this->overview->overview();
        $this->overview->overview();

        $countAfter = \Illuminate\Support\Facades\DB::table('stability_freeze_runs')->count();
        $this->assertSame($countBefore, $countAfter);
    }

    public function test_command_runs_successfully_with_no_freezes(): void
    {
        $this->artisan('stability:freeze-overview')
            ->expectsOutputToContain('has been run for any version yet')
            ->assertSuccessful();
    }

    public function test_command_shows_version_table_after_a_freeze(): void
    {
        app(StabilityEvidenceFreezeV2Service::class)->freeze(false);

        $this->artisan('stability:freeze-overview')
            ->expectsOutputToContain('Most recent freeze overall')
            ->assertSuccessful();
    }
}
