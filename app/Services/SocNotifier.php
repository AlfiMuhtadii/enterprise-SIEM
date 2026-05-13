<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SocNotifier
{
    /**
     * @param array<string, mixed> $payload
     */
    public function send(string $targetType, ?string $url, string $eventType, ?string $incidentId, array $payload): bool
    {
        if (!$url) {
            $this->log($targetType, null, $eventType, $incidentId, 'skipped', 1, null, 'URL not configured', $payload);
            return false;
        }

        $maxAttempts = (int) config('notifications_soc.max_attempts', 3);
        $timeout = (int) config('notifications_soc.timeout_seconds', 8);
        $urlHash = hash('sha256', $url);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::timeout($timeout)->post($url, $this->formatPayload($targetType, $payload));
                $ok = $response->successful();
                $this->log($targetType, $urlHash, $eventType, $incidentId, $ok ? 'delivered' : 'failed', $attempt, $response->status(), $ok ? null : Str::limit($response->body(), 500), $payload);
                if ($ok) {
                    return true;
                }
            } catch (\Throwable $e) {
                $this->log($targetType, $urlHash, $eventType, $incidentId, 'failed', $attempt, null, $e->getMessage(), $payload);
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function formatPayload(string $targetType, array $payload): array
    {
        if ($targetType === 'slack') {
            return ['text' => $payload['message'] ?? json_encode($payload)];
        }
        if ($targetType === 'discord') {
            return ['content' => $payload['message'] ?? json_encode($payload)];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function log(string $targetType, ?string $urlHash, string $eventType, ?string $incidentId, string $status, int $attempt, ?int $httpStatus, ?string $error, array $payload): void
    {
        DB::table('notification_delivery_logs')->insert([
            'attempted_at' => now(),
            'target_type' => $targetType,
            'target_url_hash' => $urlHash,
            'event_type' => $eventType,
            'incident_id' => $incidentId,
            'status' => $status,
            'attempt' => $attempt,
            'http_status' => $httpStatus,
            'error_message' => $error,
            'payload' => json_encode($payload),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
