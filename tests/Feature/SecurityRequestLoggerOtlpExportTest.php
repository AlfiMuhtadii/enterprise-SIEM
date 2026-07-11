<?php

namespace Tests\Feature;

use App\Services\TraceparentService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OBS-OTEL-TRACING (phase 5): SecurityRequestLogger::terminate() actually
 * emits an OTLP span for the SOC control-plane hop, using Laravel's
 * terminable-middleware mechanism (called after the response is sent).
 */
class SecurityRequestLoggerOtlpExportTest extends TestCase
{
    public function test_no_export_when_endpoint_unconfigured(): void
    {
        Http::fake();
        config(['xdr.otel_exporter_endpoint' => '']);

        $this->get('/');

        Http::assertNothingSent();
    }

    public function test_exports_a_span_when_endpoint_configured(): void
    {
        Http::fake(['http://collector/v1/traces' => Http::response(['ok' => true], 200)]);
        config(['xdr.otel_exporter_endpoint' => 'http://collector/v1/traces']);

        $this->get('/');

        Http::assertSent(function ($request) {
            return $request->url() === 'http://collector/v1/traces'
                && count($request['resourceSpans'][0]['scopeSpans'][0]['spans']) === 1;
        });
    }

    public function test_exported_span_trace_id_matches_response_traceparent(): void
    {
        Http::fake(['http://collector/v1/traces' => Http::response(['ok' => true], 200)]);
        config(['xdr.otel_exporter_endpoint' => 'http://collector/v1/traces']);

        $response = $this->get('/');
        $outParsed = TraceparentService::parse($response->headers->get('Traceparent'));

        Http::assertSent(function ($request) use ($outParsed) {
            $span = $request['resourceSpans'][0]['scopeSpans'][0]['spans'][0];

            return $span['traceId'] === $outParsed['trace_id']
                && $span['spanId'] === $outParsed['span_id'];
        });
    }

    public function test_exported_span_parent_matches_inbound_traceparent(): void
    {
        Http::fake(['http://collector/v1/traces' => Http::response(['ok' => true], 200)]);
        config(['xdr.otel_exporter_endpoint' => 'http://collector/v1/traces']);

        $inbound = TraceparentService::generate();
        $inboundParsed = TraceparentService::parse($inbound);

        $this->withHeaders(['traceparent' => $inbound])->get('/');

        Http::assertSent(function ($request) use ($inboundParsed) {
            $span = $request['resourceSpans'][0]['scopeSpans'][0]['spans'][0];

            return $span['parentSpanId'] === $inboundParsed['span_id']
                && $span['traceId'] === $inboundParsed['trace_id'];
        });
    }

    public function test_export_failure_does_not_break_the_response(): void
    {
        Http::fake(['http://collector/v1/traces' => fn () => throw new \Illuminate\Http\Client\ConnectionException('refused')]);
        config(['xdr.otel_exporter_endpoint' => 'http://collector/v1/traces']);

        $response = $this->get('/');

        $response->assertOk();
    }
}
