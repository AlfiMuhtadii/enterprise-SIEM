#!/usr/bin/env python3
"""Validate fail-closed production correlation-worker mutual TLS wiring."""

from __future__ import annotations

import json
import os
import subprocess
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
CERT_DIR = "/etc/xdr/internal-mtls"
LARAVEL_CLIENTS = ("app", "queue", "scheduler")
SERVER_PATHS = {
    "XDR_INTERNAL_MTLS_CA": f"{CERT_DIR}/ca.crt",
    "XDR_INTERNAL_MTLS_SERVER_CERT": f"{CERT_DIR}/server.crt",
    "XDR_INTERNAL_MTLS_SERVER_KEY": f"{CERT_DIR}/server.key",
    "XDR_INTERNAL_MTLS_CLIENT_CERT": f"{CERT_DIR}/client.crt",
    "XDR_INTERNAL_MTLS_CLIENT_KEY": f"{CERT_DIR}/client.key",
}


def resolved(production: bool) -> dict[str, Any]:
    command = ["docker", "compose", "--profile", "app", "--profile", "strangler"]
    environment = os.environ.copy()
    if production:
        command += ["--env-file", ".env.production.example", "-f", "docker-compose.yml", "-f", "docker-compose.prod.yml"]
    else:
        environment["XDR_INTERNAL_MTLS_ENABLED"] = "false"
        environment["XDR_INTERNAL_MTLS_REQUIRED"] = "false"
    command += ["config", "--format", "json"]
    result = subprocess.run(command, cwd=ROOT, env=environment, capture_output=True, text=True, timeout=30)
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip())
    return json.loads(result.stdout)


def mount(service: dict[str, Any]) -> dict[str, Any] | None:
    return next((item for item in service.get("volumes", []) if item.get("target") == CERT_DIR), None)


def validate(base: dict[str, Any], production: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    local = base["services"]
    prod = production["services"]
    local_worker = local["correlation-worker"]
    worker = prod["correlation-worker"]

    if local_worker["environment"].get("XDR_INTERNAL_MTLS_ENABLED") not in {"false", False}:
        errors.append("local correlation-worker mTLS must remain disabled")
    if worker["environment"].get("XDR_INTERNAL_MTLS_ENABLED") not in {"true", True}:
        errors.append("production correlation-worker must require mTLS")
    for name, path in SERVER_PATHS.items():
        if worker["environment"].get(name) != path:
            errors.append(f"correlation-worker {name} must be {path}")
    if worker.get("depends_on", {}).get("internal-mtls-certs-init", {}).get("condition") != "service_completed_successfully":
        errors.append("correlation-worker must wait for certificate initialization")
    if mount(worker) is None or mount(worker).get("read_only") is not True:
        errors.append("correlation-worker must mount certificates read-only")
    if "44444" not in {str(group) for group in worker.get("group_add", [])}:
        errors.append("correlation-worker must receive the private-key supplemental group")
    if worker.get("ports"):
        errors.append("production correlation-worker must not publish its mTLS port to the host")

    initializer = prod["internal-mtls-certs-init"]
    if initializer["environment"].get("XDR_INTERNAL_MTLS_REQUIRED") not in {"true", True}:
        errors.append("production certificate initialization must fail closed")
    command = " ".join(str(item) for item in initializer.get("entrypoint", []))
    for value in ("server.key", "client.key", "chmod 640", "chown 0:44444"):
        if value not in command:
            errors.append(f"certificate initializer missing {value}")

    for name in LARAVEL_CLIENTS:
        service = prod[name]
        env = service.get("environment", {})
        if env.get("XDR_CORRELATION_WORKER_URL") != "https://correlation-worker:8093":
            errors.append(f"{name} must use correlation-worker HTTPS")
        if env.get("XDR_INTERNAL_MTLS_ENABLED") not in {"true", True}:
            errors.append(f"{name} must enable the mTLS client")
        if service.get("depends_on", {}).get("internal-mtls-certs-init", {}).get("condition") != "service_completed_successfully":
            errors.append(f"{name} must wait for certificate initialization")
        if mount(service) is None or mount(service).get("read_only") is not True:
            errors.append(f"{name} must mount certificates read-only")
        if "44444" not in {str(group) for group in service.get("group_add", [])}:
            errors.append(f"{name} must receive the private-key supplemental group")

    callers = (
        ROOT / "app" / "Support" / "XdrCorrelationCutover.php",
        ROOT / "app" / "Console" / "Commands" / "XdrStranglerStatusCommand.php",
    )
    for caller in callers:
        source = caller.read_text(encoding="utf-8")
        if "InternalMtlsHttpClient::request" not in source:
            errors.append(f"{caller.relative_to(ROOT)} must use the mTLS-aware client")
    return errors


def main() -> int:
    try:
        errors = validate(resolved(False), resolved(True))
    except (KeyError, OSError, RuntimeError, ValueError, subprocess.TimeoutExpired) as exc:
        errors = [str(exc)]
    report = {"status": "PASS" if not errors else "FAIL", "checks": 33, "errors": errors}
    print(json.dumps(report, indent=2))
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
