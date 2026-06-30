<?php

namespace App\Http\Controllers;

use App\Support\AuditLogger;
use App\Support\ExternalThreatIntelService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SocThreatIntelController extends Controller
{
    public function index(): View
    {
        return view('soc.threat-intel.index', [
            'iocs' => DB::table('threat_iocs')->orderByDesc('created_at')->paginate(50),
            'hits' => DB::table('ioc_hits')->orderByDesc('matched_at')->limit(100)->get(),
            'summary' => DB::table('threat_iocs')
                ->select('ioc_type', DB::raw('count(*) as total'))
                ->where('enabled', true)
                ->groupBy('ioc_type')
                ->orderByDesc('total')
                ->get(),
            'lookups' => DB::table('external_threat_intel_lookups')->orderByDesc('looked_up_at')->limit(50)->get(),
            'feeds' => DB::table('external_ioc_feeds')->orderByDesc('last_imported_at')->limit(20)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ioc_type' => ['required', 'in:ip,domain,hash,url'],
            'ioc_value' => ['required', 'string', 'max:512'],
            'source' => ['nullable', 'string', 'max:160'],
            'reputation' => ['nullable', 'in:unknown,benign,suspicious,malicious'],
            'threat_label' => ['nullable', 'string', 'max:160'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);
        $iocId = 'ioc-'.Str::uuid();
        DB::table('threat_iocs')->updateOrInsert(
            ['ioc_type' => $data['ioc_type'], 'ioc_value' => $data['ioc_value']],
            [
                'ioc_id' => $iocId,
                'source' => $data['source'] ?? 'manual',
                'reputation' => $data['reputation'] ?? 'unknown',
                'threat_label' => $data['threat_label'] ?? null,
                'expires_at' => isset($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null,
                'enabled' => true,
                'metadata' => json_encode(['entry' => 'manual']),
                'created_by' => $request->user()->email,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        AuditLogger::log($request->user()->email, 'ioc.create', 'ioc', $iocId, null, $data);

        return back()->with('status', 'IOC saved.');
    }

    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'feed_format' => ['required', 'in:jsonl,csv'],
            'feed_body' => ['required', 'string'],
            'source' => ['nullable', 'string', 'max:160'],
        ]);
        $rows = $data['feed_format'] === 'jsonl' ? $this->parseJsonl($data['feed_body']) : $this->parseCsv($data['feed_body']);
        $inserted = 0;
        foreach ($rows as $row) {
            $type = $row['ioc_type'] ?? $row['type'] ?? null;
            $value = $row['ioc_value'] ?? $row['value'] ?? null;
            if (!in_array($type, ['ip', 'domain', 'hash', 'url'], true) || !is_string($value) || trim($value) === '') {
                continue;
            }
            DB::table('threat_iocs')->updateOrInsert(
                ['ioc_type' => $type, 'ioc_value' => trim($value)],
                [
                    'ioc_id' => 'ioc-'.Str::uuid(),
                    'source' => $row['source'] ?? $data['source'] ?? 'import',
                    'reputation' => $row['reputation'] ?? 'suspicious',
                    'threat_label' => $row['threat_label'] ?? $row['label'] ?? null,
                    'expires_at' => !empty($row['expires_at']) ? $row['expires_at'] : null,
                    'enabled' => true,
                    'metadata' => json_encode(['feed_format' => $data['feed_format']]),
                    'created_by' => $request->user()->email,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            $inserted++;
        }
        AuditLogger::log($request->user()->email, 'ioc.import', 'ioc_feed', null, null, ['count' => $inserted, 'format' => $data['feed_format']]);

        return back()->with('status', "IOC import processed: {$inserted}");
    }

    public function enrich(Request $request): RedirectResponse
    {
        $hits = $this->matchIocs();
        AuditLogger::log($request->user()->email, 'ioc.enrich_alerts', 'ioc', null, null, ['hits' => $hits]);
        return back()->with('status', "IOC enrichment hits: {$hits}");
    }

    public function lookup(Request $request, ExternalThreatIntelService $intel): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'in:virustotal,abuseipdb,webhook,local'],
            'indicator_type' => ['required', 'in:ip,domain,hash,url'],
            'indicator_value' => ['required', 'string', 'max:512'],
        ]);
        $result = $intel->lookup($data['provider'], $data['indicator_type'], $data['indicator_value'], $request->user()->email);
        AuditLogger::log($request->user()->email, 'ti.lookup', 'indicator', $data['indicator_value'], null, $result);

        return back()->with('status', 'Threat intel lookup: '.($result['reputation'] ?? 'unknown'));
    }

    public function externalFeed(Request $request, ExternalThreatIntelService $intel): RedirectResponse
    {
        $data = $request->validate([
            'feed_type' => ['required', 'in:jsonl,misp-json,opencti-json'],
            'source' => ['required', 'string', 'max:160'],
            'feed_body' => ['required', 'string'],
        ]);
        $count = $intel->importFeed($data['feed_type'], $data['feed_body'], $data['source'], $request->user()->email);
        AuditLogger::log($request->user()->email, 'ti.external_feed_import', 'ioc_feed', $data['source'], null, ['count' => $count, 'feed_type' => $data['feed_type']]);

        return back()->with('status', "External feed imported: {$count}");
    }

    private function parseJsonl(string $body): array
    {
        $rows = [];
        foreach (preg_split('/\r\n|\n|\r/', $body) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private function parseCsv(string $body): array
    {
        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', $body), fn ($line) => trim($line) !== ''));
        if (!$lines) {
            return [];
        }
        $headers = str_getcsv(array_shift($lines));
        $rows = [];
        foreach ($lines as $line) {
            $values = str_getcsv($line);
            $rows[] = array_combine($headers, array_pad($values, count($headers), null));
        }
        return $rows;
    }

    /**
     * Match active IOCs against recent alerts and enrich them.
     *
     * PERF-IOC-LOOP: the nested alert×IOC scan previously issued one ioc_hits
     * insert AND one security_alerts UPDATE per match (synchronous writes inside
     * the inner loop). This batches all hit rows into chunked inserts and writes
     * each matched alert's evidence exactly once.
     *
     * Note: this also fixes a latent correctness bug — the previous code
     * re-decoded the original (in-memory) $alert->evidence on every inner
     * iteration, so an alert matching multiple IOCs only persisted the LAST
     * match. Accumulating per-alert evidence in memory persists all matches.
     *
     * Semantics preserved: $hits counts every match (not only new inserts);
     * ioc_hits has no unique key, so inserts are NOT de-duplicated (a repeated
     * enrich run appends rows again, matching prior behavior).
     */
    private function matchIocs(): int
    {
        $iocs = DB::table('threat_iocs')
            ->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();
        if ($iocs->isEmpty()) {
            return 0;
        }

        $alerts = DB::table('security_alerts')
            ->where('detected_at', '>=', now()->subDays(30))
            ->orderByDesc('detected_at')
            ->limit(2000)
            ->get();

        $now = now();
        $hitRows = [];
        $evidenceByAlert = [];
        $hits = 0;

        // PERF-IOC-STR-LOWER: lowercase each IOC value once here instead of once
        // per (alert × IOC) inside the nested matching loop below.
        $iocNeedles = [];
        foreach ($iocs as $ioc) {
            $iocNeedles[] = ['ioc' => $ioc, 'needle' => strtolower((string) $ioc->ioc_value)];
        }

        foreach ($alerts as $alert) {
            $haystack = strtolower(json_encode([$alert->ip, $alert->actor_key ?? null, $alert->evidence, $alert->raw_event]));
            foreach ($iocNeedles as $entry) {
                if (!str_contains($haystack, $entry['needle'])) {
                    continue;
                }
                $ioc = $entry['ioc'];
                $hitRows[] = [
                    'ioc_id' => $ioc->ioc_id,
                    'alert_id' => $alert->alert_id,
                    'matched_field' => $ioc->ioc_type,
                    'matched_value' => $ioc->ioc_value,
                    'matched_at' => $now,
                    'metadata' => json_encode(['reputation' => $ioc->reputation, 'threat_label' => $ioc->threat_label]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if (!array_key_exists($alert->alert_id, $evidenceByAlert)) {
                    $evidenceByAlert[$alert->alert_id] = json_decode($alert->evidence ?: '{}', true) ?: [];
                }
                $evidenceByAlert[$alert->alert_id]['ioc_matches'][] = [
                    'ioc_id' => $ioc->ioc_id,
                    'type' => $ioc->ioc_type,
                    'value' => $ioc->ioc_value,
                    'reputation' => $ioc->reputation,
                    'threat_label' => $ioc->threat_label,
                ];
                $hits++;
            }
        }

        if ($hitRows === []) {
            return 0;
        }

        DB::transaction(function () use ($hitRows, $evidenceByAlert, $now) {
            foreach (array_chunk($hitRows, 500) as $chunk) {
                DB::table('ioc_hits')->insertOrIgnore($chunk);
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
