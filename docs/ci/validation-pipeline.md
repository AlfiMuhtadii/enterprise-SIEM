# CI Validation Pipeline

This document tracks the required CI gates for the current polyglot XDR stack. CI should mirror local validation commands and must not hide runtime-specific failures inside a single monolithic job.

---

## Required Jobs

| Job | Runtime | Merge blocking purpose |
|---|---|---|
| `PHP / Laravel` | PHP 8.3 + PostgreSQL 16 | Laravel syntax, route cache validation, and full `php artisan test` |
| `PHP / Static analysis` | PHP 8.3 | Pint style gate and PHPStan/Larastan baseline-aware static analysis |
| `Frontend / Vite` | Node 20 | Lockfile install and production asset build |
| `Python / <suite>` | Python 3.11 | Endpoint agent, service workers, scripts, and governance Python suites |
| `Go / <module>` | Go 1.26.x | Go service/tool vetting and race-enabled tests |
| `Contracts / Governance` | Python 3.11 | Environment, rule registry, XDR contracts, fleet simulation, portable export, and sample adapters |
| `Compose / Production Image` | Docker Compose + Node + Python | Current Compose topology, production overlay, image build, and advisory image vulnerability scan; scanner/runtime errors block CI |
| `phase9-contract / contract-and-replay` | Python 3.11 | Golden event schema and deterministic rules replay validation; ML replay remains an artifact-aware MLOps check |

The stable branch-protection context for this workflow is `ci / Required Gate`. It aggregates all jobs above and fails when any group fails, is cancelled, or is skipped. Require it together with `phase9-contract / contract-and-replay`; individual matrix check names can then evolve without silently weakening branch protection. Long soak and cutover evidence remain manual gates.

---

## PHP / Laravel

```sh
composer install --no-interaction --prefer-dist --no-progress
cp .env.example .env
php artisan key:generate --ansi
php artisan route:cache
php artisan route:clear
php artisan test
```

Constraint: do not run parallel `php artisan test` jobs against the same PostgreSQL test database. The CI job uses one PostgreSQL service and one Laravel test runner. It does not run a separate `migrate:fresh` step because `phpunit.xml` pins the test database and Laravel `RefreshDatabase` migrates it once at suite startup.

---

## PHP / Static Analysis

```sh
composer install --no-interaction --prefer-dist --no-progress
./vendor/bin/pint --test <changed-php-files>
./vendor/bin/phpstan analyse --no-progress --no-interaction --memory-limit=1G
```

Pint checks PHP files changed by the current push or pull request, so historical formatting debt does not require a mass rewrite before the gate can be enabled. PHPStan still scans the full repository against the committed Larastan baseline, blocking new findings without requiring the historical static-analysis backlog to be cleared in the same change.

---

## Frontend / Vite

```sh
npm ci
npm run build
```

The generated `public/build/` directory is uploaded as a short-lived CI artifact.

---

## Python Matrix

Each suite is run independently. All jobs install pinned `requests` because shared integration validators import it; the alert-writer and incident-builder jobs additionally install their complete pinned service requirements before testing.

```sh
python -m compileall -q scripts services
python -m unittest discover -s tests/<suite> -p "test_*.py" -v
```

Current suites:

- `endpoint_agent`
- `alert_writer`
- `incident_builder`
- `ai_rag`
- `scripts`
- `xdr_topic_bootstrap`
- `demo_feed`
- `demo_causal_verify`

CI sets `PYTHONPYCACHEPREFIX=/tmp/xdr-pycache` on its isolated Ubuntu runners so bytecode generation does not depend on source-tree `__pycache__` permissions.

---

## Go Matrix

Each first-party Go module is tested independently:

```sh
go vet ./...
CGO_ENABLED=1 go test -race ./...
```

Current modules:

- `services/ingestion-gateway`
- `services/normalizer-worker`
- `services/correlation-worker`
- `services/log-connector-syslog`
- `services/log-connector-cloudtrail`
- `services/log-connector-guardduty`
- `services/log-connector-gcp-audit`
- `services/log-connector-o365`
- `tools/attack-simulator`
- `tools/xdr-scenario-runner`
- `tools/shared-go/mtls`
- `tools/shared-go/deliver`

Race tests require cgo. The GitHub Ubuntu runner has a C toolchain; local Windows validation may need plain `go test ./...` unless a C compiler is configured.

---

## Contracts / Governance

```sh
python -m compileall -q tools/shared-python/service-adapters
python scripts/xdr_shared_go_package_drift_validate.py
python scripts/validate_environment.py --profile local --env-file .env.example
python scripts/validate_environment.py --profile production --env-file .env.production.example --allow-placeholders
python scripts/xdr_rule_registry_validate.py
python scripts/xdr_contract_validate.py --output reports/ci_xdr_contract_validation.json
python scripts/xdr_fleet_simulation_validate.py
python scripts/export_portable_detector.py --output dist/detector-portable-ci --clean
python scripts/telemetry_adapters.py --adapter sysmon-json --input samples/real-world/sysmon_sample.jsonl --output storage/logs/ci_sysmon.jsonl
python scripts/telemetry_adapters.py --adapter zeek-conn --input samples/real-world/zeek_conn.log --output storage/logs/ci_zeek_conn.jsonl
python scripts/telemetry_adapters.py --adapter suricata-eve --input samples/real-world/suricata_eve.jsonl --output storage/logs/ci_suricata.jsonl
python scripts/telemetry_event_contract.py --file storage/logs/ci_sysmon.jsonl
python scripts/telemetry_enrichment.py --input storage/logs/ci_sysmon.jsonl --output storage/logs/ci_sysmon_enriched.jsonl
python scripts/threat_hunt.py --jsonl storage/logs/ci_sysmon_enriched.jsonl --output reports/ci_threat_hunt.json
```

Artifacts:

- `reports/*.json`
- `storage/logs/ci_*.jsonl`

---

## Compose / Production Image

```sh
npm ci
npm run build
cp .env.production.example .env
docker compose config --quiet
docker compose --env-file .env.production.example -f docker-compose.yml -f docker-compose.prod.yml config --quiet
docker compose --env-file .env.production.example -f infra/production/docker-compose.production.yml config --quiet
docker compose --project-name detector-ci --profile app build app
docker image ls --quiet --no-trunc --filter "label=com.docker.compose.project=detector-ci" --filter "label=com.docker.compose.service=app"
python scripts/xdr_container_image_scan.py --image <the-single-image-id-above> --output reports/ci_container_image_scan.json
```

The production overlay intentionally fails closed without required secrets, so CI validates it with `.env.production.example`.
The scan resolves the image through Compose build labels. `docker compose images` is intentionally not used because it reports images attached to created containers and returns nothing on a clean CI runner that only performed a build.

---

## Phase 9 Contract Workflow

`.github/workflows/phase9-contract.yml` remains separate, runs on every push and pull request so its required context is never missing, and validates:

```sh
python scripts/security_event_contract.py --file scripts/golden_runs/normal.jsonl
python scripts/security_event_contract.py --file scripts/golden_runs/bruteforce.jsonl
python scripts/security_event_contract.py --file scripts/golden_runs/scan.jsonl
python scripts/security_event_contract.py --file scripts/golden_runs/injection.jsonl
python -m unittest tests.scripts.test_golden_replay_test -v
python scripts/golden_replay_test.py --rules-only --output storage/app/golden_replay_report_ci.json
```

The required workflow uses explicit rules-only mode because the versioned repository does not ship the optional local `storage/app/ai_detector_model.pkl` artifact. When a model artifact is supplied outside CI, the same script validates ML label counts and score signatures as well. This workflow should stay required while replay determinism remains part of the release gate.

---

## Manual Gates

Mini soak and full 6h soak are not run on every push.

```sh
python scripts/xdr_correlation_soak.py --duration-minutes 5 --batch-size 5000 --sleep-ms 100 --output reports/xdr_correlation_mini_soak.json
powershell -ExecutionPolicy Bypass -File ./scripts/run_xdr_correlation_soak_6h.ps1
```

Full 6h soak is pre-cutover only and requires explicit operator approval.

---

## Blocked Promotions

Never promote to staged active if:

- Any required CI job fails.
- `ci / Required Gate` is absent or fails.
- `phase9-contract / contract-and-replay` fails.
- A target domain has no valid 6h soak PASS.
- Full 6h soak was skipped or run with less than 360 minutes.
- Domains beyond `identity-cloud` are included without domain-specific gate evidence.

---

## Not Covered By CI

- Production traffic validation.
- Permanent cutover decisions.
- Kubernetes migration readiness.
- Endpoint, DNS, proxy, firewall, or threat-intel active promotion.
- Manual branch protection/ruleset enforcement.
