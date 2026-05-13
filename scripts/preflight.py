#!/usr/bin/env python3
"""
Demo preflight checks (hard dependencies + health).
"""

from __future__ import annotations

import argparse
import shutil
import subprocess
import sys
import urllib.request
from pathlib import Path
from typing import List, Tuple


def run(cmd: List[str], cwd: Path) -> Tuple[int, str, str]:
    p = subprocess.run(cmd, cwd=str(cwd), text=True, capture_output=True)
    return p.returncode, p.stdout.strip(), p.stderr.strip()


def check_url(url: str, timeout: int = 5) -> bool:
    try:
        with urllib.request.urlopen(url, timeout=timeout) as resp:
            return resp.status < 500
    except Exception:
        return False


def main() -> int:
    parser = argparse.ArgumentParser(description="Preflight for deterministic demo")
    parser.add_argument("--base-url", default="http://127.0.0.1:8000")
    parser.add_argument("--skip-app", action="store_true")
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]

    failures: List[str] = []
    for cmd in ["docker", "php", "python"]:
        if shutil.which(cmd) is None:
            failures.append(f"missing command: {cmd}")

    rc, out, err = run(
        [
            sys.executable,
            "-c",
            "import importlib.util; raise SystemExit(0 if importlib.util.find_spec('confluent_kafka') else 1)",
        ],
        root,
    )
    if rc != 0:
        failures.append("missing Python package: confluent-kafka (run: python -m pip install -r scripts/requirements-ingest.txt)")

    if failures:
        for f in failures:
            print(f"FAIL: {f}")
        return 2

    checks = [
        ("compose services", ["docker", "compose", "ps"]),
    ]
    for name, cmd in checks:
        rc, out, err = run(cmd, root)
        if rc != 0:
            failures.append(f"{name}: docker compose failed: {err or out}")
            continue
        if "Up" not in out:
            failures.append(f"{name}: no running container detected")

    # Check Redpanda REST, ClickHouse HTTP, Grafana, app
    endpoints = [
        ("redpanda rest", "http://127.0.0.1:8082/topics"),
        ("clickhouse", "http://127.0.0.1:8123/ping"),
        ("grafana", "http://127.0.0.1:3000/login"),
    ]
    if not args.skip_app:
        endpoints.append(("app", f"{args.base_url}/login"))
    for name, url in endpoints:
        if not check_url(url):
            failures.append(f"{name}: unreachable ({url})")

    if failures:
        for f in failures:
            print(f"FAIL: {f}")
        print("Preflight: FAILED")
        return 2

    print("Preflight: PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
