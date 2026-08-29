<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

final class ClickHouseHttpClient
{
    public static function request(string $url): PendingRequest
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('ClickHouse URL must use http or https.');
        }

        $request = Http::timeout((int) config('xdr.infrastructure.clickhouse.timeout_seconds', 5))
            ->withBasicAuth(
                (string) config('xdr.infrastructure.clickhouse.user'),
                (string) config('xdr.infrastructure.clickhouse.password'),
            );

        if ($scheme !== 'https') {
            return $request;
        }

        if (! config('xdr.infrastructure.clickhouse.verify_tls', true)) {
            return $request->withOptions(['verify' => false]);
        }

        $caCert = trim((string) config('xdr.infrastructure.clickhouse.ca_cert', ''));
        if ($caCert === '') {
            return $request;
        }
        if (! is_file($caCert)) {
            throw new RuntimeException("ClickHouse CA certificate not found: {$caCert}");
        }

        return $request->withOptions(['verify' => $caCert]);
    }
}
