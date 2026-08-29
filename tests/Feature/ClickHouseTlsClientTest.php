<?php

namespace Tests\Feature;

use App\Services\ClickHouseHttpClient;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

class ClickHouseTlsClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('xdr.infrastructure.clickhouse.user', 'detector');
        Config::set('xdr.infrastructure.clickhouse.password', 'secret');
        Config::set('xdr.infrastructure.clickhouse.timeout_seconds', 7);
        Config::set('xdr.infrastructure.clickhouse.verify_tls', true);
        Config::set('xdr.infrastructure.clickhouse.ca_cert', '');
    }

    public function test_plain_http_keeps_backward_compatible_transport_options(): void
    {
        $options = ClickHouseHttpClient::request('http://clickhouse:8123/ping')->getOptions();

        $this->assertArrayNotHasKey('verify', $options);
        $this->assertSame(7, $options['timeout']);
        $this->assertSame(['detector', 'secret'], $options['auth']);
    }

    public function test_https_uses_configured_ca_bundle(): void
    {
        $caCert = tempnam(sys_get_temp_dir(), 'clickhouse-ca-');
        $this->assertNotFalse($caCert);
        Config::set('xdr.infrastructure.clickhouse.ca_cert', $caCert);

        try {
            $options = ClickHouseHttpClient::request('https://clickhouse:8443/ping')->getOptions();
            $this->assertSame($caCert, $options['verify']);
        } finally {
            @unlink($caCert);
        }
    }

    public function test_https_fails_closed_when_configured_ca_is_missing(): void
    {
        Config::set('xdr.infrastructure.clickhouse.ca_cert', '/missing/clickhouse-ca.crt');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ClickHouse CA certificate not found');
        ClickHouseHttpClient::request('https://clickhouse:8443/ping');
    }

    public function test_explicit_insecure_mode_is_visible_in_request_options(): void
    {
        Config::set('xdr.infrastructure.clickhouse.verify_tls', false);

        $options = ClickHouseHttpClient::request('https://clickhouse:8443/ping')->getOptions();
        $this->assertFalse($options['verify']);
    }

    public function test_non_http_scheme_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClickHouseHttpClient::request('file:///etc/passwd');
    }
}
