#!/usr/bin/env python3
"""Validate plaintext-local and TLS-only production OTLP/Tempo wiring."""

from __future__ import annotations

import argparse
import json
import re
import subprocess
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
OTLP_ENDPOINT = "https://tempo:4318/v1/traces"
CERT_DIR = "/etc/xdr/internal-mtls"
EXPORTER_SERVICES = (
    "app",
    "queue",
    "scheduler",
    "ingestion-gateway",
    "normalizer-worker",
    "correlation-worker",
    "alert-writer-service",
    "incident-builder-service",
)


def resolved_config(production: bool) -> dict[str, Any]:
    command = ["docker", "compose", "--profile", "app", "--profile", "strangler", "--profile", "observability"]
    if production:
        command += [
            "--env-file",
            ".env.production.example",
            "-f",
            "docker-compose.yml",
            "-f",
            "docker-compose.prod.yml",
        ]
    command += ["config", "--format", "json"]
    result = subprocess.run(command, cwd=ROOT, capture_output=True, text=True, timeout=30, check=False)
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip())
    return json.loads(result.stdout)


def _mount(service: dict[str, Any], target: str) -> dict[str, Any] | None:
    return next((item for item in service.get("volumes", []) if item.get("target") == target), None)


def validate_configs(
    base: dict[str, Any],
    production: dict[str, Any],
    *,
    base_tempo_config: str | None = None,
    tls_tempo_config: str | None = None,
    cert_generator: str | None = None,
    production_env: str | None = None,
) -> list[str]:
    errors: list[str] = []
    base_services = base.get("services", {})
    prod_services = production.get("services", {})
    tempo = base_services.get("tempo", {})
    prod_tempo = prod_services.get("tempo", {})

    base_tempo_config = base_tempo_config if base_tempo_config is not None else (ROOT / "infra/analytics/tempo/tempo.yaml").read_text(encoding="utf-8")
    tls_tempo_config = tls_tempo_config if tls_tempo_config is not None else (ROOT / "infra/analytics/tempo/tempo-tls.yaml").read_text(encoding="utf-8")
    cert_generator = cert_generator if cert_generator is not None else (ROOT / "scripts/xdr_generate_internal_mtls_certs.py").read_text(encoding="utf-8")
    production_env = production_env if production_env is not None else (ROOT / ".env.production.example").read_text(encoding="utf-8")

    if "observability" not in tempo.get("profiles", []):
        errors.append("local Tempo must remain opt-in through the observability profile")
    if "tls:" in base_tempo_config:
        errors.append("local Tempo receiver must remain plaintext")
    local_ports = {str(item.get("target")) for item in tempo.get("ports", [])}
    if not {"3200", "4318"}.issubset(local_ports):
        errors.append("local Tempo must retain localhost query and OTLP ports")

    if prod_tempo.get("profiles"):
        errors.append("production Tempo must start without an opt-in profile")
    if prod_tempo.get("ports"):
        errors.append("production Tempo must not publish host ports")
    command = " ".join(str(item) for item in prod_tempo.get("command", []))
    if "-config.file=/etc/tempo-tls.yaml" not in command:
        errors.append("production Tempo must load the TLS receiver configuration")
    if prod_tempo.get("depends_on", {}).get("internal-mtls-certs-init", {}).get("condition") != "service_completed_successfully":
        errors.append("production Tempo must wait for successful certificate initialization")
    cert_mount = _mount(prod_tempo, CERT_DIR)
    if cert_mount is None or cert_mount.get("read_only") is not True:
        errors.append("production Tempo certificate mount must be read-only")
    config_mount = _mount(prod_tempo, "/etc/tempo-tls.yaml")
    if config_mount is None or config_mount.get("read_only") is not True:
        errors.append("production Tempo TLS configuration mount must be read-only")
    groups = {str(group) for group in prod_tempo.get("group_add", [])}
    if "44444" not in groups:
        errors.append("production Tempo must receive only the shared certificate reader group")

    required_tls_tokens = (
        "endpoint: 0.0.0.0:4318",
        f"cert_file: {CERT_DIR}/server.crt",
        f"key_file: {CERT_DIR}/server.key",
        'min_version: "1.2"',
    )
    for token in required_tls_tokens:
        if token not in tls_tempo_config:
            errors.append(f"Tempo TLS receiver configuration missing {token}")
    for forbidden in ("client_ca_file:", "insecure_skip_verify"):
        if forbidden in tls_tempo_config:
            errors.append(f"Tempo TLS receiver configuration contains forbidden {forbidden.rstrip(':')}")
    if re.search(r"(?m)^\s+grpc:\s*$", tls_tempo_config):
        errors.append("Tempo production receiver must not expose unused OTLP gRPC")

    for name in EXPORTER_SERVICES:
        service = prod_services.get(name, {})
        environment = service.get("environment", {})
        if environment.get("XDR_OTEL_EXPORTER_ENDPOINT") != OTLP_ENDPOINT:
            errors.append(f"{name} must use the production Tempo HTTPS endpoint")
        if environment.get("XDR_OTEL_CA_CERT") != f"{CERT_DIR}/ca.crt":
            errors.append(f"{name} must verify Tempo with the mounted private CA")
        mount = _mount(service, CERT_DIR)
        if mount is None or mount.get("read_only") is not True:
            errors.append(f"{name} must mount Tempo CA material read-only")

    if '"tempo"' not in cert_generator:
        errors.append("internal certificate SAN list must include tempo")
    if f"XDR_OTEL_EXPORTER_ENDPOINT={OTLP_ENDPOINT}" not in production_env:
        errors.append("production environment example must use the Tempo HTTPS endpoint")
    if f"XDR_OTEL_CA_CERT={CERT_DIR}/ca.crt" not in production_env:
        errors.append("production environment example must use the mounted Tempo CA")
    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output")
    args = parser.parse_args()
    try:
        errors = validate_configs(resolved_config(False), resolved_config(True))
    except (OSError, ValueError, RuntimeError, subprocess.TimeoutExpired) as exc:
        errors = [str(exc)]
    report = {"status": "PASS" if not errors else "FAIL", "checks": 47, "errors": errors}
    if args.output:
        output = Path(args.output)
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, indent=2))
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
