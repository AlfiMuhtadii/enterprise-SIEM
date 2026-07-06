# Testing Guide

## TL;DR

```powershell
# one-time
createdb detector_test

# run the suite (serial)
php artisan test

# run faster (parallel — brianium/paratest is a committed dev dependency)
php artisan test --parallel --recreate-databases

# iterate on one area
php artisan test --filter=SiemSearchTest
```

Tests run against a **dedicated `detector_test` Postgres database**, never the app/dev
database (`detector`). You never need `migrate:fresh` — the suite manages its own schema.

## Why a dedicated test DB

`phpunit.xml` sets `DB_DATABASE=detector_test`. This override always wins over `.env`, so:

- A test run **cannot touch or wipe** your real `detector` data.
- Because each test worker gets its own DB, **parallel runs are safe** (the single biggest
  speedup — roughly Nx on core count).
- Schema is auto-managed: 140/147 test files use `RefreshDatabase`, which runs `migrate:fresh`
  **once** at suite start and wraps every test in a transaction that rolls back. A manual
  `php artisan migrate:fresh` before the suite is redundant (double-migration) and is no longer
  part of the workflow.

**`database/schema/pgsql-schema.sql` speeds up the initial `migrate:fresh`** `RefreshDatabase`
runs at suite start — it replays the whole schema as one `psql --file=` script instead of
running 147+ migrations individually, and `RefreshDatabase` picks it up automatically with no
config change. An earlier attempt at this failed (74 NOT NULL violations on `id` columns) because
the dump was generated against the long-lived dev `detector` database, which had drifted from a
clean state — `pg_dump` faithfully reproduced that drift. **If you regenerate this file**, always
run `schema:dump` against a database you just ran `migrate:fresh` against:
```powershell
dropdb detector_test; createdb detector_test
php artisan migrate:fresh --force --database=pgsql   # or: DB_DATABASE=detector_test php artisan migrate:fresh --force
DB_DATABASE=detector_test php artisan schema:dump
```
Never regenerate it against the ambient dev `detector` DB, and re-run the full suite once against
a freshly recreated `detector_test` before trusting the new dump — see `claude.md`'s
test-database section for the full root-cause writeup.

Historically the suite shared the app DB, which is why the old instructions prefixed every run
with `migrate:fresh --force` (a band-aid that also destroyed dev data). That is fixed.

## One-time setup

```powershell
createdb detector_test
# or: psql -U postgres -c "CREATE DATABASE detector_test;"
```

`RefreshDatabase` migrates it on the first test run. If schema ever gets wedged:

```powershell
dropdb detector_test; createdb detector_test
```

## Parallel runs

```powershell
php artisan test --parallel --recreate-databases
```

Laravel creates per-worker databases `detector_test_test_1..N` automatically. Never run parallel
workers against a single shared DB — the per-worker DBs above are what make it safe.

Verified: 12 workers → 12 `detector_test_test_N` databases, same 4681-test/11843-assertion
result as serial, ~46% faster (613s serial vs 332s parallel on this machine).

## `.env.testing` (only for artisan --env=testing)

`php artisan test` / phpunit do **not** need `.env.testing` — `phpunit.xml` already sets the test
DB. You only need `.env.testing` to run artisan commands explicitly against the test environment
(e.g. `php artisan migrate --env=testing`). See `.env.testing.example`. Note: Laravel **replaces**
`.env` with `.env.testing` (it does not merge) — a real `.env.testing` must be complete, so copy
your working `.env` and change only the DB name + `APP_ENV`. Do not commit a real `.env.testing`.

## Why not sqlite :memory:

Tempting (fastest, zero-setup) but the codebase relies on Postgres-specific SQL — `::jsonb`
casts, `ILIKE`, `xmax` upsert detection, `GREATEST` — that sqlite does not support. Keep Postgres
fidelity; gain speed from isolation + parallelism instead.

## Iteration discipline (unchanged)

Run the smallest relevant test first (`--filter=<Class>` / `--filter=<method>`); run the full
suite once before a commit or milestone claim. Do not re-run the full suite after every small
edit.
