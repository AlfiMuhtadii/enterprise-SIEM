<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TZ-AGENT-STALE — locks the timestamptz round-trip fix.
 *
 * The PostgreSQL server session defaulted to +07 while app.timezone is UTC, so a
 * naive timestamp written by the query builder was read back ~7h off when treated
 * as an absolute instant in PHP (e.g. now()->diffInSeconds($row->last_seen_at)).
 * Pinning the pgsql connection 'timezone' to UTC makes the round-trip faithful.
 */
class TimezoneRoundTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_db_session_timezone_is_utc(): void
    {
        $tz = DB::selectOne('show timezone');
        // property name is "TimeZone"
        $value = (array) $tz;
        $this->assertSame('UTC', reset($value));
    }

    public function test_naive_now_round_trips_within_a_minute(): void
    {
        DB::table('endpoint_agents')->insert([
            'agent_id' => 'tz-rt',
            'host_fingerprint' => 'fp-tz',
            'host_id' => 'host-tz',
            'agent_version' => '0.1.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $raw = DB::table('endpoint_agents')->where('agent_id', 'tz-rt')->value('last_seen_at');

        // Absolute diff must be small — proves no multi-hour session-offset skew.
        $this->assertLessThan(60, now()->diffInSeconds($raw, true));
    }

    public function test_recent_agent_is_not_falsely_stale(): void
    {
        $offlineAfter = (int) config('soc.agent_offline_after_seconds', 180);

        DB::table('endpoint_agents')->insert([
            'agent_id' => 'tz-fresh',
            'host_fingerprint' => 'fp-fresh',
            'host_id' => 'host-fresh',
            'agent_version' => '0.1.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $raw = DB::table('endpoint_agents')->where('agent_id', 'tz-fresh')->value('last_seen_at');
        $this->assertLessThanOrEqual($offlineAfter, now()->diffInSeconds($raw, true));
    }
}
