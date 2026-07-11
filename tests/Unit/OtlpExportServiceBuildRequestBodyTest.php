<?php

namespace Tests\Unit;

use App\Services\OtlpExportService;
use PHPUnit\Framework\TestCase;

/**
 * OBS-OTEL-TRACING (phase 5): pure unit tests for buildRequestBody()'s
 * OTLP/HTTP+JSON wire format, mirroring the Go internal/otlpexport and
 * Python otlp_export.py test suites for the same wire shape.
 */
class OtlpExportServiceBuildRequestBodyTest extends TestCase
{
    private function sampleSpan(): array
    {
        return [
            'trace_id' => '0123456789abcdef0123456789abcdef',
            'span_id' => '0123456789abcdef',
            'parent_span_id' => 'fedcba9876543210',
            'name' => 'soc-control-plane.http_request',
            'kind' => OtlpExportService::SPAN_KIND_SERVER,
            'start_unix_nano' => 1700000000000000000,
            'end_unix_nano' => 1700000000050000000,
            'attributes' => ['tenant_id' => 'tenant-a'],
        ];
    }

    public function test_shape_matches_otlp_http_json(): void
    {
        $body = OtlpExportService::buildRequestBody('detector-soc', [$this->sampleSpan()]);

        $rs = $body['resourceSpans'][0];
        $this->assertSame('service.name', $rs['resource']['attributes'][0]['key']);
        $this->assertSame('detector-soc', $rs['resource']['attributes'][0]['value']['stringValue']);

        $scopeSpans = $rs['scopeSpans'][0];
        $this->assertSame('detector-xdr', $scopeSpans['scope']['name']);

        $span = $scopeSpans['spans'][0];
        $this->assertSame('0123456789abcdef0123456789abcdef', $span['traceId']);
        $this->assertSame('0123456789abcdef', $span['spanId']);
        $this->assertSame('fedcba9876543210', $span['parentSpanId']);
        $this->assertSame('soc-control-plane.http_request', $span['name']);
        $this->assertSame(OtlpExportService::SPAN_KIND_SERVER, $span['kind']);
        $this->assertSame('1700000000000000000', $span['startTimeUnixNano']);
        $this->assertSame('1700000000050000000', $span['endTimeUnixNano']);
    }

    public function test_omits_parent_span_id_for_root_span(): void
    {
        $root = $this->sampleSpan();
        unset($root['parent_span_id']);

        $body = OtlpExportService::buildRequestBody('detector-soc', [$root]);
        $span = $body['resourceSpans'][0]['scopeSpans'][0]['spans'][0];

        $this->assertArrayNotHasKey('parentSpanId', $span);
    }

    public function test_defaults_kind_to_internal_when_absent(): void
    {
        $span = $this->sampleSpan();
        unset($span['kind']);

        $body = OtlpExportService::buildRequestBody('detector-soc', [$span]);

        $this->assertSame(OtlpExportService::SPAN_KIND_INTERNAL, $body['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['kind']);
    }

    public function test_batches_multiple_spans_into_one_request(): void
    {
        $spans = [$this->sampleSpan(), $this->sampleSpan(), $this->sampleSpan()];

        $body = OtlpExportService::buildRequestBody('detector-soc', $spans);

        $this->assertCount(3, $body['resourceSpans'][0]['scopeSpans'][0]['spans']);
    }

    public function test_result_is_json_encodable(): void
    {
        $body = OtlpExportService::buildRequestBody('detector-soc', [$this->sampleSpan()]);
        $encoded = json_encode($body);

        $this->assertNotFalse($encoded);
        $this->assertJson($encoded);
    }

    public function test_empty_spans_produces_empty_spans_array(): void
    {
        $body = OtlpExportService::buildRequestBody('detector-soc', []);

        $this->assertSame([], $body['resourceSpans'][0]['scopeSpans'][0]['spans']);
    }
}
