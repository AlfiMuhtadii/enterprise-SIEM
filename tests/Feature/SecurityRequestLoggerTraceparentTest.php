<?php

namespace Tests\Feature;

use App\Services\TraceparentService;
use Tests\TestCase;

/**
 * OBS-OTEL-TRACING (phase 3): verifies SecurityRequestLogger propagates a
 * W3C traceparent on every response — a new child span if the request
 * carried a valid inbound one, otherwise a fresh root — regardless of
 * whether the request is otherwise ignored by the security detector.
 */
class SecurityRequestLoggerTraceparentTest extends TestCase
{
    public function test_response_carries_a_valid_generated_traceparent_when_none_sent(): void
    {
        $response = $this->get('/');

        $traceparent = $response->headers->get('Traceparent');
        $this->assertNotNull($traceparent);
        $this->assertNotNull(TraceparentService::parse($traceparent));
    }

    public function test_response_propagates_a_child_span_of_the_inbound_traceparent(): void
    {
        $inbound = TraceparentService::generate();
        $inboundParsed = TraceparentService::parse($inbound);

        $response = $this->withHeaders(['traceparent' => $inbound])->get('/');

        $outParsed = TraceparentService::parse($response->headers->get('Traceparent'));
        $this->assertNotNull($outParsed);
        $this->assertSame($inboundParsed['trace_id'], $outParsed['trace_id']);
        $this->assertNotSame($inboundParsed['span_id'], $outParsed['span_id']);
    }

    public function test_invalid_inbound_traceparent_still_yields_a_valid_response_header(): void
    {
        $response = $this->withHeaders(['traceparent' => 'not-a-real-traceparent'])->get('/');

        $this->assertNotNull(TraceparentService::parse($response->headers->get('Traceparent')));
    }

    public function test_traceparent_header_present_even_on_internal_ignored_paths(): void
    {
        // Traceparent propagation is a cross-cutting concern independent of
        // the security-detector's authenticated-internal-path exclusion —
        // it must still be set even when SecurityLogger::log() is skipped.
        $response = $this->get('/soc');

        $this->assertNotNull($response->headers->get('Traceparent'));
    }
}
