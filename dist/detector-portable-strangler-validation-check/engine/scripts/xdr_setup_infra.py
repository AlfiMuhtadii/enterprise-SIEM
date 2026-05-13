#!/usr/bin/env python3
"""Setup and validate Redpanda, ClickHouse, OpenSearch, and Qdrant for XDR runtime."""

from __future__ import annotations

import argparse
import json
from pathlib import Path

from xdr_infra_clients import clients_from_env


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Setup distributed XDR infrastructure")
    parser.add_argument("--output", default="reports/xdr_infra_setup.json")
    return parser.parse_args()


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
