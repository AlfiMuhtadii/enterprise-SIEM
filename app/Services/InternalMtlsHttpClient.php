<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

final class InternalMtlsHttpClient
{
    public static function request(string $url, int $timeoutSeconds): PendingRequest
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Internal service URL must use http or https.');
        }

        $request = Http::timeout($timeoutSeconds);
        if ($scheme !== 'https' || ! config('xdr.internal_mtls.enabled', false)) {
            return $request;
        }

        $paths = [
            'CA certificate' => (string) config('xdr.internal_mtls.ca_cert'),
            'client certificate' => (string) config('xdr.internal_mtls.client_cert'),
            'client key' => (string) config('xdr.internal_mtls.client_key'),
        ];
        foreach ($paths as $label => $path) {
            if ($path === '' || ! is_file($path)) {
                throw new RuntimeException("Internal mTLS {$label} not found: {$path}");
            }
        }

        return $request->withOptions([
            'verify' => $paths['CA certificate'],
            'cert' => $paths['client certificate'],
            'ssl_key' => $paths['client key'],
        ]);
    }
}
