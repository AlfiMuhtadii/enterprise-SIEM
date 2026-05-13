<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgentIngestionController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $configured = (string) config('soc.agent_enrollment_token', '');
        $provided = (string) $request->header('X-Agent-Enrollment-Token', '');
        if ($configured === '' || !hash_equals(hash('sha256', $configured), hash('sha256', $provided))) {
            $this->recordFailure(null, 'enrollment_denied', 'Invalid enrollment token', $request->all());
            return response()->json(['ok' => false, 'error' => 'invalid enrollment token'], 401);
        }

        $data = $request->validate([
            'host_fingerprint' => ['required', 'string', 'max:128'],
            'host_id' => ['required', 'string', 'max:128'],
            'agent_version' => ['required', 'string', 'max:40'],
            'os_family' => ['nullable', 'string', 'max:40'],
            'metadata' => ['nullable', 'array'],
        ]);

        $existing = DB::table('endpoint_agents')
            ->where('host_fingerprint', $data['host_fingerprint'])
            ->first();

        $agentId = $existing->agent_id ?? 'agent-'.Str::uuid()->toString();
        $secret = Str::random(48);

        DB::table('endpoint_agents')->updateOrInsert(
            ['agent_id' => $agentId],
            [
                'host_fingerprint' => $data['host_fingerprint'],
                'host_id' => $data['host_id'],
                'agent_version' => $data['agent_version'],
                'os_family' => $data['os_family'] ?? null,
                'policy_id' => $existing->policy_id ?? null,
                'policy_version_seen' => $existing->policy_version_seen ?? 0,
                'upgrade_status' => $this->upgradeStatus($data['agent_version']),
                'target_version' => $this->latestVersion(),
                'status' => 'online',
                'agent_secret' => Crypt::encryptString($secret),
                'enrolled_at' => $existing->enrolled_at ?? now(),
                'last_seen_at' => now(),
                'metadata' => json_encode($data['metadata'] ?? []),
                'updated_at' => now(),
                'created_at' => $existing->created_at ?? now(),
            ]
        );

        return response()->json([
            'ok' => true,
            'agent_id' => $agentId,
            'agent_secret' => $secret,
            'heartbeat_interval_seconds' => (int) config('soc.agent_heartbeat_interval_seconds', 60),
        ]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $agent = $this->verifiedAgent($request);
        if (!$agent) {
            return response()->json(['ok' => false, 'error' => 'unauthorized agent'], 401);
        }

        $data = $request->validate([
            'event_count_total' => ['nullable', 'integer', 'min:0'],
            'error_count_total' => ['nullable', 'integer', 'min:0'],
            'last_batch_event_count' => ['nullable', 'integer', 'min:0'],
            'last_error' => ['nullable', 'string', 'max:500'],
            'agent_version' => ['nullable', 'string', 'max:40'],
            'metadata' => ['nullable', 'array'],
            'policy_version_seen' => ['nullable', 'integer', 'min:0'],
            'config_hash' => ['nullable', 'string', 'max:96'],
            'retry_queue_depth' => ['nullable', 'integer', 'min:0'],
            'upgrade_status' => ['nullable', 'string', 'max:40'],
            'stream_status' => ['nullable', 'string', 'max:32'],
            'events_streamed_total' => ['nullable', 'integer', 'min:0'],
            'events_dropped_total' => ['nullable', 'integer', 'min:0'],
            'stream_retry_total' => ['nullable', 'integer', 'min:0'],
            'avg_event_latency_ms' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::table('endpoint_agents')->where('agent_id', $agent->agent_id)->update([
            'status' => 'online',
            'last_seen_at' => now(),
            'event_count_total' => $data['event_count_total'] ?? $agent->event_count_total,
            'error_count_total' => $data['error_count_total'] ?? $agent->error_count_total,
            'last_batch_event_count' => $data['last_batch_event_count'] ?? 0,
            'last_error' => $data['last_error'] ?? null,
            'agent_version' => $data['agent_version'] ?? $agent->agent_version,
            'metadata' => isset($data['metadata']) ? json_encode($data['metadata']) : $agent->metadata,
            'policy_version_seen' => $data['policy_version_seen'] ?? ($agent->policy_version_seen ?? 0),
            'config_hash' => $data['config_hash'] ?? ($agent->config_hash ?? null),
            'retry_queue_depth' => $data['retry_queue_depth'] ?? ($agent->retry_queue_depth ?? 0),
            'upgrade_status' => $data['upgrade_status'] ?? $this->upgradeStatus($data['agent_version'] ?? $agent->agent_version),
            'target_version' => $this->latestVersion(),
            'updated_at' => now(),
        ]);

        if (isset($data['stream_status']) || isset($data['events_streamed_total'])) {
            DB::table('agent_stream_metrics')->insert([
                'agent_id' => $agent->agent_id,
                'reported_at' => now(),
                'stream_status' => $data['stream_status'] ?? 'healthy',
                'events_streamed_total' => $data['events_streamed_total'] ?? 0,
                'events_dropped_total' => $data['events_dropped_total'] ?? 0,
                'stream_retry_total' => $data['stream_retry_total'] ?? 0,
                'avg_event_latency_ms' => $data['avg_event_latency_ms'] ?? null,
                'buffer_depth' => $data['retry_queue_depth'] ?? 0,
                'last_error' => $data['last_error'] ?? null,
                'metadata' => json_encode(['source' => 'agent-heartbeat']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['ok' => true, 'server_time' => now()->toIso8601String()]);
    }

    public function config(Request $request): JsonResponse
    {
        $agent = $this->verifiedAgent($request);
        if (!$agent) {
            return response()->json(['ok' => false, 'error' => 'unauthorized agent'], 401);
        }

        $policy = $this->policyFor($agent);
        $commands = DB::table('agent_commands')
            ->where('agent_id', $agent->agent_id)
            ->where('status', 'queued')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('queued_at')
            ->limit(10)
            ->get();

        foreach ($commands as $command) {
            DB::table('agent_commands')->where('id', $command->id)->update([
                'status' => 'sent',
                'sent_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);
        }

        DB::table('endpoint_agents')->where('agent_id', $agent->agent_id)->update([
            'last_seen_at' => now(),
            'status' => 'online',
            'policy_id' => $policy['policy_id'],
            'updated_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'policy' => $policy,
            'commands' => $commands->map(fn ($cmd) => [
                'command_id' => $cmd->command_id,
                'command_type' => $cmd->command_type,
                'payload' => json_decode($cmd->payload ?: '{}', true) ?: [],
                'expires_at' => $cmd->expires_at,
            ])->values(),
            'latest_version' => $this->latestVersion(),
        ]);
    }

    public function commandResult(Request $request): JsonResponse
    {
        $agent = $this->verifiedAgent($request);
        if (!$agent) {
            return response()->json(['ok' => false, 'error' => 'unauthorized agent'], 401);
        }

        $data = $request->validate([
            'command_id' => ['required', 'string', 'max:80'],
            'status' => ['required', 'in:succeeded,failed,retry,timeout,unsupported'],
            'result' => ['nullable', 'array'],
        ]);

        DB::table('agent_commands')
            ->where('agent_id', $agent->agent_id)
            ->where('command_id', $data['command_id'])
            ->update([
                'status' => $data['status'],
                'result' => json_encode($data['result'] ?? []),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        if ($data['status'] === 'succeeded') {
            $command = DB::table('agent_commands')
                ->where('agent_id', $agent->agent_id)
                ->where('command_id', $data['command_id'])
                ->first();
            $payload = $command ? (json_decode($command->payload ?: '{}', true) ?: []) : [];
            if ($command && $command->command_type === 'rotate-agent-secret' && !empty($payload['new_agent_secret'])) {
                DB::table('endpoint_agents')->where('agent_id', $agent->agent_id)->update([
                    'agent_secret' => Crypt::encryptString($payload['new_agent_secret']),
                    'updated_at' => now(),
                ]);
            }
        }

        return response()->json(['ok' => true]);
    }

    public function telemetry(Request $request): JsonResponse
    {
        $agent = $this->verifiedAgent($request);
        if (!$agent) {
            return response()->json(['ok' => false, 'error' => 'unauthorized agent'], 401);
        }

        $data = $request->validate([
            'events' => ['required', 'array', 'max:1000'],
            'events.*.schema_version' => ['required', 'integer'],
            'events.*.ts' => ['required', 'string'],
            'events.*.event_id' => ['required', 'string', 'max:80'],
            'events.*.telemetry_type' => ['required', 'in:endpoint,network,dns'],
            'events.*.event_type' => ['required', 'string', 'max:80'],
            'events.*.host_id' => ['required', 'string', 'max:128'],
        ]);

        $rows = [];
        foreach ($data['events'] as $event) {
            $event['agent_id'] = $agent->agent_id;
            $rows[] = [
                'ts' => $event['ts'],
                'event_id' => substr((string) $event['event_id'], 0, 80),
                'telemetry_type' => $event['telemetry_type'],
                'event_type' => substr((string) $event['event_type'], 0, 80),
                'host_id' => substr((string) $event['host_id'], 0, 128),
                'src_ip' => $event['src_ip'] ?? null,
                'dst_ip' => $event['dst_ip'] ?? null,
                'dst_port' => $event['dst_port'] ?? null,
                'protocol' => $event['protocol'] ?? null,
                'process_name' => $event['process_name'] ?? null,
                'user_name_hash' => $event['user_name_hash'] ?? null,
                'payload' => json_encode($event),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $inserted = 0;
        foreach (array_chunk($rows, 250) as $chunk) {
            $inserted += DB::table('telemetry_events')->insertOrIgnore($chunk);
        }

        DB::table('endpoint_agents')->where('agent_id', $agent->agent_id)->update([
            'status' => 'online',
            'last_seen_at' => now(),
            'event_count_total' => DB::raw('event_count_total + '.count($rows)),
            'last_batch_event_count' => count($rows),
            'retry_queue_depth' => 0,
            'upgrade_status' => $this->upgradeStatus($agent->agent_version),
            'target_version' => $this->latestVersion(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'received' => count($rows), 'inserted' => $inserted]);
    }

    private function verifiedAgent(Request $request): ?object
    {
        $agentId = (string) $request->header('X-Agent-Id', '');
        $timestamp = (string) $request->header('X-Agent-Timestamp', '');
        $signature = (string) $request->header('X-Agent-Signature', '');
        if ($agentId === '' || $timestamp === '' || $signature === '') {
            $this->recordFailure($agentId ?: null, 'missing_signature', 'Missing agent auth headers', []);
            return null;
        }
        if (abs(now()->timestamp - (int) $timestamp) > 300) {
            $this->recordFailure($agentId, 'stale_signature', 'Agent timestamp outside accepted window', []);
            return null;
        }

        $agent = DB::table('endpoint_agents')->where('agent_id', $agentId)->first();
        if (!$agent || !$agent->agent_secret) {
            $this->recordFailure($agentId, 'unknown_agent', 'Unknown agent id', []);
            return null;
        }

        $secret = Crypt::decryptString($agent->agent_secret);
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        if (!hash_equals($expected, $signature)) {
            $this->recordFailure($agentId, 'bad_signature', 'Invalid payload signature', []);
            return null;
        }

        return $agent;
    }

    private function recordFailure(?string $agentId, string $type, string $message, array $payload): void
    {
        DB::table('agent_delivery_failures')->insert([
            'agent_id' => $agentId,
            'failure_type' => $type,
            'message' => $message,
            'payload' => json_encode($payload),
            'failed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function policyFor(object $agent): array
    {
        $policy = null;
        if (!empty($agent->policy_id)) {
            $policy = DB::table('agent_policies')->where('policy_id', $agent->policy_id)->first();
        }
        if (!$policy) {
            $policy = DB::table('agent_policies')->where('is_default', true)->orderByDesc('version')->first();
        }
        if (!$policy) {
            $policyId = 'default-endpoint-policy';
            DB::table('agent_policies')->updateOrInsert(
                ['policy_id' => $policyId],
                [
                    'name' => 'Default Endpoint Policy',
                    'version' => 1,
                    'is_default' => true,
                    'collection_interval_seconds' => (int) config('soc.agent_heartbeat_interval_seconds', 60),
                    'enabled_collectors' => json_encode(['process' => true, 'network' => true]),
                    'max_batch_size' => 500,
                    'retry_policy' => json_encode(['max_backoff_seconds' => 300, 'initial_backoff_seconds' => 1]),
                    'telemetry_categories' => json_encode(['endpoint', 'network']),
                    'metadata' => json_encode(['created_by' => 'auto']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            $policy = DB::table('agent_policies')->where('policy_id', $policyId)->first();
        }

        return [
            'policy_id' => $policy->policy_id,
            'version' => (int) $policy->version,
            'collection_interval_seconds' => (int) $policy->collection_interval_seconds,
            'enabled_collectors' => json_decode($policy->enabled_collectors ?: '{}', true) ?: [],
            'max_batch_size' => (int) $policy->max_batch_size,
            'retry_policy' => json_decode($policy->retry_policy ?: '{}', true) ?: [],
            'telemetry_categories' => json_decode($policy->telemetry_categories ?: '[]', true) ?: [],
        ];
    }

    private function latestVersion(): string
    {
        $release = DB::table('agent_releases')->where('is_latest', true)->orderByDesc('released_at')->first();
        return $release->version ?? (string) config('soc.agent_latest_version', '0.2.0');
    }

    private function upgradeStatus(string $version): string
    {
        return version_compare($version, $this->latestVersion(), '<') ? 'upgrade_available' : 'current';
    }
}
