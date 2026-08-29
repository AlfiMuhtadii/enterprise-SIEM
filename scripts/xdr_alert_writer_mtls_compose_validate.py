#!/usr/bin/env python3
"""Validate fail-closed production alert-writer mutual TLS wiring."""

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
    environment["XDR_ALERT_WRITER_MTLS_ENABLED"] = "false"
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
    local_writer = local["alert-writer-service"]
    writer = prod["alert-writer-service"]

    if enabled(local_writer["environment"].get("XDR_INTERNAL_MTLS_ENABLED")):
        errors.append("local alert-writer mTLS must remain disabled")
    if not enabled(writer["environment"].get("XDR_INTERNAL_MTLS_ENABLED")):
        errors.append("production alert-writer must require mTLS")
    for name, path in CERT_PATHS.items():
        if writer["environment"].get(name) != path:
            errors.append(f"alert-writer {name} must be {path}")
    if writer.get("depends_on", {}).get("internal-mtls-certs-init", {}).get("condition") != "service_completed_successfully":
        errors.append("alert-writer must wait for certificate initialization")
    if mount(writer) is None or mount(writer).get("read_only") is not True:
        errors.append("alert-writer must mount certificates read-only")
    if "44444" not in {str(group) for group in writer.get("group_add", [])}:
        errors.append("alert-writer must receive the private-key supplemental group")
    if writer.get("ports"):
        errors.append("production alert-writer must not publish its mTLS port to the host")

    for name in LARAVEL_CLIENTS:
        service = prod[name]
        env = service.get("environment", {})
        if env.get("XDR_ALERT_WRITER_URL") != "https://alert-writer-service:8095":
            errors.append(f"{name} must use alert-writer HTTPS")
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

    separated = (ROOT / "app" / "Support" / "XdrSeparatedServiceMetrics.php").read_text(encoding="utf-8")
    strangler = (ROOT / "app" / "Console" / "Commands" / "XdrStranglerStatusCommand.php").read_text(encoding="utf-8")
    resilience = (ROOT / "app" / "Services" / "ResilienceValidationService.php").read_text(encoding="utf-8")
    if "InternalMtlsHttpClient::request" not in separated:
        errors.append("separated service metrics must use the mTLS-aware client")
    if "InternalMtlsHttpClient::request" not in strangler:
        errors.append("strangler health checks must use the mTLS-aware client")
    if "InternalMtlsHttpClient::request" not in resilience:
        errors.append("resilience health checks must use the mTLS-aware client")
    if "env('XDR_ALERT_WRITER_URL'" not in resilience:
        errors.append("resilience alert-writer URL must be production-overridable")

    entrypoint = (ROOT / "services" / "alert-writer-service" / "docker-entrypoint.sh").read_text(encoding="utf-8")
    healthcheck = (ROOT / "services" / "alert-writer-service" / "docker-healthcheck.py").read_text(encoding="utf-8")
    dockerfile = (ROOT / "services" / "alert-writer-service" / "Dockerfile").read_text(encoding="utf-8")
    if "--ssl-cert-reqs 2" not in entrypoint:
        errors.append("alert-writer Uvicorn must require client certificates")
    if "XDR_INTERNAL_MTLS_SERVER_CERT is required" not in entrypoint:
        errors.append("alert-writer entrypoint must fail closed on missing server identity")
    if "ssl.create_default_context" not in healthcheck:
        errors.append("alert-writer healthcheck must verify the private CA")
    if "ctx.load_cert_chain" not in healthcheck:
        errors.append("alert-writer healthcheck must present a client certificate")
    if "CMD python docker-healthcheck.py 8095" not in dockerfile:
        errors.append("alert-writer image must retain its mTLS-aware healthcheck")
    return errors


def main() -> int:
    try:
        errors = validate(resolved(False), resolved(True))
    except (KeyError, OSError, RuntimeError, ValueError, subprocess.TimeoutExpired) as exc:
        errors = [str(exc)]
    report = {"status": "PASS" if not errors else "FAIL", "checks": 40, "errors": errors}
    print(json.dumps(report, indent=2))
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
