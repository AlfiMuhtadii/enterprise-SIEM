#!/usr/bin/env python3
"""
Export a portable detector package that can be deployed beside another app.
"""

from __future__ import annotations

import argparse
import shutil
from pathlib import Path
from typing import Iterable, Tuple


SCRIPT_FILES = [
    "requirements-ingest.txt",
    "security_event_contract.py",
    "stream_producer_kafka.py",
    "realtime_detector_kafka_consumer.py",
    "realtime_detector_consumer.py",
    "ingest_security_events.py",
    "telemetry_event_contract.py",
    "ingest_telemetry_events.py",
    "telemetry_correlation_detector.py",
    "xdr_correlation_detector.py",
    "xdr_validation_report.py",
    "xdr_stream_bus.py",
    "xdr_infra_clients.py",
    "xdr_setup_infra.py",
    "xdr_distributed_validate.py",
    "xdr_generate_large_dataset.py",
    "xdr_generate_realistic_dataset.py",
    "xdr_operational_validate.py",
    "xdr_strangler_e2e_validate.py",
    "xdr_correlation_shadow_benchmark.py",
    "xdr_generate_identity_cloud_golden.py",
    "telemetry_adapters.py",
    "telemetry_rule_engine.py",
    "rule_quality_manager.py",
    "real_dataset_validation.py",
    "alert_deduplicator.py",
    "incident_manager.py",
    "soc_workflow.py",
    "storage_maintenance.py",
    "storage_partition_manager.py",
    "integration_exporter.py",
    "quality_history_recorder.py",
    "incident_archive_export.py",
    "telemetry_stream_worker.py",
    "entity_graph_builder.py",
    "detection_benchmark.py",
    "false_positive_evaluator.py",
    "generate_telemetry_sample.py",
    "sync_postgres_to_clickhouse.py",
    "clickhouse_sync_daemon.py",
    "detector_coverage_matrix.py",
    "mitre_coverage_matrix.py",
    "train_ai_detector.py",
    "train_anomaly_profile.py",
    "mlops_register_model.py",
    "mlops_ensure_active_deployment.py",
    "mlops_drift_monitor.py",
    "mlops_retrain_policy.py",
    "security_audit.py",
    "update_detector_allowlist.py",
    "update_detector_thresholds.py",
    "validate_environment.py",
    "load_test_soc.py",
    "ci-local.ps1",
    "demo-package-start.ps1",
    "generate_demo_screenshots.py",
    "endpoint_telemetry_agent.py",
    "telemetry_enrichment.py",
    "threat_hunt.py",
    "incident_intelligence.py",
    "detection_quality_report.py",
    "build_agent_package.py",
]

MODEL_FILES = [
    "ai_detector_model.pkl",
    "ai_detector_report.json",
    "ai_detector_feature_importance.json",
    "ai_detector_model_card.json",
    "anomaly_profile.json",
    "detector_correlation.json",
    "telemetry_correlation.json",
    "telemetry_baseline.json",
    "telemetry_rules.json",
    "normal_telemetry_patterns.json",
    "real_dataset_manifest.json",
    "detector_thresholds.json",
    "detector_allowlist.json",
]


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Export portable detector runtime package.")
    parser.add_argument("--output", default="dist/detector-portable")
    parser.add_argument("--clean", action="store_true")
    return parser.parse_args()


def copy_file(src: Path, dst: Path) -> bool:
    if not src.exists():
        print(f"skip missing: {src}")
        return False
    dst.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(src, dst)
    print(f"copy: {src} -> {dst}")
    return True


def copy_tree(src: Path, dst: Path) -> None:
    if not src.exists():
        print(f"skip missing dir: {src}")
        return
    if dst.exists():
        shutil.rmtree(dst)
    shutil.copytree(src, dst)
    print(f"copy dir: {src} -> {dst}")


def write_manifest(path: Path, copied: Iterable[Tuple[str, str]]) -> None:
    lines = [
        "# Portable Detector Manifest",
        "",
        "Generated package layout:",
        "",
    ]
    for src, dst in copied:
        lines.append(f"- `{dst}` from `{src}`")
    lines.extend(
        [
            "",
            "Start with `README.md`, then follow `docs/DEPLOY_TO_NEW_SYSTEM.md`.",
            "",
        ]
    )
    path.write_text("\n".join(lines), encoding="utf-8")


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    out = (root / args.output).resolve()

    if args.clean and out.exists():
        shutil.rmtree(out)
    out.mkdir(parents=True, exist_ok=True)

    copied: list[Tuple[str, str]] = []

    for file_name in SCRIPT_FILES:
        src = root / "scripts" / file_name
        dst = out / "engine" / "scripts" / file_name
        if copy_file(src, dst):
            copied.append((str(src.relative_to(root)), str(dst.relative_to(out))))

    for file_name in MODEL_FILES:
        src = root / "storage" / "app" / file_name
        dst = out / "storage" / "app" / file_name
        if copy_file(src, dst):
            copied.append((str(src.relative_to(root)), str(dst.relative_to(out))))

    for rel in [
        "infra/redpanda",
        "infra/analytics",
        "infra/production",
        "infra/distributed",
        "database/migrations",
        "database/seeders",
        "tools/attack-lab",
        "samples/real-world",
        "docs/architecture",
        "docs/demo",
        "deploy/agent",
        "services/ingestion-gateway",
        "services/normalizer-worker",
        "services/correlation-worker",
        "services/alert-writer-service",
        "services/incident-builder-service",
        "services/ai-rag-service",
        "portable/adapters",
        "portable/docs",
    ]:
        src = root / rel
        dst = out / rel.replace("portable/", "")
        copy_tree(src, dst)
        copied.append((rel, str(dst.relative_to(out))))

    copy_file(root / "portable" / "README.md", out / "README.md")
    copied.append(("portable/README.md", "README.md"))

    for doc_name in [
        "README_PRODUCTION_DEPLOYMENT.md",
        "README_BACKUP_RECOVERY.md",
        "README_OPERATIONAL_RUNBOOKS.md",
        "README_ENVIRONMENTS.md",
        "README_PERFORMANCE_TESTING.md",
        "README_DEMO_PACKAGE.md",
        "README_ARCHITECTURE.md",
        "README_PHASE_NEXT_TELEMETRY_INTELLIGENCE.md",
        "README_ENDPOINT_AGENT_OPS.md",
        "README_AGENT_MANAGEMENT.md",
        "README_REALTIME_ENDPOINT_RESPONSE.md",
        "README_INVESTIGATION_HUNTING.md",
        "README_SOC_DETECTION_TUNING_TI_PLAYBOOK_REPORTING.md",
        "README_SOC_AI_KNOWLEDGE_MATURITY.md",
        "README_SOC_LLM_RAG_GUARDRAILS.md",
        "README_SOC_EXTERNAL_TI_ADVANCED_RAG_AI_EVAL.md",
        "README_ENTERPRISE_READINESS_HA.md",
        "README_FINAL_PORTFOLIO.md",
        "README_XDR_ARCHITECTURE.md",
        "README_XDR_DISTRIBUTED_ARCHITECTURE.md",
        "README_XDR_REAL_INFRASTRUCTURE.md",
        "README_XDR_MATURITY.md",
        "README_XDR_STRANGLER_OPERATIONAL_VALIDATION.md",
    ]:
        if copy_file(root / doc_name, out / "docs" / doc_name):
            copied.append((doc_name, f"docs/{doc_name}"))

    for env_name in [".env.local.example", ".env.staging.example", ".env.production.example"]:
        if copy_file(root / env_name, out / env_name):
            copied.append((env_name, env_name))

    write_manifest(out / "MANIFEST.md", copied)
    print(f"Portable package ready: {out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
