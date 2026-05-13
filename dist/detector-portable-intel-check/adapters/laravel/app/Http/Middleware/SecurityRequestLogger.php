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

        if ($this->shouldIgnore($request)) {
            return $response;
        }

        $path = '/' . ltrim($request->path(), '/');
        $queryHash = null;
        $hasSqlKeywords = false;
        $hasScriptPayload = false;

        if ($this->shouldCaptureQuery($request) && $request->query->count() > 0) {
            $query = http_build_query($request->query->all(), '', '&', PHP_QUERY_RFC3986);
            $queryHash = SecurityLogger::hashValue($query);
            $lower = function_exists('mb_strtolower') ? mb_strtolower($query) : strtolower($query);
            $hasSqlKeywords = (bool) preg_match('/\b(union|select|or|and|drop|insert|update|delete|sleep|benchmark|information_schema)\b/', $lower);
            $hasScriptPayload = str_contains($lower, '<script') || str_contains($lower, 'javascript:') || str_contains($lower, 'onerror=');
        }

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
        foreach ((array) config('security_detector.ignored_paths', []) as $pattern) {
            if (is_string($pattern) && $pattern !== '' && $request->is($pattern)) {
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
}
