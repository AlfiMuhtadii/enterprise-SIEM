#!/usr/bin/env python3
"""Setup and validate Redpanda, ClickHouse, OpenSearch, and Qdrant for XDR runtime."""

from __future__ import annotations

import argparse
import json
import subprocess
from pathlib import Path

from xdr_infra_clients import clients_from_env


REQUIRED_TOPICS = [
    "telemetry.raw",
    "telemetry.normalized",
    "xdr.alerts",
    "alerts.created",
    "incidents.updated",
    "ai.analysis.requests",
    "ai.analysis.results",
    "xdr.alerts.dlq",
    "alerts.created.dlq",
    "incidents.builder.dlq",
    "incidents.updated.dlq",
]


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Setup distributed XDR infrastructure")
    parser.add_argument("--output", default="reports/xdr_infra_setup.json")
    return parser.parse_args()


def setup_redpanda_topics() -> dict:
    """Create required topics in local Docker Redpanda when available."""
    result = {
        "ok": False,
        "container": "detector-redpanda",
        "topics": REQUIRED_TOPICS,
        "stdout": "",
        "stderr": "",
        "returncode": None,
    }
    cmd = ["docker", "exec", "detector-redpanda", "rpk", "topic", "create", *REQUIRED_TOPICS]
    try:
        proc = subprocess.run(cmd, text=True, capture_output=True, timeout=30)
    except Exception as exc:
        result["stderr"] = str(exc)
        return result
    result["stdout"] = proc.stdout
    result["stderr"] = proc.stderr
    result["returncode"] = proc.returncode
    already_exists_only = proc.returncode != 0 and "TOPIC_ALREADY_EXISTS" in (proc.stdout + proc.stderr)
    result["ok"] = proc.returncode == 0 or already_exists_only
    return result


def main() -> int:
    args = parse_args()
    redpanda, clickhouse, opensearch, qdrant = clients_from_env()
    report = {
        "health": {
            "redpanda": redpanda.health(),
            "clickhouse": clickhouse.health(),
            "opensearch": opensearch.health(),
            "qdrant": qdrant.health(),
        },
        "setup": {
            "redpanda_topics": setup_redpanda_topics(),
            "clickhouse": clickhouse.setup_schema(),
            "opensearch": opensearch.setup_indexes(),
            "qdrant": qdrant.setup_collection(),
        },
    }
    path = Path(args.output)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(report, indent=2, default=str), encoding="utf-8")
    print(f"output={path}")
    for name, health in report["health"].items():
        print(f"{name}: {'ok' if health.get('ok') else 'failed'}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
