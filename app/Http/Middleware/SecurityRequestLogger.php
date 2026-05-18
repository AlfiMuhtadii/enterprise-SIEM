<?php

namespace App\Http\Middleware;

use App\Services\SecurityLogger;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SecurityRequestLogger
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('security_detector.enabled', true)) {
            return $next($request);
        }

        $requestId = (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);
        $request->attributes->set('request_start', microtime(true));

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $handler = app(ExceptionHandler::class);
            $handler->report($e);
            $response = $handler->render($request, $e);
        }

        $response->headers->set('X-Request-Id', $requestId);

        $path = '/' . ltrim($request->path(), '/');
        if ($this->shouldIgnore($request)) {
            return $response;
        }

        [$queryHash, $hasSqlKeywords, $hasScriptPayload] = $this->queryThreatSignals($request);

        SecurityLogger::log('http_request', [
            'request_id' => $requestId,
            'ip' => $request->ip(),
            'user_agent_hash' => SecurityLogger::hashValue($request->userAgent()),
            'user_id' => optional($request->user())->id,
            'email_hash' => null,
            'method' => $request->method(),
            'path' => $path,
            'status' => $response->getStatusCode(),
            'latency_ms' => SecurityLogger::latencyMs(),
            'query_hash' => $queryHash,
            'has_sql_keywords' => $hasSqlKeywords,
            'has_script_payload' => $hasScriptPayload,
        ]);

        return $response;
    }

    private function shouldIgnore(Request $request): bool
    {
        // Authenticated users navigating the SOC / admin UI are known actors.
        // Their page visits must not feed the threat detector — doing so produces
        // false-positive ANOMALY_BEHAVIOR and EXPLOIT_CHAIN_SUSPECTED alerts
        // every time an analyst opens /security/alerts or /soc.
        if ($request->user() !== null && $this->isInternalUiPath($request)) {
            return true;
        }

        foreach ((array) config('security_detector.ignored_paths', []) as $pattern) {
            if (is_string($pattern) && $pattern !== '' && $request->is($pattern)) {
                return true;
            }
        }

        return false;
    }

    private function isInternalUiPath(Request $request): bool
    {
        $internalPrefixes = [
            'soc', 'soc/*',
            'security/alerts', 'security/alerts*',
            'scenario', 'scenario/*',
            'admin', 'admin/*',
            'profile', 'profile/*',
            'dashboard',
        ];

        foreach ($internalPrefixes as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }

    private function shouldCaptureQuery(Request $request): bool
    {
        foreach ((array) config('security_detector.capture_query_paths', []) as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            $normalized = ltrim($pattern, '/');
            if ($request->is($pattern) || $request->is($normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Inspect decoded query values as well as the raw query string. Browsers send
     * XSS/SQLi probes URL-encoded, while http_build_query() would re-encode them.
     */
    private function queryThreatSignals(Request $request): array
    {
        if (! $this->shouldCaptureQuery($request) || $request->query->count() === 0) {
            return [null, false, false];
        }

        $query = http_build_query($request->query->all(), '', '&', PHP_QUERY_RFC3986);
        if ($query === '') {
            $query = json_encode($request->query->all(), JSON_UNESCAPED_SLASHES) ?: '';
        }

        $rawQuery = (string) ($request->server->get('QUERY_STRING') ?? '');
        $decodedRaw = rawurldecode($rawQuery);
        $decodedValues = implode(' ', $this->flattenQueryValues($request->query->all()));
        $inspect = $query . ' ' . $rawQuery . ' ' . $decodedRaw . ' ' . $decodedValues;
        $lower = function_exists('mb_strtolower') ? mb_strtolower($inspect) : strtolower($inspect);

        $hasSqlKeywords = (bool) preg_match(
            "/(\\bunion\\b\\s+\\bselect\\b|\\bselect\\b.+\\bfrom\\b|\\bor\\b\\s+\\d+\\s*=\\s*\\d+|\\band\\b\\s+\\w+\\s*=\\s*\\w+|\\bsleep\\s*\\(|\\bbenchmark\\s*\\(|\\binformation_schema\\b|\\bdrop\\s+table\\b|--|\\/\\*)/",
            $lower
        );
        $hasScriptPayload = str_contains($lower, '<script')
            || str_contains($lower, '</script')
            || str_contains($lower, 'javascript:')
            || str_contains($lower, 'onerror=')
            || str_contains($lower, 'onload=')
            || str_contains($lower, '<svg')
            || str_contains($lower, '<img')
            || str_contains($lower, 'document.cookie');

        return [SecurityLogger::hashValue($query), $hasSqlKeywords, $hasScriptPayload];
    }

    private function flattenQueryValues(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            if (is_array($value)) {
                array_push($out, ...$this->flattenQueryValues($value));
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $out[] = (string) $value;
            }
        }

        return $out;
    }
}
