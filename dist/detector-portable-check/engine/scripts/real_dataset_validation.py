#!/usr/bin/env python3
"""
Replay/import real telemetry datasets and measure operational validation metrics.
"""

from __future__ import annotations

import argparse
import json
import subprocess
import sys
import time
from pathlib import Path
from typing import Any, Dict, List


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Validate detector with real telemetry datasets")
    parser.add_argument("--manifest", default="storage/app/real_dataset_manifest.json")
    parser.add_argument("--output", default="reports/real_dataset_validation.json")
    parser.add_argument("--minutes", type=int, default=120)
    return parser.parse_args()


def run(cmd: List[str], root: Path) -> Dict[str, Any]:
    start = time.time()
    proc = subprocess.run(cmd, cwd=str(root), text=True, capture_output=True)
    return {"cmd": cmd, "returncode": proc.returncode, "duration_sec": time.time() - start, "stdout": proc.stdout, "stderr": proc.stderr}


def default_manifest(path: Path) -> Dict[str, Any]:
    payload = {
        "datasets": [
            {"name": "sysmon sample", "adapter": "sysmon-json", "input": "storage/tmp/sysmon_sample.jsonl", "enabled": True}
        ],
        "coverage": "tools/attack-lab/coverage/mitre-advanced-coverage.json",
        "labels": "tools/attack-lab/coverage/telemetry-benchmark-labels.json"
    }
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2), encoding="utf-8")
    return payload


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    manifest_path = (root / args.manifest).resolve()
    manifest = json.loads(manifest_path.read_text(encoding="utf-8")) if manifest_path.exists() else default_manifest(manifest_path)
    runs = []
    normalized_files = []
    for ds in manifest.get("datasets", []):
        if ds.get("enabled") is False:
            continue
        name = str(ds.get("name", ds.get("adapter", "dataset"))).replace(" ", "_")
        out = f"storage/logs/normalized_{name}.jsonl"
        runs.append(run([sys.executable, "scripts/telemetry_adapters.py", "--adapter", ds["adapter"], "--input", ds["input"], "--output", out], root))
        normalized_files.append(out)
        runs.append(run([sys.executable, "scripts/telemetry_event_contract.py", "--file", out], root))
        runs.append(run([sys.executable, "scripts/ingest_telemetry_events.py", "--file", out, "--from-start"], root))
    runs.append(run([sys.executable, "scripts/telemetry_rule_engine.py", "--minutes", str(args.minutes)], root))
    runs.append(run([sys.executable, "scripts/alert_deduplicator.py", "--minutes", str(args.minutes)], root))
    runs.append(run([sys.executable, "scripts/incident_manager.py", "--minutes", str(args.minutes)], root))
    coverage = run([sys.executable, "scripts/mitre_coverage_matrix.py", "--expectations", manifest.get("coverage", "tools/attack-lab/coverage/mitre-advanced-coverage.json"), "--minutes", str(args.minutes)], root)
    benchmark = run([sys.executable, "scripts/detection_benchmark.py", "--labels", manifest.get("labels", "tools/attack-lab/coverage/telemetry-benchmark-labels.json"), "--minutes", str(args.minutes)], root)
    stability_a = run([sys.executable, "scripts/telemetry_rule_engine.py", "--minutes", str(args.minutes), "--dry-run"], root)
    stability_b = run([sys.executable, "scripts/telemetry_rule_engine.py", "--minutes", str(args.minutes), "--dry-run"], root)
    report = {
        "manifest": str(manifest_path),
        "normalized_files": normalized_files,
        "runs": runs,
        "coverage": coverage,
        "benchmark": benchmark,
        "replay_stability": {
            "first_returncode": stability_a["returncode"],
            "second_returncode": stability_b["returncode"],
            "stable_stdout": stability_a["stdout"] == stability_b["stdout"],
            "first_stdout": stability_a["stdout"],
            "second_stdout": stability_b["stdout"],
        },
    }
    out = (root / args.output).resolve()
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
    print(f"output={out}")
    print(f"datasets={len(normalized_files)}")
    print(f"coverage_rc={coverage['returncode']}")
    print(f"benchmark_rc={benchmark['returncode']}")
    print(f"replay_stable={report['replay_stability']['stable_stdout']}")
    return 0 if coverage["returncode"] == 0 and benchmark["returncode"] == 0 else 2


if __name__ == "__main__":
    raise SystemExit(main())
