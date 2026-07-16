# Shared Package Drift Guardrail

## Finding (INTERNAL-RUNTIME-SDK, 2026-07-14; Python family added 2026-07-16)

Three helper families are duplicated across first-party services, manually
copied rather than shared, so a future security or correctness fix applied
to one copy and not the others would silently drift with nothing catching
it:

- **mtls** — `internal/mtls/{mtls.go,mtls_test.go}`, confirmed byte-identical
  across all 8 Go services (3 core pipeline workers — `correlation-worker`,
  `ingestion-gateway`, `normalizer-worker` — plus the 5 `log-connector-*`
  services).
- **deliver** — `internal/deliver/{deliver.go,deliver_test.go}` (the
  CONN-DELIVERY-LOSS bounded-retry primitive), confirmed byte-identical
  across the 5 `log-connector-*` services.
- **python-service-adapters** — `xdr_event_contracts.py`, `traceparent.py`,
  `otlp_export.py`, `pg_pool.py`, `kafka_native.py`, confirmed byte-identical
  (sha256) between the 2 Python event-pipeline writers,
  `alert-writer-service` and `incident-builder-service`.

## Why not real cross-module extraction (evaluated and rejected for every family)

The obvious fix — a real shared Go module with `replace` directives, or a
real installable Python package — was tried and doesn't work cleanly here:
every service's `docker-compose.yml` build `context` is the service's own
directory (e.g. `context: ./services/correlation-worker`, not the repo
root). A `replace mymodule => ../../tools/shared-go/mtls` directive (or a
Python package installed from outside the build context) resolves fine for
a local or CI build (the whole repo is on disk there), but **breaks the
Docker build** — `COPY` cannot reach outside its build context, so the
referenced path simply wouldn't exist inside the build. Fixing that would
mean widening every service's Dockerfile build context to the repo root,
which is a materially larger, separate, riskier change (bigger build
context, every `COPY` path changes, real risk of quietly including
something the current narrow per-service context excludes) — not part of
this bounded phase. `GO-TOOLCHAIN-CONTRACT`'s decision not to add a
`go.work` file for the same underlying reason applies here too.

## What this phase does instead

`tools/shared-go/{mtls,deliver}/` and `tools/shared-python/service-adapters/`
are the canonical, independently buildable/testable sources (the Go ones
each have their own `go.mod`, `go build`/`go vet`/`go test` clean
standalone; the Python family's 5 files have no inter-file imports and each
`python -m py_compile`s clean standalone). The dependent services keep their
own local copies (so Docker builds are completely unaffected — zero
behavior change), but `scripts/xdr_shared_go_package_drift_validate.py` is
the actual guardrail — despite the Go-only filename (kept for historical/
reference-stability reasons rather than renamed mid-use), the drift-check
mechanism itself is just byte-for-byte file comparison and copying, so it
works identically for any family regardless of language. It fails if
**any** dependent's copy differs at all from its family's canonical source,
so a copy-paste fix applied to only one service can no longer merge
silently.

```powershell
python scripts/xdr_shared_go_package_drift_validate.py                                    # check all families (exit 0/1)
python scripts/xdr_shared_go_package_drift_validate.py --family=deliver                   # check just one family
python scripts/xdr_shared_go_package_drift_validate.py --family=python-service-adapters   # check just the Python family
python scripts/xdr_shared_go_package_drift_validate.py --sync                             # propagate canonical -> dependents, all families
python scripts/xdr_shared_go_package_drift_validate.py --sync --family=mtls               # propagate just one family
```

## How to make a legitimate change to a shared helper

1. Edit `tools/shared-go/<family>/*.go` or
   `tools/shared-python/service-adapters/*.py` — the canonical source, not
   any service's copy directly.
2. For a Go family, run `go build ./... && go vet ./... && go test ./...`
   inside `tools/shared-go/<family>/` to confirm it's still correct
   standalone. For the Python family, run
   `python -m py_compile tools/shared-python/service-adapters/*.py`.
3. Run `python scripts/xdr_shared_go_package_drift_validate.py --sync --family=<family>`
   to propagate the change to every dependent.
4. Run each affected service's own test suite (`go build ./... && go vet
   ./... && go test ./...` for Go; `python -m unittest discover -s
   tests/alert_writer` / `tests/incident_builder` for Python) to confirm
   the change doesn't break any consumer.

## Adding a fourth family

Add an entry to the `FAMILIES` dict at the top of
`scripts/xdr_shared_go_package_drift_validate.py` (canonical dir, file
list, dependent dirs), create `tools/shared-go/<family>/` or
`tools/shared-python/<family>/` with a copy of the (confirmed
byte-identical, e.g. via `sha256sum`) source, and run the validator to
confirm a clean `PASS` before relying on it.

## Still open

- **CI wiring** — adding the canonical modules to the Go/Python test
  matrices and a drift-check step to the pipeline is deferred:
  `.github/workflows/ci.yml` is under active, larger, uncommitted
  restructuring in this repo (CI-PIPELINE-RESTRUCTURE / CICD-POLYGLOT-COVERAGE)
  at the time this phase was written, and this project's own convention
  (see `SEC-HTTP-HEADERS`, `QA-STATIC-ANALYSIS` in `REVIEW_COMPLETED.md`) is
  not to commit CI wiring into a file mid-restructure by another concurrent
  change. Once that restructure lands, add the canonical Go modules to the
  `go-tests` job's matrix, and a
  `python scripts/xdr_shared_go_package_drift_validate.py` step to the
  governance job (which alone already covers all 3 families, Go and
  Python).
- **Full cross-module extraction** — remains blocked on the Docker
  build-context change described above; revisit if/when that's tackled for
  other reasons (e.g. as part of a future `CICD-IMMUTABLE-DELIVERY`-style
  pass).
