<?php

namespace App\Services;

use App\Models\Honeytoken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CAP-DECEPTION-HONEYTOKEN: seeded fake indicators (credential/file_path/
 * url/dns_name) that should never legitimately appear anywhere — any touch
 * is a near-zero-FP, high-signal tripwire. Purely detective: this service
 * never deploys anything to a third-party system and never triggers active
 * response; it only creates catalog rows and, on scan, writes append-only
 * hit records for an analyst to review.
 *
 * scanForHits() mirrors SocThreatIntelController::matchIocs() exactly (same
 * substring-scan-over-JSON-evidence idiom, same batched insertOrIgnore into
 * a unique-indexed hit table, same accumulate-then-write-once evidence
 * update) so honeytoken detection behaves identically to the existing IOC
 * matching path rather than inventing a second scanning strategy.
 */
class HoneytokenService
{
    public function create(string $tokenType, string $tokenValue, ?string $label = null, ?string $tenantId = null, string $createdBy = 'system'): Honeytoken
    {
        if (!in_array($tokenType, Honeytoken::TOKEN_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid token_type: {$tokenType}");
        }

        return Honeytoken::create([
            'honeytoken_id' => 'ht_'.Str::uuid(),
            'token_type' => $tokenType,
            'token_value' => $tokenValue,
            'label' => $label,
            'tenant_id' => $tenantId,
            'is_active' => true,
            'created_by' => $createdBy,
        ]);
    }

    public function deactivate(string $honeytokenId): bool
    {
        return Honeytoken::where('honeytoken_id', $honeytokenId)->update(['is_active' => false]) > 0;
    }

    public function listActive(?string $tenantId = null)
    {
        $query = Honeytoken::where('is_active', true);
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->orderByDesc('created_at')->get();
    }

    /**
     * Scans recent security_alerts evidence for any honeytoken value.
     * Returns the number of matches found (not distinct new inserts —
     * honeytoken_hits has a unique (honeytoken_id, alert_id) index, so
     * insertOrIgnore de-duplicates and re-running is idempotent).
     */
    public function scanForHits(int $withinDays = 30, int $alertLimit = 2000): int
    {
        $tokens = Honeytoken::where('is_active', true)->get();
        if ($tokens->isEmpty()) {
            return 0;
        }

        $alerts = DB::table('security_alerts')
            ->where('detected_at', '>=', now()->subDays($withinDays))
            ->orderByDesc('detected_at')
            ->limit($alertLimit)
            ->get();

        $now = now();
        $hitRows = [];
        $evidenceByAlert = [];
        $hits = 0;

        $needles = [];
        foreach ($tokens as $token) {
            $needles[] = ['token' => $token, 'needle' => strtolower($token->token_value)];
        }

        foreach ($alerts as $alert) {
            // Decode evidence/raw_event (stored as JSON text) back into arrays
            // before re-encoding (avoids nesting an already-encoded JSON
            // string inside another json_encode() call), AND pass
            // JSON_UNESCAPED_SLASHES — json_encode() escapes "/" to "\/" by
            // default, which would otherwise never match a plain-text needle
            // containing "/" (exactly what a honeytoken file_path or url
            // value looks like — two of the four supported token types).
            $haystack = strtolower(json_encode([
                $alert->ip,
                $alert->actor_key ?? null,
                json_decode($alert->evidence ?: 'null', true),
                json_decode($alert->raw_event ?: 'null', true),
            ], JSON_UNESCAPED_SLASHES));
            foreach ($needles as $entry) {
                if (!str_contains($haystack, $entry['needle'])) {
                    continue;
                }
                $token = $entry['token'];
                $hitRows[] = [
                    'honeytoken_id' => $token->honeytoken_id,
                    'alert_id' => $alert->alert_id,
                    'matched_field' => $token->token_type,
                    'matched_value' => $token->token_value,
                    'matched_at' => $now,
                    'metadata' => json_encode(['label' => $token->label]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if (!array_key_exists($alert->alert_id, $evidenceByAlert)) {
                    $evidenceByAlert[$alert->alert_id] = json_decode($alert->evidence ?: '{}', true) ?: [];
                }
                $evidenceByAlert[$alert->alert_id]['honeytoken_matches'][] = [
                    'honeytoken_id' => $token->honeytoken_id,
                    'type' => $token->token_type,
                    'label' => $token->label,
                ];
                $hits++;
            }
        }

        if ($hitRows === []) {
            return 0;
        }

        DB::transaction(function () use ($hitRows, $evidenceByAlert, $now) {
            foreach (array_chunk($hitRows, 500) as $chunk) {
                DB::table('honeytoken_hits')->insertOrIgnore($chunk);
            }
            foreach ($evidenceByAlert as $alertId => $evidence) {
                DB::table('security_alerts')
                    ->where('alert_id', $alertId)
                    ->update(['evidence' => json_encode($evidence), 'updated_at' => $now]);
            }
        });

        return $hits;
    }
}
