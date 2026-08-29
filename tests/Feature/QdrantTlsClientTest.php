<?php

namespace Tests\Feature;

use App\Services\QdrantHttpClient;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class QdrantTlsClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('xdr.infrastructure.qdrant.timeout_seconds', 7);
        Config::set('xdr.infrastructure.qdrant.verify_tls', true);
        Config::set('xdr.infrastructure.qdrant.ca_cert', '');
    }

    public function test_plain_http_keeps_backward_compatible_transport_options(): void
    {
        $options = QdrantHttpClient::request('http://qdrant:6333/healthz')->getOptions();

        $this->assertArrayNotHasKey('verify', $options);
        $this->assertSame(7, $options['timeout']);
    }

    public function test_https_uses_configured_ca_bundle(): void
    {
        $caCert = tempnam(sys_get_temp_dir(), 'qdrant-ca-');
        $this->assertNotFalse($caCert);
        Config::set('xdr.infrastructure.qdrant.ca_cert', $caCert);

        try {
            $options = QdrantHttpClient::request('https://qdrant:6333/healthz')->getOptions();
            $this->assertSame($caCert, $options['verify']);
        } finally {
            @unlink($caCert);
        }
    }

    public function test_https_fails_closed_when_configured_ca_is_missing(): void
    {
        Config::set('xdr.infrastructure.qdrant.ca_cert', '/missing/qdrant-ca.crt');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Qdrant CA certificate not found');
        QdrantHttpClient::request('https://qdrant:6333/healthz');
    }

    public function test_explicit_insecure_mode_is_visible_in_request_options(): void
    {
        Config::set('xdr.infrastructure.qdrant.verify_tls', false);

        $options = QdrantHttpClient::request('https://qdrant:6333/healthz')->getOptions();
        $this->assertFalse($options['verify']);
    }

    public function test_call_specific_timeout_is_preserved(): void
    {
        $options = QdrantHttpClient::request('http://qdrant:6333/healthz', 11)->getOptions();

        $this->assertSame(11, $options['timeout']);
    }

    public function test_non_http_scheme_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        QdrantHttpClient::request('file:///etc/passwd');
    }
}
