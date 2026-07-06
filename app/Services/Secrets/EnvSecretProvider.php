<?php

namespace App\Services\Secrets;

use App\Contracts\SecretProvider;

/**
 * Default secret-provider driver — reads from the OS/process environment.
 * This is the demo/default backend; behavior is unchanged from before
 * SECRETS-VAULT existed.
 */
class EnvSecretProvider implements SecretProvider
{
    public function get(string $key, string $default = ''): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }

    /**
     * A running PHP process cannot durably mutate its parent shell's
     * environment or rewrite `.env` safely from within a request/command —
     * always returns false so callers know rotation requires an operator
     * to update `.env`/the deployment secret store and restart.
     */
    public function set(string $key, string $value): bool
    {
        return false;
    }

    public function driverName(): string
    {
        return 'env';
    }
}
