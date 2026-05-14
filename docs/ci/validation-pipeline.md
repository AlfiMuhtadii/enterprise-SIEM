# CI Validation Pipeline

Documents the validation steps for CI/CD integration. All validations use the same commands as local validation — CI does not use special modes or mocked dependencies.

---

## Validation Order

```
Step 1  Docker compose config validation     (every push)
Step 2  Laravel test suite                   (every push)
Step 3  Event contract validation            (every push)
Step 4  Replay validation                    (every push)
Step 5  Resilience validation                (every push)
Step 6  Mini soak (5 minutes)               (release branches / on demand)
Step 7  Full 6h soak                        (pre-cutover only, manual)
```

Steps 1–5 block merges. Steps 6–7 are run explicitly before promotion decisions.

---

## Step 1: Docker Compose Validation

```sh
docker compose config --quiet
```

- Exit code 0, no output = **PASS**
- Any output or non-zero exit = **FAIL** (pipeline blocked)

---

## Step 2: Laravel Test Suite

```sh
php artisan test
```

- All tests green, zero failures = **PASS**
- Any failure = **FAIL** (pipeline blocked)

Constraint: single test runner instance per PostgreSQL test database. Do not parallelize `php artisan test`.

---

## Step 3: Event Contract Validation

```sh
python scripts/xdr_contract_validate.py --output reports/xdr_contract_validation.json
```

- All contracts valid = **PASS**
- Any violation = **FAIL** (pipeline blocked)
- Artifact: `reports/xdr_contract_validation.json`

---

## Step 4: Replay Validation

```sh
python scripts/xdr_event_flow_resilience_validate.py \
    --replays 3 \
    --restart-services 0 \
    --send-malformed 1 \
    --output reports/xdr_event_flow_resilience_validation.json
```

Pass criteria:
- Replay results consistent across 3 replays
- Malformed events rejected without consumer crash
- No goroutine growth

Artifact: `reports/xdr_event_flow_resilience_validation.json`

---

## Step 5: Resilience Validation

```sh
python scripts/xdr_event_flow_resilience_validate.py \
    --replays 3 \
    --restart-services 1 \
    --send-malformed 1 \
    --output reports/xdr_event_flow_resilience_validation.json
```

Pass criteria:
- Consumer reconnects and resumes after restart
- No events lost after reconnect
- No goroutine growth

---

## Step 6: Mini Soak (Release Branches / On Demand)

```sh
python scripts/xdr_correlation_soak.py \
    --duration-minutes 5 \
    --batch-size 5000 \
    --sleep-ms 100 \
    --output reports/xdr_correlation_mini_soak.json
```

Pass criteria (all required):

| Gate | Threshold |
|---|---|
| fallback_count | = 0 |
| failure_count | = 0 |
| status_failures | = 0 |
| p95_latency_ms | < 300 ms |
| goroutine_growth | = 0 |
| memory stable | no sustained growth |

Artifact: `reports/xdr_correlation_mini_soak.json`

A mini soak PASS is required before scheduling a full 6h soak. It does not authorize staged cutover.

---

## Step 7: Full 6h Soak (Pre-Cutover Only)

```sh
powershell -ExecutionPolicy Bypass -File ./scripts/run_xdr_correlation_soak_6h.ps1
```

Run manually with explicit approval. Not triggered automatically.

All gates must pass. See `docs/validation/xdr_6h_soak_pass.md` for gate definitions and current evidence.

Artifacts:
- `reports/xdr_correlation_soak_6h.json`
- `reports/xdr_correlation_soak_fallback_debug.json`

---

## Artifact Retention

| Artifact | When | Retention |
|---|---|---|
| `reports/xdr_contract_validation.json` | Steps 1–5 (every push) | Per build |
| `reports/xdr_event_flow_resilience_validation.json` | Steps 1–5 | Per build |
| `reports/xdr_correlation_mini_soak.json` | Step 6 (on demand) | Per run |
| `reports/xdr_correlation_soak_6h.json` | Step 7 (pre-cutover) | Archive permanently |
| `reports/xdr_correlation_soak_fallback_debug.json` | Step 7 | Archive permanently |

---

## Blocked Promotions

Never promote to staged active if:
- Any step above (1–7) failed for the target scope
- Full 6h soak was not run with `--duration-minutes 360`
- Soak report shows any gate out of range
- Steps were manually skipped or bypassed
- Domains beyond identity-cloud are included in the scope

---

## What CI Does Not Cover

- Permanent cutover decisions — those require explicit human approval and sustained operational evidence beyond one soak
- Endpoint/DNS/proxy/firewall domain promotion — not authorized; these domains require separate domain-specific gate evidence
- Production traffic validation — the soak validates the Go worker under test traffic; production traffic patterns may differ
