<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    public function __construct()
    {
        $this->proxies = $this->resolveTrustedProxies();
    }

    /**
     * The trusted proxies for this application.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies;

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_AWS_ELB;

    /**
     * @return array<int, string>|string|null
     */
    private function resolveTrustedProxies(): array|string|null
    {
        // ENV-CACHE-DRIFT-BATCH: read via config so a custom TRUSTED_PROXIES value
        // survives `php artisan config:cache` (env() would return the default there).
        $value = (string) config('xdr.trusted_proxies', '127.0.0.1,::1');
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if ($value === '*') {
            return '*';
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $value))));

        return $parts === [] ? null : $parts;
    }
}
