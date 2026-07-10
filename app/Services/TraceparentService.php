<?php

namespace App\Services;

/**
 * OBS-OTEL-TRACING (phase 3): W3C Trace Context (level 1) generation,
 * parsing, and hop-to-hop propagation for the Laravel control plane.
 *
 * Mirrors the Go "internal/traceparent" packages and the Python
 * "traceparent.py" modules algorithmically, so trace continuity survives
 * into the one hop those two didn't reach: the analyst-facing SOC control
 * plane. Additive to the platform's existing free-form trace_id lineage
 * field, not a replacement — same reasoning as the pipeline-side
 * implementations.
 */
class TraceparentService
{
    private const PATTERN = '/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/';

    private const ZERO_TRACE_ID = '00000000000000000000000000000000';

    private const ZERO_SPAN_ID = '0000000000000000';

    /**
     * Returns a new root W3C traceparent: version 00, fresh trace-id/span-id, sampled flag.
     */
    public static function generate(): string
    {
        return sprintf('00-%s-%s-01', bin2hex(random_bytes(16)), bin2hex(random_bytes(8)));
    }

    /**
     * Validates and decomposes a traceparent header value per the level-1
     * spec (version "00"; 32-hex trace-id and 16-hex span-id, neither
     * all-zero; 2-hex flags). Returns null for anything invalid or absent.
     *
     * @return array{trace_id: string, span_id: string, flags: string}|null
     */
    public static function parse(?string $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!preg_match(self::PATTERN, $value, $m)) {
            return null;
        }
        [, $traceId, $spanId, $flags] = $m;
        if ($traceId === self::ZERO_TRACE_ID || $spanId === self::ZERO_SPAN_ID) {
            return null;
        }

        return ['trace_id' => $traceId, 'span_id' => $spanId, 'flags' => $flags];
    }

    /**
     * Returns a traceparent string carrying the same trace-id but a
     * freshly generated span-id, representing this hop's span.
     *
     * @param  array{trace_id: string, span_id: string, flags: string}  $parsed
     */
    public static function newChildSpan(array $parsed): string
    {
        return sprintf('00-%s-%s-%s', $parsed['trace_id'], bin2hex(random_bytes(8)), $parsed['flags']);
    }

    /**
     * Parses an inbound traceparent (if any) and returns a child-span
     * traceparent for this hop. An empty or invalid inbound value never
     * blocks propagation — a fresh root traceparent is generated instead.
     */
    public static function propagate(?string $inbound): string
    {
        $parsed = self::parse($inbound);
        if ($parsed !== null) {
            return self::newChildSpan($parsed);
        }

        return self::generate();
    }
}
