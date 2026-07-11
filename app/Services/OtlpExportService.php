<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * OBS-OTEL-TRACING (phase 5): OTLP/HTTP+JSON span exporter for the Laravel
 * SOC control plane, matching the Go `internal/otlpexport` and Python
 * `otlp_export.py` implementations in wire format: trace_id/span_id/
 * parent_span_id are lowercase hex strings (the OTLP spec's documented
 * JSON-encoding special case, not the default base64 protobuf-JSON
 * mapping) — exactly what TraceparentService already produces, so no
 * re-encoding is needed.
 *
 * Unlike the Go/Python hot pipeline hops (fire-and-forget in a goroutine/
 * background thread), this control-plane hop's export call is synchronous
 * with a short bounded timeout. PHP-FPM has no native background-thread
 * primitive, and this codebase's default `QUEUE_CONNECTION=sync` means
 * dispatching a queued job would run inline anyway — not actually async.
 * Given export is disabled by default and, when enabled, only affects a
 * low-volume analyst-facing control-plane request (not the hot ingestion
 * path every other hop protects), a short bounded synchronous call is an
 * honest, explicitly-documented tradeoff — errors and timeouts are always
 * swallowed here, never surfaced to the end user or the request/response
 * cycle.
 */
class OtlpExportService
{
    public const SPAN_KIND_INTERNAL = 1;

    public const SPAN_KIND_SERVER = 2;

    /**
     * Pure function producing the OTLP/HTTP+JSON ExportTraceServiceRequest
     * body as a PHP array (Laravel's Http client JSON-encodes an array body
     * automatically) — split out from export() so the wire format can be
     * unit tested directly without a real HTTP round trip.
     *
     * @param  array<int, array{trace_id: string, span_id: string, name: string, start_unix_nano: int, end_unix_nano: int, kind?: int, parent_span_id?: string, attributes?: array<string, string>}>  $spans
     */
    public static function buildRequestBody(string $serviceName, array $spans): array
    {
        $otlpSpans = [];
        foreach ($spans as $span) {
            $otlpSpan = [
                'traceId' => $span['trace_id'],
                'spanId' => $span['span_id'],
                'name' => $span['name'],
                'kind' => $span['kind'] ?? self::SPAN_KIND_INTERNAL,
                'startTimeUnixNano' => (string) $span['start_unix_nano'],
                'endTimeUnixNano' => (string) $span['end_unix_nano'],
            ];
            if (! empty($span['parent_span_id'])) {
                $otlpSpan['parentSpanId'] = $span['parent_span_id'];
            }
            if (! empty($span['attributes'])) {
                $attributes = [];
                foreach ($span['attributes'] as $key => $value) {
                    $attributes[] = ['key' => $key, 'value' => ['stringValue' => (string) $value]];
                }
                $otlpSpan['attributes'] = $attributes;
            }
            $otlpSpans[] = $otlpSpan;
        }

        return [
            'resourceSpans' => [[
                'resource' => [
                    'attributes' => [
                        ['key' => 'service.name', 'value' => ['stringValue' => $serviceName]],
                    ],
                ],
                'scopeSpans' => [[
                    'scope' => ['name' => 'detector-xdr'],
                    'spans' => $otlpSpans,
                ]],
            ]],
        ];
    }

    /**
     * Sends spans as a single OTLP ExportTraceServiceRequest. A blank
     * endpoint or empty spans array is a no-op. Never throws — a slow or
     * unreachable collector must never break the request it's describing.
     *
     * @param  array<int, array<string, mixed>>  $spans
     */
    public static function export(string $endpoint, string $serviceName, array $spans, int $timeoutSeconds = 2): void
    {
        if ($endpoint === '' || $spans === []) {
            return;
        }

        try {
            Http::timeout($timeoutSeconds)->post($endpoint, self::buildRequestBody($serviceName, $spans));
        } catch (Throwable $e) {
            // Best-effort: intentionally swallowed, matching the Go/Python exporters'
            // "observability must never break the system it observes" posture.
        }
    }
}
