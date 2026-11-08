<?php

namespace Tests\Feature;

use App\Services\OtlpExportService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OBS-OTEL-TRACING (phase 5): export()'s real HTTP behavior against a faked
 * collector endpoint — no-op when disabled, sends a real POST when
 * configured, and never throws (best-effort) when the collector fails.
 */
class OtlpExportServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('xdr.otel_ca_cert', '');
    }

    private function sampleSpan(): array
    {
        return [
            'trace_id' => '0123456789abcdef0123456789abcdef',
            'span_id' => '0123456789abcdef',
            'name' => 'soc-control-plane.http_request',
            'start_unix_nano' => 1700000000000000000,
            'end_unix_nano' => 1700000000050000000,
        ];
    }

    public function test_noop_when_endpoint_blank(): void
    {
        Http::fake();

        OtlpExportService::export('', 'detector-soc', [$this->sampleSpan()]);

        Http::assertNothingSent();
    }

    public function test_noop_when_spans_empty(): void
    {
        Http::fake();

        OtlpExportService::export('http://collector/v1/traces', 'detector-soc', []);

        Http::assertNothingSent();
    }

    public function test_sends_real_post_to_configured_endpoint(): void
    {
        Http::fake(['http://collector/v1/traces' => Http::response(['ok' => true], 200)]);

        OtlpExportService::export('http://collector/v1/traces', 'detector-soc', [$this->sampleSpan()]);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://collector/v1/traces'
                && $request['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['traceId'] === '0123456789abcdef0123456789abcdef';
        });
    }

    public function test_does_not_throw_when_collector_unreachable(): void
    {
        Http::fake(['http://collector/v1/traces' => fn () => throw new \Illuminate\Http\Client\ConnectionException('refused')]);

        // Must not throw — the caller (SecurityRequestLogger) never wraps this in try/catch.
        OtlpExportService::export('http://collector/v1/traces', 'detector-soc', [$this->sampleSpan()]);

        $this->assertTrue(true);
    }

    public function test_does_not_throw_on_non_2xx_response(): void
    {
        Http::fake(['http://collector/v1/traces' => Http::response('service unavailable', 503)]);

        OtlpExportService::export('http://collector/v1/traces', 'detector-soc', [$this->sampleSpan()]);

        $this->assertTrue(true);
    }

    public function test_https_request_uses_configured_private_ca(): void
    {
        $caCert = tempnam(sys_get_temp_dir(), 'otlp-ca-');
        $this->assertNotFalse($caCert);
        Config::set('xdr.otel_ca_cert', $caCert);

        try {
            $options = OtlpExportService::request('https://collector/v1/traces', 3)->getOptions();
            $this->assertSame($caCert, $options['verify']);
        } finally {
            @unlink($caCert);
        }
    }

    public function test_ca_with_plaintext_endpoint_fails_before_network_but_remains_nonfatal(): void
    {
        Config::set('xdr.otel_ca_cert', '/configured/collector-ca.crt');
        Http::fake();

        OtlpExportService::export('http://collector/v1/traces', 'detector-soc', [$this->sampleSpan()]);

        Http::assertNothingSent();
    }
}
