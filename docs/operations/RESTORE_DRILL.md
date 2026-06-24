# Restore Drill

**Task:** ENTERPRISE-037  
**Status:** Controlled restore drill workflow — dry-run by default, execute mode requires explicit opt-in.

---

## Purpose

The restore drill validates that a PostgreSQL backup can be successfully restored into
an isolated target database and that post-restore integrity checks pass. It complements
the readiness documentation in `BACKUP_RESTORE_RECOVERY.md` with an executable workflow.

**This does NOT:**
- Overwrite the active database
- Mutate any append-only audit tables
- Change detection rules or shadow/active domain boundaries
- Claim full production PITR or HA backup coverage

**This DOES:**
- Validate pg_dump/pg_restore toolchain availability
- Validate required DB env vars
- Generate a human-readable restore plan
- When `--execute` is used: dump source → createdb isolated target → pg_restore → post-restore checks → cleanup

---

## Safety Invariants

| Invariant | Enforcement |
|---|---|
| Active DB never overwritten | target_db ≠ source_db (PRE-06 fails if equal) |
| Default mode non-destructive | `--execute` required for any DB operation |
| No active data mutation | All post-restore checks are read-only SELECTs on the target DB |
| Cleanup after drill | `dropdb target_db` runs after post-restore checks |
| No promotion side-effects | No ACTIVE_ALLOWLIST changes, no shadow→active promotion |

---

## Usage

### Dry-run (default — no DB changes)

```bash
python scripts/xdr_restore_drill.py
```

Runs pre-flight checks and prints the restore plan. No database operations are performed.

```bash
python scripts/xdr_restore_drill.py --output reports/restore_drill_dryrun.json
```

### Execute mode (actual drill)

```bash
python scripts/xdr_restore_drill.py --execute
```

Requires: PostgreSQL client tools (`pg_dump`, `pg_restore`, `createdb`, `dropdb`, `psql`)
and a running PostgreSQL instance reachable via `.env` DB vars.

```bash
python scripts/xdr_restore_drill.py --execute \
    --target-db xdr_drill_$(date +%Y%m%d) \
    --output reports/restore_drill_$(date +%Y%m%d).json
```

### Custom target database

```bash
python scripts/xdr_restore_drill.py --execute --target-db xdr_restore_test
```

The target database must be different from the source database (enforced by PRE-06).

---

## Pre-flight Checks

| Step | Description | Failure action |
|---|---|---|
| PRE-01 | DB env vars present (HOST/PORT/DATABASE/USERNAME/PASSWORD) | FAIL — abort |
| PRE-02 | `pg_dump` on PATH | FAIL — abort |
| PRE-03 | `pg_restore` on PATH | FAIL — abort |
| PRE-04 | `createdb` on PATH | FAIL — abort |
| PRE-05 | `dropdb` on PATH | WARN only (advisory) |
| PRE-06 | target DB ≠ source DB | FAIL — abort |

---

## Execute Steps

Runs only when `--execute` is provided and all pre-flight checks pass.

| Step | Description |
|---|---|
| DUMP | `pg_dump -Fc source_db -f dump_file` |
| RESTORE-CREATE | `createdb target_db` |
| RESTORE | `pg_restore --no-owner --no-acl -d target_db dump_file` |
| POST-01 | `migrations` table present in target DB |
| POST-02 | 7 append-only spot-check tables present in target DB |
| POST-03 | Rule registry integrity (133 rules, 12 staged_active) — advisory |
| POST-04 | Tenant null audit (`php artisan tenant:null-audit`) — advisory |
| CLEANUP | `dropdb --if-exists target_db` |

---

## Post-restore Checks

### POST-01: Migrations table

Verifies the `migrations` table exists in the restored database. A missing migrations table
indicates an incomplete or corrupt dump.

### POST-02: Append-only table spot-check

Verifies these 7 tables exist in the target DB:
- `security_alerts`
- `security_incidents`
- `export_audit_logs`
- `investigation_events`
- `endpoint_agent_heartbeats`
- `dlq_normalization_events`
- `advisory_finding_events`

### POST-03: Rule registry integrity (advisory)

Reads `docs/detection/rules/registry.v1.json` from the project root and verifies:
- 133 total rules
- 12 staged_active rules

This is an advisory check (WARN on mismatch, never FAIL). It does not query the target DB.

### POST-04: Tenant null audit (advisory)

Runs `php artisan tenant:null-audit --format=json` against the project root.
Skipped if `artisan` is not found (returns INFO). Returns WARN if null tenant rows found.

---

## Dump File Location

Dump files are written to `reports/restore_drill/` with a timestamp:

```
reports/restore_drill/xdr_restore_drill_20260624_120000.dump
```

The dump file is not automatically deleted — remove it manually after confirming the
drill succeeded.

---

## Required Environment

All vars must be set in `.env` or OS environment:

| Variable | Example |
|---|---|
| `DB_HOST` | `localhost` |
| `DB_PORT` | `5432` |
| `DB_DATABASE` | `xdr_db` |
| `DB_USERNAME` | `xdr_user` |
| `DB_PASSWORD` | `(secret)` |

---

## Exit Codes

| Code | Meaning |
|---|---|
| 0 | PASS — all checks passed |
| 1 | FAIL — ≥1 FAIL-level issue |
| 2 | ERROR — unexpected exception |

---

## Related Documents

- `docs/operations/BACKUP_RESTORE_RECOVERY.md` — RPO/RTO documentation and manual backup commands
- `docs/operations/PRODUCTION_DEPLOYMENT_PROFILE.md` — Production deployment posture
- `docs/validation/VALIDATION_BASELINES.md` — Baseline pass criteria
- `scripts/xdr_recovery_validate.py` — Static recovery readiness validator (BACKLOG-DR-027)
