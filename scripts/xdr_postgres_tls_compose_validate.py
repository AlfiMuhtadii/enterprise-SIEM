#!/usr/bin/env python3
"""Validate additive local and fail-closed production PostgreSQL TLS wiring."""

from __future__ import annotations

import argparse
import json
import subprocess
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
CLIENT_CERT_DIR = "/etc/xdr/postgres-certs"
DISCRETE_TLS_CLIENTS = (
    "app",
    "queue",
    "scheduler",
    "alert-writer-service",
    "incident-builder-service",
)
DSN_TLS_CLIENTS = ("telemetry-worker", "ml-shadow-detector")


def resolved_config(production: bool) -> dict[str, Any]:
    command = [
        "docker", "compose",
        "--profile", "app",
        "--profile", "strangler",
        "--profile", "ml-shadow",
    ]
    if production:
        command += ["--env-file", ".env.production.example", "-f", "docker-compose.yml", "-f", "docker-compose.prod.yml"]
    command += ["config", "--format", "json"]
    result = subprocess.run(command, cwd=ROOT, capture_output=True, text=True, timeout=30, check=False)
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip())
    return json.loads(result.stdout)


def validate_configs(base: dict[str, Any], production: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    base_services = base.get("services", {})
    prod_services = production.get("services", {})
    init = base_services.get("postgres-tls-init", {})
    prod_init = prod_services.get("postgres-tls-init", {})
    postgres = base_services.get("postgres", {})

    if init.get("environment", {}).get("POSTGRES_TLS_ENABLED") not in {"false", False}:
        errors.append("local postgres TLS must remain explicitly disabled")
    if init.get("environment", {}).get("POSTGRES_TLS_REQUIRED") not in {"false", False}:
        errors.append("local postgres TLS init must remain optional")
    if prod_init.get("environment", {}).get("POSTGRES_TLS_ENABLED") not in {"true", True}:
        errors.append("production postgres TLS must be enabled")
    if prod_init.get("environment", {}).get("POSTGRES_TLS_REQUIRED") not in {"true", True}:
        errors.append("production postgres TLS init must fail closed")
    if postgres.get("depends_on", {}).get("postgres-tls-init", {}).get("condition") != "service_completed_successfully":
        errors.append("postgres must wait for successful TLS initialization")

    mounts = postgres.get("volumes", [])
    mount = next((item for item in mounts if item.get("target") == "/etc/postgresql/tls"), None)
    if mount is None or mount.get("read_only") is not True:
        errors.append("postgres certificate volume must be read-only")

    command_text = " ".join(str(item) for item in postgres.get("command", []))
    for required in ("docker-entrypoint.sh postgres", "ssl=on", "ssl_cert_file=", "ssl_key_file=", "ssl_ca_file=", "hba_file="):
        if required not in command_text:
            errors.append(f"postgres TLS command missing {required}")

    if prod_services.get("postgres", {}).get("ports"):
        errors.append("production postgres must not publish host ports")

    init_text = " ".join(str(item) for item in init.get("entrypoint", []))
    for required in ("clientcert=verify-ca", "chown 0:44444 /tls/client.key", "chmod 640 /tls/client.key"):
        if required not in init_text:
            errors.append(f"postgres client certificate initialization missing {required}")

    expected_paths = {
        "DB_SSLMODE": "verify-full",
        "DB_SSLROOTCERT": f"{CLIENT_CERT_DIR}/ca.crt",
        "DB_SSLCERT": f"{CLIENT_CERT_DIR}/client.crt",
        "DB_SSLKEY": f"{CLIENT_CERT_DIR}/client.key",
    }
    for name in DISCRETE_TLS_CLIENTS + DSN_TLS_CLIENTS:
        service = prod_services.get(name, {})
        mounts = service.get("volumes", [])
        cert_mount = next((item for item in mounts if item.get("target") == CLIENT_CERT_DIR), None)
        if cert_mount is None or cert_mount.get("read_only") is not True:
            errors.append(f"{name} must mount PostgreSQL client certificates read-only")
        if "44444" not in {str(group) for group in service.get("group_add", [])}:
            errors.append(f"{name} must receive the PostgreSQL client-key group")

    for name in DISCRETE_TLS_CLIENTS:
        environment = prod_services.get(name, {}).get("environment", {})
        for variable, expected in expected_paths.items():
            if environment.get(variable) != expected:
                errors.append(f"{name} must set {variable}={expected}")

    for name in DSN_TLS_CLIENTS:
        dsn = str(prod_services.get(name, {}).get("environment", {}).get("SECURITY_INGEST_DSN", ""))
        for required in (
            "sslmode=verify-full",
            f"sslrootcert={CLIENT_CERT_DIR}/ca.crt",
            f"sslcert={CLIENT_CERT_DIR}/client.crt",
            f"sslkey={CLIENT_CERT_DIR}/client.key",
        ):
            if required not in dsn:
                errors.append(f"{name} production DSN missing {required}")
    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output")
    args = parser.parse_args()
    try:
        errors = validate_configs(resolved_config(False), resolved_config(True))
    except (OSError, ValueError, RuntimeError, subprocess.TimeoutExpired) as exc:
        errors = [str(exc)]
    report = {"status": "PASS" if not errors else "FAIL", "checks": 54, "errors": errors}
    if args.output:
        output = Path(args.output)
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, indent=2))
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
