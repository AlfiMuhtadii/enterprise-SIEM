#!/usr/bin/env python3
"""INTERNAL-RUNTIME-SDK: drift check for Go helper code duplicated across
first-party services.

Each "family" below is a Go helper confirmed byte-identical across the
services that copy it, but manually copied, so a future security/
correctness fix applied to one copy and not the others would silently
drift and go unnoticed:

  - mtls: internal/mtls/{mtls.go,mtls_test.go}, 8 services (3 core pipeline
    workers + 5 log-connectors).
  - deliver: internal/deliver/{deliver.go,deliver_test.go}, the 5
    log-connectors' bounded-retry delivery primitive (CONN-DELIVERY-LOSS).

Real cross-module Go package extraction (a shared module + `replace`
directives) was evaluated and rejected for both families: every Go
service's docker-compose.yml build `context` is the service's own
directory (e.g. `./services/correlation-worker`), so a
`replace ... => ../../<shared>` directive would resolve fine for a
local/CI `go build` (whole repo on disk) but break the Docker build
entirely (COPY cannot reach outside its build context). Fixing that would
mean widening every Go Dockerfile's build context to the repo root -- a
materially larger, separate, riskier change, not part of this bounded
phase.

Instead: tools/shared-go/<family>/ is the canonical, independently
buildable/testable source for each family (its own go.mod). This script is
the actual guardrail -- it fails if a dependent's copy differs at all from
the canonical source, so a copy-paste fix applied to only one service can
no longer merge silently. Run with --sync to update all dependents from the
canonical source (the correct way to make a legitimate change); --family
scopes either mode to a single family (default: check/sync all of them).
"""
from __future__ import annotations

import argparse
import filecmp
import shutil
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

FAMILIES: dict[str, dict] = {
    "mtls": {
        "canonical_dir": ROOT / "tools" / "shared-go" / "mtls",
        "files": ["mtls.go", "mtls_test.go"],
        "dependents": [
            ROOT / "services" / "correlation-worker" / "internal" / "mtls",
            ROOT / "services" / "ingestion-gateway" / "internal" / "mtls",
            ROOT / "services" / "normalizer-worker" / "internal" / "mtls",
            ROOT / "services" / "log-connector-cloudtrail" / "internal" / "mtls",
            ROOT / "services" / "log-connector-gcp-audit" / "internal" / "mtls",
            ROOT / "services" / "log-connector-guardduty" / "internal" / "mtls",
            ROOT / "services" / "log-connector-o365" / "internal" / "mtls",
            ROOT / "services" / "log-connector-syslog" / "internal" / "mtls",
        ],
    },
    "deliver": {
        "canonical_dir": ROOT / "tools" / "shared-go" / "deliver",
        "files": ["deliver.go", "deliver_test.go"],
        "dependents": [
            ROOT / "services" / "log-connector-cloudtrail" / "internal" / "deliver",
            ROOT / "services" / "log-connector-gcp-audit" / "internal" / "deliver",
            ROOT / "services" / "log-connector-guardduty" / "internal" / "deliver",
            ROOT / "services" / "log-connector-o365" / "internal" / "deliver",
            ROOT / "services" / "log-connector-syslog" / "internal" / "deliver",
        ],
    },
}


def _check_family(name: str, family: dict) -> tuple[bool, list[str], list[str]]:
    canonical_dir: Path = family["canonical_dir"]
    files: list[str] = family["files"]
    dependents: list[Path] = family["dependents"]

    drifted: list[str] = []
    missing: list[str] = []

    for dep_dir in dependents:
        for filename in files:
            dep_file = dep_dir / filename
            if not dep_file.is_file():
                missing.append(str(dep_file.relative_to(ROOT)))
                continue
            if not filecmp.cmp(canonical_dir / filename, dep_file, shallow=False):
                drifted.append(str(dep_file.relative_to(ROOT)))

    return (not drifted and not missing), drifted, missing


def _sync_family(family: dict) -> int:
    canonical_dir: Path = family["canonical_dir"]
    files: list[str] = family["files"]
    dependents: list[Path] = family["dependents"]

    for dep_dir in dependents:
        dep_dir.mkdir(parents=True, exist_ok=True)
        for filename in files:
            shutil.copyfile(canonical_dir / filename, dep_dir / filename)

    return len(dependents)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--family",
        choices=sorted(FAMILIES),
        help="Limit to a single family (default: check/sync all families).",
    )
    parser.add_argument(
        "--sync",
        action="store_true",
        help="Overwrite every dependent's copy with the canonical source instead of just checking.",
    )
    args = parser.parse_args()

    families = {args.family: FAMILIES[args.family]} if args.family else FAMILIES

    for name, family in families.items():
        for filename in family["files"]:
            if not (family["canonical_dir"] / filename).is_file():
                print(f"status=ERROR  family={name}  canonical file missing: {family['canonical_dir'] / filename}")
                return 2

    if args.sync:
        for name, family in families.items():
            dep_count = _sync_family(family)
            print(f"status=SYNCED  family={name}  dependents={dep_count}  files_per_dependent={len(family['files'])}")
        return 0

    overall_ok = True
    for name, family in families.items():
        ok, drifted, missing = _check_family(name, family)
        overall_ok = overall_ok and ok
        total_checked = len(family["dependents"]) * len(family["files"])
        status = "PASS" if ok else "FAIL"
        canonical_rel = family["canonical_dir"].relative_to(ROOT)
        print(f"status={status}  family={name}  canonical={canonical_rel}  checked={total_checked}  drifted={len(drifted)}  missing={len(missing)}")
        for f in missing:
            print(f"  MISSING  {f}")
        for f in drifted:
            print(f"  DRIFT    {f}  (differs from canonical -- run with --sync to fix, or update the canonical source first if the drift is the intended change)")

    return 0 if overall_ok else 1


if __name__ == "__main__":
    sys.exit(main())
