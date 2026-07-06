<?php

namespace App\Contracts;

/**
 * Pluggable secret-provider contract (SECRETS-VAULT).
 *
 * Implementations must never throw on read failure — a provider outage or
 * misconfiguration must fall back to the given default, never crash a
 * request or the application boot sequence.
 */
interface SecretProvider
{
    public function get(string $key, string $default = ''): string;

    /**
     * Write/rotate a secret in the backing store. Backends that cannot
     * durably persist a secret from within a running process (e.g. the env
     * driver — a PHP process cannot mutate its parent shell's environment)
     * must return false rather than pretend to succeed.
     */
    public function set(string $key, string $value): bool;

    public function driverName(): string;
}
