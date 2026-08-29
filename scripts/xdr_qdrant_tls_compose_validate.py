#!/usr/bin/env python3
"""Validate optional local and fail-closed production Qdrant HTTPS wiring."""

from __future__ import annotations

import argparse
import json
import os
import subprocess
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
CLIENT_CERT_DIR = "/etc/xdr/qdrant-certs"
TLS_CLIENTS = ("app", "queue", "scheduler")


def resolved_config(production: bool) -> dict[str, Any]:
    command = ["docker", "compose", "--profile", "app"]
    environment = os.environ.copy()
    if production:
        command += ["--env-file", ".env.production.example", "-f", "docker-compose.yml", "-f", "docker-compose.prod.yml"]
    else:
        environment["QDRANT_TLS_ENABLED"] = "false"
        environment["QDRANT_TLS_REQUIRED"] = "false"
    command += ["config", "--format", "json"]
    result = subprocess.run(command, cwd=ROOT, env=environment, capture_output=True, text=True, timeout=30, check=False)
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip())
    return json.loads(result.stdout)


def _mount(service: dict[str, Any], target: str) -> dict[str, Any] | None:
    return next((item for item in service.get("volumes", []) if item.get("target") == target), None)


def validate_configs(base: dict[str, Any], production: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    base_services = base.get("services", {})
    prod_services = production.get("services", {})
    init = base_services.get("qdrant-tls-init", {})
    prod_init = prod_services.get("qdrant-tls-init", {})
    qdrant = base_services.get("qdrant", {})
    prod_qdrant = prod_services.get("qdrant", {})

    if init.get("environment", {}).get("QDRANT_TLS_ENABLED") not in {"false", False}:
        errors.append("local Qdrant TLS must remain disabled by default")
    if init.get("environment", {}).get("QDRANT_TLS_REQUIRED") not in {"false", False}:
        errors.append("local Qdrant TLS initialization must remain optional")
    if prod_init.get("environment", {}).get("QDRANT_TLS_ENABLED") not in {"true", True}:
        errors.append("production Qdrant TLS must be enabled")
    if prod_init.get("environment", {}).get("QDRANT_TLS_REQUIRED") not in {"true", True}:
        errors.append("production Qdrant TLS initialization must fail closed")
    if qdrant.get("depends_on", {}).get("qdrant-tls-init", {}).get("condition") != "service_completed_successfully":
        errors.append("Qdrant must wait for successful TLS initialization")
    if prod_qdrant.get("environment", {}).get("QDRANT__SERVICE__ENABLE_TLS") not in {"true", True}:
        errors.append("production Qdrant server must enable TLS")
    expected_paths = {
        "QDRANT__TLS__CERT": "/qdrant/tls/server.crt",
        "QDRANT__TLS__KEY": "/qdrant/tls/server.key",
        "QDRANT__TLS__CA_CERT": "/qdrant/tls/ca.crt",
    }
    for name, expected in expected_paths.items():
        if qdrant.get("environment", {}).get(name) != expected:
            errors.append(f"Qdrant server has an unexpected {name} path")

    tls_mount = _mount(qdrant, "/qdrant/tls")
    if tls_mount is None or tls_mount.get("read_only") is not True:
        errors.append("Qdrant certificate mount must be read-only")
    init_command = " ".join(str(item) for item in init.get("entrypoint", []))
    for required in ("server.crt", "server.key", "ca.crt", "chmod 600 /tls/server.key"):
        if required not in init_command:
            errors.append(f"Qdrant TLS initializer missing {required}")

    healthcheck = " ".join(str(item) for item in qdrant.get("healthcheck", {}).get("test", []))
    for required in ("openssl s_client", "-CAfile /qdrant/tls/ca.crt", "-verify_return_error", "-verify_hostname qdrant", "/dev/tcp/127.0.0.1/6333"):
        if required not in healthcheck:
            errors.append(f"Qdrant healthcheck missing {required}")
    if "curl" in healthcheck:
        errors.append("Qdrant healthcheck cannot depend on curl, which is absent from the image")
    if prod_qdrant.get("ports"):
        errors.append("production Qdrant must not publish host ports")

    for name in TLS_CLIENTS:
        service = prod_services.get(name, {})
        environment = service.get("environment", {})
        if environment.get("SOC_QDRANT_BASE_URL") != "https://qdrant:6333":
            errors.append(f"{name} SOC knowledge client must use Qdrant HTTPS")
        if environment.get("XDR_QDRANT_URL") != "https://qdrant:6333":
            errors.append(f"{name} infrastructure client must use Qdrant HTTPS")
        if environment.get("XDR_QDRANT_VERIFY_TLS") not in {"true", True}:
            errors.append(f"{name} must verify Qdrant TLS")
        if environment.get("XDR_QDRANT_CA_CERT") != f"{CLIENT_CERT_DIR}/ca.crt":
            errors.append(f"{name} must use the mounted Qdrant CA")
        mount = _mount(service, CLIENT_CERT_DIR)
        if mount is None or mount.get("read_only") is not True:
            errors.append(f"{name} must mount Qdrant certificates read-only")

    cert_generator = (ROOT / "scripts" / "xdr_generate_internal_mtls_certs.py").read_text(encoding="utf-8")
    if '"qdrant"' not in cert_generator:
        errors.append("internal certificate SAN list must include qdrant")
    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output")
    args = parser.parse_args()
    try:
        errors = validate_configs(resolved_config(False), resolved_config(True))
    except (OSError, ValueError, RuntimeError, subprocess.TimeoutExpired) as exc:
        errors = [str(exc)]
    report = {"status": "PASS" if not errors else "FAIL", "checks": 37, "errors": errors}
    if args.output:
        output = Path(args.output)
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, indent=2))
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
