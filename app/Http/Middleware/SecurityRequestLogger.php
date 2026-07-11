<?php

namespace App\Http\Middleware;

use App\Services\OtlpExportService;
use App\Services\SecurityLogger;
use App\Services\TraceparentService;
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

        // OBS-OTEL-TRACING (phase 3): propagate a W3C traceparent — a new child
        // span if the request carried a valid one, otherwise a fresh root — so
        // an analyst request into the SOC control plane can be linked to the
        // same trace as the pipeline events it's investigating.
        $traceparent = TraceparentService::propagate($request->headers->get('traceparent'));
        $request->attributes->set('traceparent', $traceparent);
        $response->headers->set('Traceparent', $traceparent);

        $path = '/' . ltrim($request->path(), '/');
        if ($this->shouldIgnore($request)) {
            return $response;
        }

        [$queryHash, $hasSqlKeywords, $hasScriptPayload] = $this->queryThreatSignals($request);

        SecurityLogger::log('http_request', [
            'request_id' => $requestId,
            'traceparent' => $traceparent,
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

    /**
     * OBS-OTEL-TRACING (phase 5): Laravel calls terminate() on global
     * middleware AFTER the response has already been sent to the client
     * (Illuminate\Foundation\Http\Kernel::terminate(), invoked from
     * public/index.php) — unlike handle(), work done here never delays
     * what the requester sees, the closest equivalent this SAPI-agnostic
     * middleware has to the Go/Python hops' fire-and-forget goroutine/
     * background thread. A no-op when config('xdr.otel_exporter_endpoint')
     * is blank (the default) or no traceparent was computed for this
     * request (handle() short-circuited before reaching that point, e.g.
     * maintenance mode).
     */
    public function terminate(Request $request, Response $response): void
    {
        $endpoint = (string) config('xdr.otel_exporter_endpoint', '');
        if ($endpoint === '') {
            return;
        }

        $traceparent = $request->attributes->get('traceparent');
        if (! is_string($traceparent) || $traceparent === '') {
            return;
        }

        $outboundParsed = TraceparentService::parse($traceparent);
        if ($outboundParsed === null) {
            return;
        }
        $inboundParsed = TraceparentService::parse($request->headers->get('traceparent'));

        $requestStart = $request->attributes->get('request_start');
        $startSeconds = is_float($requestStart) ? $requestStart : microtime(true);

        $span = [
            'trace_id' => $outboundParsed['trace_id'],
            'span_id' => $outboundParsed['span_id'],
            'name' => 'soc-control-plane.http_request',
            'kind' => OtlpExportService::SPAN_KIND_SERVER,
            'start_unix_nano' => (int) ($startSeconds * 1_000_000_000),
            'end_unix_nano' => (int) (microtime(true) * 1_000_000_000),
        ];
        if ($inboundParsed !== null) {
            $span['parent_span_id'] = $inboundParsed['span_id'];
        }

        OtlpExportService::export($endpoint, 'soc-control-plane', [$span]);
    }
}
