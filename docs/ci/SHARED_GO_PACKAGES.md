# Shared Go Package Drift Guardrail

## Finding (INTERNAL-RUNTIME-SDK, 2026-07-14)

Eight first-party Go services (3 core pipeline workers — `correlation-worker`,
`ingestion-gateway`, `normalizer-worker` — plus the 5 `log-connector-*`
services) each carry their own `internal/mtls/{mtls.go,mtls_test.go}` —
confirmed byte-identical across all 8 by checksum. A future security or
correctness fix applied to one copy and not the others would silently drift
with nothing catching it.

## Why not real cross-module extraction (evaluated and rejected for this pass)

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
part of this bounded first phase. `GO-TOOLCHAIN-CONTRACT`'s decision not to
add a `go.work` file for the same underlying reason applies here too.

## What this phase does instead

`tools/shared-go/mtls/` is now the canonical, independently
buildable/testable source (its own `go.mod`, buildable/testable standalone
via `go build`/`go vet`/`go test` — verified clean). The 8 services keep
their own local copies (so Docker builds are completely unaffected — zero
behavior change), but `scripts/xdr_shared_go_package_drift_validate.py` is
the actual guardrail: it fails if **any** dependent's copy differs at all
from the canonical source (byte-for-byte comparison), so a copy-paste fix
applied to only one service can no longer merge silently.

```powershell
python scripts/xdr_shared_go_package_drift_validate.py          # check (exit 0/1)
python scripts/xdr_shared_go_package_drift_validate.py --sync   # propagate canonical -> all 8 dependents
```

## How to make a legitimate change to the mTLS helper

1. Edit `tools/shared-go/mtls/mtls.go` (or `mtls_test.go`) — the canonical
   source, not any service's copy directly.
2. Run `go build ./... && go vet ./... && go test ./...` inside
   `tools/shared-go/mtls/` to confirm it's still correct standalone.
3. Run `python scripts/xdr_shared_go_package_drift_validate.py --sync` to
   propagate the change to all 8 dependents.
4. Run `go build ./... && go vet ./... && go test ./...` in each of the 8
   affected service directories (or `go-tests` CI, once wired — see below)
   to confirm the change doesn't break any consumer.

## Still open

- **CI wiring** — adding `tools/shared-go/mtls` to the Go test matrix and a
  drift-check step to the pipeline is deferred: `.github/workflows/ci.yml`
  is under active, larger, uncommitted restructuring in this repo
  (CI-PIPELINE-RESTRUCTURE / CICD-POLYGLOT-COVERAGE) at the time this phase
  was written, and this project's own convention (see `SEC-HTTP-HEADERS`,
  `QA-STATIC-ANALYSIS` in `REVIEW_COMPLETED.md`) is not to commit CI wiring
  into a file mid-restructure by another concurrent change. Once that
  restructure lands, add `tools/shared-go/mtls` to the `go-tests` job's
  matrix and a `python scripts/xdr_shared_go_package_drift_validate.py` step
  to the governance job.
- **Other duplicated helper families** — the backlog also named delivery
  retry logic (5 log-connectors) and Python event-contract/tracing/OTLP/
  pool/Kafka adapter modules (2 Python writers) as duplicated. Deliberately
  out of scope for this pass, matching the backlog's own "migrate one
  helper family at a time" guidance — mTLS was chosen first because it was
  the one family confirmed byte-identical (zero risk of behavior drift from
  deduplicating it) and is the most security-sensitive of the three.
- **Full cross-module extraction** — remains blocked on the Docker
  build-context change described above; revisit if/when that's tackled for
  other reasons (e.g. as part of a future `CICD-IMMUTABLE-DELIVERY`-style
  pass).
