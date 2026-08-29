#!/usr/bin/env python3
"""Validate production normalizer mTLS and ingestion client-only TLS wiring."""

from __future__ import annotations

import json
import os
import subprocess
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
CERT_DIR = "/etc/xdr/internal-mtls"
LARAVEL_CLIENTS = ("app", "queue", "scheduler")
CERT_PATHS = {
    "XDR_INTERNAL_MTLS_CA": f"{CERT_DIR}/ca.crt",
    "XDR_INTERNAL_MTLS_SERVER_CERT": f"{CERT_DIR}/server.crt",
    "XDR_INTERNAL_MTLS_SERVER_KEY": f"{CERT_DIR}/server.key",
    "XDR_INTERNAL_MTLS_CLIENT_CERT": f"{CERT_DIR}/client.crt",
    "XDR_INTERNAL_MTLS_CLIENT_KEY": f"{CERT_DIR}/client.key",
}


def resolved(production: bool) -> dict[str, Any]:
    command = ["docker", "compose", "--profile", "app", "--profile", "strangler"]
    environment = os.environ.copy()
    environment["XDR_INGESTION_MTLS_SERVER_ENABLED"] = "false"
    environment["XDR_INGESTION_MTLS_CLIENT_ENABLED"] = "false"
    environment["XDR_NORMALIZER_MTLS_ENABLED"] = "false"
    if production:
        command += ["--env-file", ".env.production.example", "-f", "docker-compose.yml", "-f", "docker-compose.prod.yml"]
    command += ["config", "--format", "json"]
    result = subprocess.run(command, cwd=ROOT, env=environment, capture_output=True, text=True, timeout=30)
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip())
    return json.loads(result.stdout)


def mount(service: dict[str, Any]) -> dict[str, Any] | None:
    return next((item for item in service.get("volumes", []) if item.get("target") == CERT_DIR), None)


def enabled(value: Any) -> bool:
    return value in {"true", True}


def validate(base: dict[str, Any], production: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    local = base["services"]
    prod = production["services"]
    local_ingestion = local["ingestion-gateway"]
    ingestion = prod["ingestion-gateway"]
    local_normalizer = local["normalizer-worker"]
    normalizer = prod["normalizer-worker"]

    if enabled(local_ingestion["environment"].get("XDR_INTERNAL_MTLS_ENABLED")):
        errors.append("local ingestion mTLS server must remain disabled")
    if enabled(local_ingestion["environment"].get("XDR_INTERNAL_MTLS_CLIENT_ENABLED")):
        errors.append("local ingestion mTLS client must remain disabled")
    if enabled(ingestion["environment"].get("XDR_INTERNAL_MTLS_ENABLED")):
        errors.append("production ingestion server must remain external-client compatible")
    if not enabled(ingestion["environment"].get("XDR_INTERNAL_MTLS_CLIENT_ENABLED")):
        errors.append("production ingestion normalizer client must enable mTLS")
    if ingestion["environment"].get("XDR_NORMALIZER_METRICS_URL") != "https://normalizer-worker:8092/metrics":
        errors.append("ingestion must poll normalizer metrics over HTTPS")
    for name, path in CERT_PATHS.items():
        if ingestion["environment"].get(name) != path:
            errors.append(f"ingestion {name} must be {path}")
    if mount(ingestion) is None or mount(ingestion).get("read_only") is not True:
        errors.append("ingestion must mount certificates read-only")
    if "44444" not in {str(group) for group in ingestion.get("group_add", [])}:
        errors.append("ingestion must receive the private-key supplemental group")
    if ingestion.get("depends_on", {}).get("internal-mtls-certs-init", {}).get("condition") != "service_completed_successfully":
        errors.append("ingestion must wait for certificate initialization")
    if not any(int(port.get("target", 0)) == 8091 for port in ingestion.get("ports", [])):
        errors.append("production ingestion must retain its external telemetry port")

    if enabled(local_normalizer["environment"].get("XDR_INTERNAL_MTLS_ENABLED")):
        errors.append("local normalizer mTLS must remain disabled")
    if not enabled(normalizer["environment"].get("XDR_INTERNAL_MTLS_ENABLED")):
        errors.append("production normalizer must require mTLS")
    for name, path in CERT_PATHS.items():
        if normalizer["environment"].get(name) != path:
            errors.append(f"normalizer {name} must be {path}")
    if normalizer.get("depends_on", {}).get("internal-mtls-certs-init", {}).get("condition") != "service_completed_successfully":
        errors.append("normalizer must wait for certificate initialization")
    if mount(normalizer) is None or mount(normalizer).get("read_only") is not True:
        errors.append("normalizer must mount certificates read-only")
    if "44444" not in {str(group) for group in normalizer.get("group_add", [])}:
        errors.append("normalizer must receive the private-key supplemental group")
    if normalizer.get("ports"):
        errors.append("production normalizer must not publish its mTLS port to the host")

    expected_urls = {
        "XDR_NORMALIZER_WORKER_URL": "https://normalizer-worker:8092",
        "XDR_NORMALIZER_URL": "https://normalizer-worker:8092",
        "XDR_NORMALIZER_METRICS_URL": "https://normalizer-worker:8092",
    }
    for name in LARAVEL_CLIENTS:
        service = prod[name]
        env = service.get("environment", {})
        for variable, url in expected_urls.items():
            if env.get(variable) != url:
                errors.append(f"{name} {variable} must use normalizer HTTPS")
        if not enabled(env.get("XDR_INTERNAL_MTLS_ENABLED")):
            errors.append(f"{name} must enable the mTLS client")
        if service.get("depends_on", {}).get("internal-mtls-certs-init", {}).get("condition") != "service_completed_successfully":
            errors.append(f"{name} must wait for certificate initialization")
        if mount(service) is None or mount(service).get("read_only") is not True:
            errors.append(f"{name} must mount certificates read-only")
        if "44444" not in {str(group) for group in service.get("group_add", [])}:
            errors.append(f"{name} must receive the private-key supplemental group")

    initializer = prod["internal-mtls-certs-init"]
    if not enabled(initializer["environment"].get("XDR_INTERNAL_MTLS_REQUIRED")):
        errors.append("production certificate initialization must fail closed")
    command = " ".join(str(item) for item in initializer.get("entrypoint", []))
    for value in ("server.key", "client.key", "chmod 640", "chown 0:44444"):
        if value not in command:
            errors.append(f"certificate initializer missing {value}")

    callers = (
        ROOT / "app" / "Console" / "Commands" / "DlqReplayCommand.php",
        ROOT / "app" / "Services" / "ResilienceValidationService.php",
        ROOT / "app" / "Console" / "Commands" / "XdrStranglerStatusCommand.php",
    )
    for caller in callers:
        if "InternalMtlsHttpClient::request" not in caller.read_text(encoding="utf-8"):
            errors.append(f"{caller.relative_to(ROOT)} must use the mTLS-aware client")

    ingestion_source = (ROOT / "services" / "ingestion-gateway" / "main.go").read_text(encoding="utf-8")
    if 'envBool("XDR_INTERNAL_MTLS_CLIENT_ENABLED", serverEnabled)' not in ingestion_source:
        errors.append("ingestion client mTLS override must retain legacy fallback")
    if ingestion_source.index("t.TLSClientConfig = clientTLSCfg") > ingestion_source.index("gw.startMetricsPoller"):
        errors.append("ingestion must configure TLS before starting the metrics poller")
    if '"normalizer_metrics_poll_successes"' not in ingestion_source:
        errors.append("ingestion must expose successful normalizer poll evidence")
    return errors


def main() -> int:
    try:
        errors = validate(resolved(False), resolved(True))
    except (KeyError, OSError, RuntimeError, ValueError, subprocess.TimeoutExpired) as exc:
        errors = [str(exc)]
    report = {"status": "PASS" if not errors else "FAIL", "checks": 57, "errors": errors}
    print(json.dumps(report, indent=2))
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
