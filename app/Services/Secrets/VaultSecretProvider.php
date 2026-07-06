<?php

namespace App\Services\Secrets;

use App\Contracts\SecretProvider;
use Illuminate\Support\Facades\Http;

/**
 * HashiCorp Vault KV-v2 secret-provider driver (SECRETS-VAULT).
 *
 * Best-effort: any network/config failure falls back to the given default
 * rather than throwing, so a Vault outage never crashes a request or boot.
 */
class VaultSecretProvider implements SecretProvider
{
    public function __construct(
        private readonly string $addr,
        private readonly string $token,
        private readonly string $secretPath,
        private readonly int $timeoutSeconds = 5,
    ) {}

    public function get(string $key, string $default = ''): string
    {
        if (!$this->isConfigured()) {
            return $default;
        }

        $data = $this->readAll();
        if ($data === null || !array_key_exists($key, $data)) {
            return $default;
        }

        return (string) $data[$key];
    }

    public function set(string $key, string $value): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $data = $this->readAll() ?? [];
        $data[$key] = $value;

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withHeaders(['X-Vault-Token' => $this->token])
                ->post($this->url(), ['data' => $data]);

            return $response->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function driverName(): string
    {
        return 'vault';
    }

    public function isReachable(): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withHeaders(['X-Vault-Token' => $this->token])
                ->get(rtrim($this->addr, '/').'/v1/sys/health');

            return $response->successful() || $response->status() === 429;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function isConfigured(): bool
    {
        return $this->addr !== '' && $this->token !== '' && $this->secretPath !== '';
    }

    /** @return array<string, string>|null */
    private function readAll(): ?array
    {
        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withHeaders(['X-Vault-Token' => $this->token])
                ->get($this->url());
            if (!$response->successful()) {
                return null;
            }

            return $response->json('data.data') ?? [];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function url(): string
    {
        return rtrim($this->addr, '/').'/v1/'.ltrim($this->secretPath, '/');
    }
}
