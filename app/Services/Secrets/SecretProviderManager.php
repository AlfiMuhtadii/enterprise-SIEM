<?php

namespace App\Services\Secrets;

use App\Contracts\SecretProvider;

/**
 * Resolves the configured secret-provider backend (SECRETS-VAULT).
 * Default backend is 'env' — behavior-identical to pre-SECRETS-VAULT code.
 */
class SecretProviderManager implements SecretProvider
{
    private SecretProvider $driver;

    public function __construct(?string $backend = null)
    {
        $this->driver = $this->resolve($backend ?? (string) config('secrets.backend', 'env'));
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->driver->get($key, $default);
    }

    public function set(string $key, string $value): bool
    {
        return $this->driver->set($key, $value);
    }

    public function driverName(): string
    {
        return $this->driver->driverName();
    }

    private function resolve(string $backend): SecretProvider
    {
        return match ($backend) {
            'vault' => new VaultSecretProvider(
                (string) config('secrets.vault.addr', ''),
                (string) config('secrets.vault.token', ''),
                (string) config('secrets.vault.secret_path', ''),
                (int) config('secrets.vault.timeout_seconds', 5),
            ),
            default => new EnvSecretProvider(),
        };
    }
}
