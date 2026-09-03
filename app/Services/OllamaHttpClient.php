<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

final class OllamaHttpClient
{
    public static function request(string $url, int $timeoutSeconds): PendingRequest
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Ollama URL must use http or https.');
        }

        $request = Http::timeout($timeoutSeconds);
        $caCert = trim((string) config('soc.ollama_ca_cert', ''));

        if ($scheme === 'http') {
            if ($caCert !== '') {
                throw new InvalidArgumentException('SOC_OLLAMA_CA_CERT requires an HTTPS Ollama URL.');
            }

            return $request;
        }

        if (! config('soc.ollama_verify_tls', true)) {
            return $request->withOptions(['verify' => false]);
        }
        if ($caCert === '') {
            return $request;
        }
        if (! is_file($caCert)) {
            throw new RuntimeException("Ollama CA certificate not found: {$caCert}");
        }

        return $request->withOptions(['verify' => $caCert]);
    }
}
