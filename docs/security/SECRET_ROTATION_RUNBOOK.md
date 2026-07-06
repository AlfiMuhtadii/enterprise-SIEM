# Secret Rotation Runbook (SECRETS-VAULT)

Covers the pluggable secret-provider abstraction (`App\Contracts\SecretProvider`)
and the `security:rotate-internal-token` command.

## Backends

| Backend | Config | Behavior |
|---|---|---|
| `env` (default) | `XDR_SECRET_BACKEND=env` | Reads from the process environment. Identical to pre-SECRETS-VAULT behavior — the demo/default posture is unchanged. |
| `vault` | `XDR_SECRET_BACKEND=vault` | Reads/writes a HashiCorp Vault KV-v2 path (`XDR_VAULT_ADDR`, `XDR_VAULT_TOKEN`, `XDR_VAULT_SECRET_PATH`). Falls back to `env`/`APP_KEY` on any Vault failure — a Vault outage never blocks boot or a request. |

`App\Services\Secrets\SecretProviderManager` resolves the backend once per
request from `config('secrets.backend')` and is bound to the
`App\Contracts\SecretProvider` interface in `AppServiceProvider`.

`InternalAuthService::secret()` consults the provider only as a second
fallback layer — after `config('xdr.internal_auth_secret')` (still the
primary, config-cache-safe source) and before the `APP_KEY` fallback. This
keeps the existing `config('xdr.internal_auth_secret')` contract (and its
test coverage in `InternalAuthConfigMappingTest`) completely unchanged; the
`vault` backend only matters when that config value is empty.

## Rotating `XDR_INTERNAL_AUTH_SECRET`

```powershell
# Preview a candidate secret without writing it anywhere
php artisan security:rotate-internal-token --dry-run

# Rotate for real
php artisan security:rotate-internal-token
```

**`env` backend** (default): the command generates a new 32-byte secret and
prints it — it does **not** write `.env` (a running PHP process cannot
durably mutate its parent shell's environment, and rewriting `.env`
programmatically is out of scope for an advisory-first platform). The
operator must:
1. Set the printed value as `XDR_INTERNAL_AUTH_SECRET` in `.env` (and every
   polyglot service's environment that reads the same var — Go/Python
   services read `XDR_INTERNAL_AUTH_SECRET`/`XDR_*_INTERNAL_TOKEN` directly
   via `os.getenv`/Go `env()`, not through this abstraction).
2. Restart Laravel (`php artisan config:clear` if `config:cache` was used)
   and every service that verifies internal tokens.
3. Run `php artisan security:validate-secrets` to confirm the new value is
   picked up and isn't a dev default.

**`vault` backend**: the command writes the new secret directly to the
configured Vault KV-v2 path via `VaultSecretProvider::set()`, merging with
any other keys already stored at that path. Services still need restarting
to pick up the new value (there is no live-reload/push mechanism).

Every rotation attempt — success or failure to persist — is recorded as a
`SecurityHardeningEvent::EVENT_SECRET_ROTATION` (append-only,
`security_hardening_events`) with the backend name and whether the write
succeeded, so rotation history is auditable regardless of backend.

## Validating secret posture

`php artisan security:validate-secrets` now also reports the active backend
(`secret_backend=env|vault`) and, when `XDR_SECRET_BACKEND=vault`, warns
(never errors — see above) if Vault is unreachable or misconfigured.

## Rollback

If a rotated secret breaks internal service auth (all internal tokens start
failing verification): revert `XDR_INTERNAL_AUTH_SECRET` to its previous
value in `.env` (or the Vault path) and restart. Because
`InternalAuthService` verifies tokens against whatever the current
in-memory secret is, rollback is immediate — there is no persisted rotation
state to also reverse.

## Non-goals

- No automatic `.env` mutation from any command.
- No live secret hot-reload across running processes — rotation always
  requires a restart.
- No scope beyond `XDR_INTERNAL_AUTH_SECRET` in this phase; the four
  per-service `XDR_*_INTERNAL_TOKEN` bearer tokens consumed directly by the
  Go/Python services remain env-only (a future phase could route those
  through the same `SecretProvider` abstraction from each service, but that
  requires adding an HTTP client call to three additional languages and is
  out of scope here).
