#!/usr/bin/env python3
"""Validate local compatibility and fail-closed production MinIO TLS."""

from __future__ import annotations

import argparse
import json
import os
import subprocess
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
CERT_DIR = "/etc/xdr/internal-mtls"
CLIENTS = ("app", "queue", "scheduler")


def resolved_config(production: bool) -> dict[str, Any]:
    command = ["docker", "compose", "--profile", "data-tiering", "--profile", "app"]
    environment = os.environ.copy()
    if production:
        command += [
            "--env-file", ".env.production.example",
            "-f", "docker-compose.yml",
            "-f", "docker-compose.prod.yml",
        ]
    command += ["config", "--format", "json"]
    result = subprocess.run(
        command,
        cwd=ROOT,
        env=environment,
        capture_output=True,
        text=True,
        timeout=30,
        check=False,
    )
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip())
    return json.loads(result.stdout)


def _mount(service: dict[str, Any], target: str) -> dict[str, Any] | None:
    return next(
        (item for item in service.get("volumes", []) if item.get("target") == target),
        None,
    )


def validate_configs(base: dict[str, Any], production: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    base_services = base.get("services", {})
    prod_services = production.get("services", {})
    local = base_services.get("minio", {})
    minio = prod_services.get("minio", {})
    initializer = prod_services.get("internal-mtls-certs-init", {})

    local_command = " ".join(str(item) for item in local.get("command", []))
    if "--certs-dir" in local_command:
        errors.append("local MinIO must remain plaintext-compatible by default")

    command = " ".join(str(item) for item in minio.get("command", []))
    if f"--certs-dir {CERT_DIR}/minio" not in command:
        errors.append("production MinIO must use the initialized TLS directory")
    if minio.get("ports"):
        errors.append("production MinIO must not publish API or console ports")
    mount = _mount(minio, CERT_DIR)
    if mount is None or mount.get("read_only") is not True:
        errors.append("production MinIO certificate mount must be read-only")
    if "44444" not in {str(group) for group in minio.get("group_add", [])}:
        errors.append("production MinIO must receive private-key group access")
    if minio.get("depends_on", {}).get("internal-mtls-certs-init", {}).get("condition") != "service_completed_successfully":
        errors.append("production MinIO must wait for certificate initialization")

    health = " ".join(str(item) for item in minio.get("healthcheck", {}).get("test", []))
    for required in (
        "mc alias set health https://localhost:9000",
        f"SSL_CERT_FILE={CERT_DIR}/ca.crt",
        "mc ready health --quiet",
    ):
        if required not in health:
            errors.append(f"production MinIO healthcheck missing {required}")
    if "--insecure" in health:
        errors.append("production MinIO healthcheck must not bypass TLS verification")

    init_command = " ".join(str(item) for item in initializer.get("entrypoint", []))
    for required in (
        "/tls/minio/public.crt",
        "/tls/minio/private.key",
        "/tls/minio/CAs/internal-ca.crt",
        "chmod 640 /tls/minio/private.key",
    ):
        if required not in init_command:
            errors.append(f"certificate initializer missing MinIO material: {required}")

    for name in CLIENTS:
        service = prod_services.get(name, {})
        environment = service.get("environment", {})
        if environment.get("AWS_ENDPOINT") != "https://minio:9000":
            errors.append(f"{name} cold-tier client must use MinIO HTTPS")
        if environment.get("AWS_CA_BUNDLE") != f"{CERT_DIR}/ca.crt":
            errors.append(f"{name} cold-tier client must verify the internal CA")
        client_mount = _mount(service, CERT_DIR)
        if client_mount is None or client_mount.get("read_only") is not True:
            errors.append(f"{name} must mount the MinIO CA read-only")

    generator = (ROOT / "scripts" / "xdr_generate_internal_mtls_certs.py").read_text(encoding="utf-8")
    if '"minio"' not in generator:
        errors.append("internal certificate SAN list must include minio")
    filesystem = (ROOT / "config" / "filesystems.php").read_text(encoding="utf-8")
    if "'verify' => env('AWS_CA_BUNDLE') ?: true" not in filesystem:
        errors.append("Laravel S3 client must verify TLS with AWS_CA_BUNDLE")
    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output")
    args = parser.parse_args()
    try:
        errors = validate_configs(resolved_config(False), resolved_config(True))
    except (OSError, ValueError, RuntimeError, subprocess.TimeoutExpired) as exc:
        errors = [str(exc)]
    report = {"status": "PASS" if not errors else "FAIL", "checks": 32, "errors": errors}
    if args.output:
        output = Path(args.output)
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, indent=2))
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
