<?php

namespace Tests\Feature;

use App\Services\InternalMtlsHttpClient;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class InternalMtlsHttpClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('xdr.internal_mtls.enabled', false);
    }

    public function test_http_keeps_backward_compatible_transport(): void
    {
        $options = InternalMtlsHttpClient::request('http://ai-rag-service:8094/health', 2)->getOptions();
        $this->assertSame(2, $options['timeout']);
        $this->assertArrayNotHasKey('cert', $options);
    }

    public function test_https_uses_ca_and_client_identity_when_enabled(): void
    {
        $files = [tempnam(sys_get_temp_dir(), 'ca-'), tempnam(sys_get_temp_dir(), 'cert-'), tempnam(sys_get_temp_dir(), 'key-')];
        $this->assertNotContains(false, $files);
        Config::set('xdr.internal_mtls.enabled', true);
        Config::set('xdr.internal_mtls.ca_cert', $files[0]);
        Config::set('xdr.internal_mtls.client_cert', $files[1]);
        Config::set('xdr.internal_mtls.client_key', $files[2]);

        try {
            $options = InternalMtlsHttpClient::request('https://ai-rag-service:8094/health', 2)->getOptions();
            $this->assertSame($files[0], $options['verify']);
            $this->assertSame($files[1], $options['cert']);
            $this->assertSame($files[2], $options['ssl_key']);
        } finally {
            array_map(static fn ($file) => @unlink($file), $files);
        }
    }

    public function test_enabled_mtls_fails_closed_when_identity_is_missing(): void
    {
        Config::set('xdr.internal_mtls.enabled', true);
        Config::set('xdr.internal_mtls.ca_cert', '/missing/ca.crt');

        $this->expectException(RuntimeException::class);
        InternalMtlsHttpClient::request('https://ai-rag-service:8094/health', 2);
    }

    public function test_non_http_scheme_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        InternalMtlsHttpClient::request('file:///etc/passwd', 2);
    }
}
