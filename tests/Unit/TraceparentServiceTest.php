<?php

namespace Tests\Unit;

use App\Services\TraceparentService;
use PHPUnit\Framework\TestCase;

/**
 * OBS-OTEL-TRACING (phase 3): pure unit tests for the Laravel-side W3C
 * Trace Context implementation, mirroring the Go/Python test suites for
 * the same algorithm (generate/parse/child-span/propagate).
 */
class TraceparentServiceTest extends TestCase
{
    public function test_generate_produces_valid_traceparent(): void
    {
        $value = TraceparentService::generate();
        $parsed = TraceparentService::parse($value);

        $this->assertNotNull($parsed);
        $this->assertSame(32, strlen($parsed['trace_id']));
        $this->assertSame(16, strlen($parsed['span_id']));
        $this->assertSame('01', $parsed['flags']);
    }

    public function test_generate_produces_unique_values(): void
    {
        $this->assertNotSame(TraceparentService::generate(), TraceparentService::generate());
    }

    public function test_parse_rejects_null(): void
    {
        $this->assertNull(TraceparentService::parse(null));
    }

    public function test_parse_rejects_empty_string(): void
    {
        $this->assertNull(TraceparentService::parse(''));
    }

    public function test_parse_rejects_wrong_version(): void
    {
        $bad = '01-'.str_repeat('a', 32).'-'.str_repeat('b', 16).'-01';
        $this->assertNull(TraceparentService::parse($bad));
    }

    public function test_parse_rejects_wrong_lengths(): void
    {
        $cases = [
            '00-abc-'.str_repeat('b', 16).'-01',
            '00-'.str_repeat('a', 32).'-abc-01',
            '00-'.str_repeat('a', 32).'-'.str_repeat('b', 16).'-1',
            'not-a-traceparent-at-all',
        ];
        foreach ($cases as $case) {
            $this->assertNull(TraceparentService::parse($case), "expected null for {$case}");
        }
    }

    public function test_parse_rejects_all_zero_trace_id(): void
    {
        $bad = '00-'.str_repeat('0', 32).'-'.str_repeat('b', 16).'-01';
        $this->assertNull(TraceparentService::parse($bad));
    }

    public function test_parse_rejects_all_zero_span_id(): void
    {
        $bad = '00-'.str_repeat('a', 32).'-'.str_repeat('0', 16).'-01';
        $this->assertNull(TraceparentService::parse($bad));
    }

    public function test_parse_rejects_uppercase_hex(): void
    {
        $bad = '00-'.str_repeat('A', 32).'-'.str_repeat('B', 16).'-01';
        $this->assertNull(TraceparentService::parse($bad));
    }

    public function test_new_child_span_preserves_trace_id_changes_span_id(): void
    {
        $root = TraceparentService::parse(TraceparentService::generate());
        $child = TraceparentService::parse(TraceparentService::newChildSpan($root));

        $this->assertSame($root['trace_id'], $child['trace_id']);
        $this->assertNotSame($root['span_id'], $child['span_id']);
    }

    public function test_propagate_with_valid_inbound_creates_child_span(): void
    {
        $inbound = TraceparentService::generate();
        $inboundParsed = TraceparentService::parse($inbound);
        $outParsed = TraceparentService::parse(TraceparentService::propagate($inbound));

        $this->assertNotNull($outParsed);
        $this->assertSame($inboundParsed['trace_id'], $outParsed['trace_id']);
        $this->assertNotSame($inboundParsed['span_id'], $outParsed['span_id']);
    }

    public function test_propagate_with_null_inbound_generates_root(): void
    {
        $this->assertNotNull(TraceparentService::parse(TraceparentService::propagate(null)));
    }

    public function test_propagate_with_invalid_inbound_generates_root(): void
    {
        $this->assertNotNull(TraceparentService::parse(TraceparentService::propagate('garbage-not-a-traceparent')));
    }
}
