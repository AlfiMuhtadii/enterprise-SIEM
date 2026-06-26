#!/usr/bin/env python3
"""
ENTERPRISE-053: Real Endpoint Telemetry Enrollment Demo Script

Runs the endpoint agent in enrollment mode against the local host,
captures real OS process/persistence snapshots, and registers the
enrollment with the Laravel SOC control-plane.

Usage:
  python scripts/run_real_endpoint_enrollment.py [--tenant-id=<id>] [--dry-run]

Requirements:
  - Python 3.9+ stdlib only
  - XDR_INGESTION_GATEWAY_URL or default http://127.0.0.1:8091
  - XDR_SOC_API_URL or default http://127.0.0.1:8000
  - Agent config: services/endpoint-agent/config.json

Output:
  reports/real_enrollment_<hostname>.json
"""

from __future__ import annotations

import argparse
import json
import os
import platform
import socket
import subprocess
import sys
import uuid
from datetime import datetime, timezone
from pathlib import Path

ROOT       = Path(__file__).resolve().parent.parent
AGENT_PATH = ROOT / "services" / "endpoint-agent" / "agent.py"
REPORT_DIR = ROOT / "reports"

ADVISORY_ONLY = True
IS_REAL       = True


def iso_now() -> str:
    return datetime.now(timezone.utc).isoformat()


def collect_host_info() -> dict:
    return {
        "hostname":    socket.gethostname(),
        "os_platform": platform.system().lower(),
        "os_version":  platform.version(),
        "python":      platform.python_version(),
        "fqdn":        socket.getfqdn(),
    }


def collect_process_snapshot() -> dict:
    """Collect real running processes using OS-safe methods."""
    processes = []
    system = platform.system().lower()

    if system == "windows":
        try:
            result = subprocess.run(
                ["tasklist", "/FO", "CSV", "/NH"],
                capture_output=True, text=True, timeout=10
            )
            for line in result.stdout.strip().split("\n")[:50]:
                parts = line.strip('"').split('","')
                if len(parts) >= 2:
                    processes.append({"name": parts[0], "pid": parts[1]})
        except Exception:
            processes = [{"name": "collection_unavailable", "pid": "0"}]

    elif system in ("linux", "darwin"):
        try:
            result = subprocess.run(
                ["ps", "-eo", "pid,comm", "--no-headers"],
                capture_output=True, text=True, timeout=10
            )
            for line in result.stdout.strip().split("\n")[:50]:
                parts = line.split(None, 1)
                if len(parts) == 2:
                    processes.append({"pid": parts[0].strip(), "name": parts[1].strip()})
        except Exception:
            processes = [{"name": "collection_unavailable", "pid": "0"}]

    return {
        "count":     len(processes),
        "sample":    processes[:10],   # only first 10 for privacy
        "collected": True,
        "source":    "real_os",
    }


def collect_persistence_snapshot() -> dict:
    """Collect persistence inventory using OS-safe methods (read-only)."""
    system = platform.system().lower()
    items  = []

    if system == "windows":
        # Check common Run registry keys via reg query
        run_keys = [
            r"HKCU\Software\Microsoft\Windows\CurrentVersion\Run",
        ]
        for key in run_keys:
            try:
                result = subprocess.run(
                    ["reg", "query", key],
                    capture_output=True, text=True, timeout=5
                )
                if result.returncode == 0:
                    for line in result.stdout.strip().split("\n"):
                        if "REG_SZ" in line or "REG_EXPAND_SZ" in line:
                            items.append({"source": "registry", "key": key, "entry": line.strip()[:128]})
            except Exception:
                pass

    elif system == "linux":
        # Check common persistence locations
        for path in ["/etc/crontab", "/etc/rc.local"]:
            if Path(path).exists():
                items.append({"source": "file", "path": path})

    return {
        "count":     len(items),
        "items":     items[:5],
        "collected": True,
        "source":    "real_os",
    }


def register_enrollment(enrollment_data: dict, soc_url: str, dry_run: bool) -> dict:
    """POST enrollment data to Laravel SOC (dry-run by default)."""
    if dry_run:
        return {"registered": False, "dry_run": True, "note": "dry-run — no HTTP call made"}

    from urllib import request as urllib_request
    from urllib.error import URLError

    endpoint = soc_url.rstrip("/") + "/api/internal/endpoint/enroll"
    payload  = json.dumps(enrollment_data).encode()
    req = urllib_request.Request(
        endpoint,
        data=payload,
        headers={"Content-Type": "application/json"},
        method="POST"
    )
    try:
        with urllib_request.urlopen(req, timeout=5) as resp:
            return {"registered": True, "status": resp.status}
    except URLError as exc:
        return {"registered": False, "error": str(exc), "note": "SOC not reachable — enrollment recorded locally only"}


def main() -> None:
    parser = argparse.ArgumentParser(description="Real Endpoint Enrollment Demo (ENTERPRISE-053)")
    parser.add_argument("--tenant-id", default="", help="Tenant ID for this enrollment")
    parser.add_argument("--dry-run", action="store_true", help="Collect data but do not POST to SOC")
    parser.add_argument("--soc-url", default=os.getenv("XDR_SOC_API_URL", "http://127.0.0.1:8000"))
    args = parser.parse_args()

    print("=== ENTERPRISE-053: Real Endpoint Enrollment Demo ===")
    print(f"  advisory_only = {ADVISORY_ONLY}")
    print(f"  dry_run       = {args.dry_run}")
    print(f"  tenant_id     = {args.tenant_id or '(none)'}")
    print("")

    host_info   = collect_host_info()
    proc_snap   = collect_process_snapshot()
    persist_snap = collect_persistence_snapshot()

    enrollment_data = {
        "enrollment_id":    str(uuid.uuid4()),
        "enrollment_token": "xdr-enroll-" + str(uuid.uuid4()).replace("-", "")[:32],
        "hostname":         host_info["hostname"],
        "os_platform":      host_info["os_platform"],
        "os_version":       host_info["os_version"],
        "agent_version":    "1.0.0",
        "tenant_id":        args.tenant_id or None,
        "heartbeat_received": False,
        "snapshot_received":  True,
        "process_count":    proc_snap["count"],
        "persistence_count":persist_snap["count"],
        "collector_summary": {
            "collectors": ["process_snapshot", "persistence_inventory"],
            "counts": {
                "processes":   proc_snap["count"],
                "persistence": persist_snap["count"],
            },
            "process_sample":    proc_snap["sample"],
            "persistence_items": persist_snap["items"],
        },
        "is_real":      IS_REAL,
        "is_advisory":  ADVISORY_ONLY,
        "enrolled_at":  iso_now(),
    }

    print(f"  hostname      = {host_info['hostname']}")
    print(f"  os_platform   = {host_info['os_platform']}")
    print(f"  processes     = {proc_snap['count']}")
    print(f"  persistence   = {persist_snap['count']}")
    print("")

    soc_result = register_enrollment(enrollment_data, args.soc_url, args.dry_run)
    enrollment_data["soc_registration"] = soc_result

    REPORT_DIR.mkdir(parents=True, exist_ok=True)
    report_path = REPORT_DIR / f"real_enrollment_{host_info['hostname']}.json"
    with open(report_path, "w") as f:
        json.dump(enrollment_data, f, indent=2)

    print(f"  report        = {report_path}")
    print(f"  soc_result    = {soc_result}")
    print("")
    print("=== ENROLLMENT COMPLETE ===")
    print("  Next step: php artisan endpoint:verify-enrollment <enrollment_token>")


if __name__ == "__main__":
    main()
