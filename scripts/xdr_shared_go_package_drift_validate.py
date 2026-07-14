#!/usr/bin/env python3
"""INTERNAL-RUNTIME-SDK: drift check for Go helper code duplicated across
first-party services.

Eight Go services (3 core pipeline workers + 5 log-connectors) each carry
their own copy of internal/mtls/{mtls.go,mtls_test.go} -- byte-identical
today (confirmed via checksum before this script existed), but manually
copied, so a future security/correctness fix applied to one copy and not
the others would silently drift and go unnoticed.

Real cross-module Go package extraction (a shared module + `replace`
directives) was evaluated and rejected for this pass: every Go service's
docker-compose.yml build `context` is the service's own directory
(e.g. `./services/correlation-worker`), so a `replace ... => ../../<shared>`
directive would resolve fine for a local/CI `go build` (whole repo on disk)
but break the Docker build entirely (COPY cannot reach outside its build
context). Fixing that would mean widening every Go Dockerfile's build
context to the repo root -- a materially larger, separate, riskier change,
not part of this bounded first phase.

Instead: tools/shared-go/mtls/ is the canonical, independently
buildable/testable source (its own go.mod, its own CI job). This script is
the actual guardrail -- it fails if a dependent's copy differs at all from
the canonical source, so a copy-paste fix applied to only one service can
no longer merge silently. Run with --sync to update all dependents from the
canonical source (the correct way to make a legitimate change).
"""
from __future__ import annotations

import argparse
import filecmp
import shutil
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

CANONICAL_DIR = ROOT / "tools" / "shared-go" / "mtls"
FILES = ["mtls.go", "mtls_test.go"]

DEPENDENT_DIRS = [
    ROOT / "services" / "correlation-worker" / "internal" / "mtls",
    ROOT / "services" / "ingestion-gateway" / "internal" / "mtls",
    ROOT / "services" / "normalizer-worker" / "internal" / "mtls",
    ROOT / "services" / "log-connector-cloudtrail" / "internal" / "mtls",
    ROOT / "services" / "log-connector-gcp-audit" / "internal" / "mtls",
    ROOT / "services" / "log-connector-guardduty" / "internal" / "mtls",
    ROOT / "services" / "log-connector-o365" / "internal" / "mtls",
    ROOT / "services" / "log-connector-syslog" / "internal" / "mtls",
]


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--sync",
        action="store_true",
        help="Overwrite every dependent's copy with the canonical source instead of just checking.",
    )
    args = parser.parse_args()

    for filename in FILES:
        if not (CANONICAL_DIR / filename).is_file():
            print(f"status=ERROR  canonical file missing: {CANONICAL_DIR / filename}")
            return 2

    if args.sync:
        for dep_dir in DEPENDENT_DIRS:
            dep_dir.mkdir(parents=True, exist_ok=True)
            for filename in FILES:
                shutil.copyfile(CANONICAL_DIR / filename, dep_dir / filename)
        print(f"status=SYNCED  dependents={len(DEPENDENT_DIRS)}  files_per_dependent={len(FILES)}")
        return 0

    drifted: list[str] = []
    missing: list[str] = []

    for dep_dir in DEPENDENT_DIRS:
        for filename in FILES:
            dep_file = dep_dir / filename
            if not dep_file.is_file():
                missing.append(str(dep_file.relative_to(ROOT)))
                continue
            if not filecmp.cmp(CANONICAL_DIR / filename, dep_file, shallow=False):
                drifted.append(str(dep_file.relative_to(ROOT)))

    total_checked = len(DEPENDENT_DIRS) * len(FILES)
    failures = drifted + missing
    status = "PASS" if not failures else "FAIL"

    print(f"status={status}  canonical={CANONICAL_DIR.relative_to(ROOT)}  checked={total_checked}  drifted={len(drifted)}  missing={len(missing)}")
    for f in missing:
        print(f"  MISSING  {f}")
    for f in drifted:
        print(f"  DRIFT    {f}  (differs from canonical -- run with --sync to fix, or update the canonical source first if the drift is the intended change)")

    return 0 if status == "PASS" else 1


if __name__ == "__main__":
    sys.exit(main())
