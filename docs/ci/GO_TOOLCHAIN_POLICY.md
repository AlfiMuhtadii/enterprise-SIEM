# Go Toolchain Policy

## Finding (GO-TOOLCHAIN-CONTRACT, 2026-07-14)

Before this pass, `go.mod` `go` directives were spread across 4 versions with no
stated policy: `1.22` (`tools/attack-simulator`), `1.23` (`tools/xdr-scenario-runner`),
`1.24` (5 log-connectors), `1.25.0` (3 core pipeline workers — correlation-worker,
ingestion-gateway, normalizer-worker). Every Go Dockerfile except one already used
`golang:1.26-alpine` (digest-pinned); `tools/attack-simulator/Dockerfile` was the
outlier, on an unpinned `golang:1.22-alpine` tag. CI (`ci.yml`'s `go-tests` job) already
pinned `actions/setup-go` to `1.26.x` and ran `go vet`/`go test -race` across all 10
first-party modules — so a Go CI job did exist, it just wasn't provably aligned with
either the `go.mod` directives or the Docker builder version.

## Policy

**Preferred toolchain: Go 1.26.** This was already the de facto standard — the version
CI and 7 of 8 Go Dockerfiles were already built against — so this pass aligns the
remaining declarations to match reality rather than picking a new version:

- All 10 first-party `go.mod` files now declare `go 1.26`.
- All 8 containerized Go services build from the same digest-pinned
  `golang:1.26-alpine@sha256:3ad57304ad93bbec8548a0437ad9e06a455660655d9af011d58b993f6f615648`
  (`tools/xdr-scenario-runner` has no Dockerfile — it's a bare CLI, never containerized).
- CI's `go-tests` job (`.github/workflows/ci.yml`) pins `actions/setup-go@v5` to
  `go-version: "1.26.x"`.

All three now agree. There is no `toolchain` directive in any module — the `go`
line is a floor, and every module builds/vets/tests cleanly under 1.26 locally and in CI,
so no per-module minimum-version exception is currently justified. If a future module
genuinely needs a lower floor (e.g., a vendored/third-party tool with an older
constraint), state that reason directly in this file before diverging — silent drift is
exactly what this pass fixed.

## Module inventory (in lieu of `go.work`)

No `go.work` workspace file was added. `ci.yml`'s `go-tests` job already carries an
explicit matrix listing all 10 first-party modules and runs `go vet ./...` +
`go test -race ./...` against each — that matrix *is* the inventory this policy needs,
and adding a `go.work` on top would change module resolution/build behavior for modules
that are intentionally independent (own `go.mod`, own dependency graph, own release
cadence) without adding anything a `go build`/`go test` run doesn't already prove. Add
`go.work` only if a real need to build/test multiple modules as one unit shows up (e.g.
cross-module refactors that need simultaneous local `replace` directives).

## What's still open

CI validates every module's source (`go build`/`go vet`/`go test -race`) against the
1.26 toolchain, but no CI job builds the actual per-service Docker images (the
`compose-image` job only builds the PHP/Node `app` image) — so a Docker-layer version
drift like the one this pass fixed would not have been caught by CI on its own. Adding
a Go Docker-image build+scan job to CI is separate scope, tracked alongside
`CI-SAST-DEPSCAN`'s existing Trivy image-scan gap.
