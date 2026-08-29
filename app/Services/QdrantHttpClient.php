<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

final class QdrantHttpClient
{
    public static function request(string $url, ?int $timeoutSeconds = null): PendingRequest
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Qdrant URL must use http or https.');
        }

        $request = Http::timeout($timeoutSeconds ?? (int) config('xdr.infrastructure.qdrant.timeout_seconds', 5));

        if ($scheme !== 'https') {
            return $request;
        }

        if (! config('xdr.infrastructure.qdrant.verify_tls', true)) {
            return $request->withOptions(['verify' => false]);
        }

        $caCert = trim((string) config('xdr.infrastructure.qdrant.ca_cert', ''));
        if ($caCert === '') {
            return $request;
        }
        if (! is_file($caCert)) {
            throw new RuntimeException("Qdrant CA certificate not found: {$caCert}");
        }

        return $request->withOptions(['verify' => $caCert]);
    }
}
