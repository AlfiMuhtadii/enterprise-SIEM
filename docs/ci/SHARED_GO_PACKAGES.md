# Shared Go Package Drift Guardrail

## Finding (INTERNAL-RUNTIME-SDK, 2026-07-14)

Two Go helper families are duplicated across first-party services, manually
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

## Why not real cross-module extraction (evaluated and rejected for both families)

The obvious fix — a real shared Go module + `replace` directives in each
consumer's `go.mod` — was tried and doesn't work cleanly here: every Go
service's `docker-compose.yml` build `context` is the service's own
directory (e.g. `context: ./services/correlation-worker`, not the repo
root). A `replace mymodule => ../../tools/shared-go/mtls` directive resolves
fine for a local or CI `go build` (the whole repo is on disk there), but
**breaks the Docker build** — `COPY` cannot reach outside its build context,
so the replaced path simply wouldn't exist inside the build. Fixing that
would mean widening every Go service's Dockerfile build context to the repo
root, which is a materially larger, separate, riskier change (bigger build
context, every `COPY` path in 8 Dockerfiles changes, real risk of quietly
including something the current narrow per-service context excludes) — not
part of this bounded phase. `GO-TOOLCHAIN-CONTRACT`'s decision not to add a
`go.work` file for the same underlying reason applies here too.

## What this phase does instead

`tools/shared-go/{mtls,deliver}/` are the canonical, independently
buildable/testable sources (each its own `go.mod`, `go build`/`go vet`/
`go test` clean standalone). The dependent services keep their own local
copies (so Docker builds are completely unaffected — zero behavior change),
but `scripts/xdr_shared_go_package_drift_validate.py` is the actual
guardrail: it fails if **any** dependent's copy differs at all from its
family's canonical source (byte-for-byte comparison), so a copy-paste fix
applied to only one service can no longer merge silently.

```powershell
python scripts/xdr_shared_go_package_drift_validate.py                     # check all families (exit 0/1)
python scripts/xdr_shared_go_package_drift_validate.py --family=deliver    # check just one family
python scripts/xdr_shared_go_package_drift_validate.py --sync              # propagate canonical -> dependents, all families
python scripts/xdr_shared_go_package_drift_validate.py --sync --family=mtls  # propagate just one family
```

## How to make a legitimate change to a shared helper

1. Edit `tools/shared-go/<family>/*.go` — the canonical source, not any
   service's copy directly.
2. Run `go build ./... && go vet ./... && go test ./...` inside
   `tools/shared-go/<family>/` to confirm it's still correct standalone.
3. Run `python scripts/xdr_shared_go_package_drift_validate.py --sync --family=<family>`
   to propagate the change to every dependent.
4. Run `go build ./... && go vet ./... && go test ./...` in each affected
   service directory (or `go-tests` CI, once wired — see below) to confirm
   the change doesn't break any consumer.

## Adding a third family

Add an entry to the `FAMILIES` dict at the top of
`scripts/xdr_shared_go_package_drift_validate.py` (canonical dir, file
list, dependent dirs), create `tools/shared-go/<family>/` with a copy of
the (confirmed byte-identical) source + its own minimal `go.mod`, and run
the validator to confirm a clean `PASS` before relying on it.

## Still open

- **CI wiring** — adding `tools/shared-go/{mtls,deliver}` to the Go test
  matrix and a drift-check step to the pipeline is deferred:
  `.github/workflows/ci.yml` is under active, larger, uncommitted
  restructuring in this repo (CI-PIPELINE-RESTRUCTURE / CICD-POLYGLOT-COVERAGE)
  at the time this phase was written, and this project's own convention
  (see `SEC-HTTP-HEADERS`, `QA-STATIC-ANALYSIS` in `REVIEW_COMPLETED.md`) is
  not to commit CI wiring into a file mid-restructure by another concurrent
  change. Once that restructure lands, add both canonical modules to the
  `go-tests` job's matrix and a
  `python scripts/xdr_shared_go_package_drift_validate.py` step to the
  governance job.
- **Python duplication** — the backlog also named Python
  event-contract/tracing/OTLP/pool/Kafka adapter modules duplicated between
  `alert-writer-service` and `incident-builder-service` as a third
  duplicated family. Deliberately out of scope for this pass, matching the
  backlog's own "migrate one helper family at a time" guidance, and not yet
  confirmed byte-identical the way mtls/deliver were (would need its own
  parity check before applying the same pattern).
- **Full cross-module extraction** — remains blocked on the Docker
  build-context change described above; revisit if/when that's tackled for
  other reasons (e.g. as part of a future `CICD-IMMUTABLE-DELIVERY`-style
  pass).
