#!/usr/bin/env python3
"""Validate fail-closed production AI-RAG mutual TLS wiring."""

from __future__ import annotations

import json
import os
import subprocess
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
CERT_DIR = "/etc/xdr/internal-mtls"
LARAVEL_CLIENTS = ("app", "queue", "scheduler")


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
    init = local["internal-mtls-certs-init"]
    prod_init = prod["internal-mtls-certs-init"]
    if init["environment"].get("XDR_INTERNAL_MTLS_ENABLED") not in {"false", False}:
        errors.append("local internal mTLS must remain disabled")
    if prod_init["environment"].get("XDR_INTERNAL_MTLS_ENABLED") not in {"true", True}:
        errors.append("production internal mTLS must be enabled")
    if prod_init["environment"].get("XDR_INTERNAL_MTLS_REQUIRED") not in {"true", True}:
        errors.append("production certificate initialization must fail closed")
    command = " ".join(str(item) for item in init.get("entrypoint", []))
    for value in ("server.key", "client.key", "chmod 640", "chown 0:44444"):
        if value not in command:
            errors.append(f"certificate initializer missing {value}")

    ai = prod["ai-rag-service"]
    if ai["environment"].get("XDR_INTERNAL_MTLS_ENABLED") not in {"true", True}:
        errors.append("production AI-RAG server must require mTLS")
    if ai.get("depends_on", {}).get("internal-mtls-certs-init", {}).get("condition") != "service_completed_successfully":
        errors.append("AI-RAG must wait for certificate initialization")
    if mount(ai) is None or mount(ai).get("read_only") is not True:
        errors.append("AI-RAG must mount certificates read-only")
    if "44444" not in {str(group) for group in ai.get("group_add", [])}:
        errors.append("AI-RAG must receive the private-key supplemental group")

    for name in LARAVEL_CLIENTS:
        service = prod[name]
        env = service.get("environment", {})
        if env.get("XDR_AI_RAG_SERVICE_URL") != "https://ai-rag-service:8094":
            errors.append(f"{name} must use AI-RAG HTTPS")
        if env.get("XDR_INTERNAL_MTLS_ENABLED") not in {"true", True}:
            errors.append(f"{name} must enable the mTLS client")
        if service.get("depends_on", {}).get("internal-mtls-certs-init", {}).get("condition") != "service_completed_successfully":
            errors.append(f"{name} must wait for certificate initialization")
        if mount(service) is None or mount(service).get("read_only") is not True:
            errors.append(f"{name} must mount certificates read-only")
    return errors


def main() -> int:
    try:
        errors = validate(resolved(False), resolved(True))
    except (KeyError, OSError, RuntimeError, ValueError, subprocess.TimeoutExpired) as exc:
        errors = [str(exc)]
    report = {"status": "PASS" if not errors else "FAIL", "checks": 23, "errors": errors}
    print(json.dumps(report, indent=2))
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
