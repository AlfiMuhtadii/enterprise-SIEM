#!/usr/bin/env python3
"""Validate native Kafka TLS listener and Go service Compose wiring."""

from __future__ import annotations

import argparse
import json
import os
import subprocess
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
GO_SERVICES = ("ingestion-gateway", "normalizer-worker", "correlation-worker")
TLS_CERT_TARGET = "/etc/xdr/internal-certs"


def resolved_compose(root: Path = ROOT) -> dict[str, Any]:
    env = os.environ.copy()
    env.update({
        "XDR_KAFKA_TRANSPORT": "native",
        "XDR_REDPANDA_KAFKA_BROKERS": "redpanda:9093",
        "XDR_REDPANDA_KAFKA_TLS_ENABLED": "true",
    })
    result = subprocess.run(
        ["docker", "compose", "--profile", "strangler", "config", "--format", "json"],
        cwd=root,
        env=env,
        capture_output=True,
        text=True,
        timeout=30,
        check=False,
    )
    if result.returncode != 0:
        raise RuntimeError(f"docker compose config failed: {result.stderr.strip()}")
    return json.loads(result.stdout)


def validate_config(config: dict[str, Any], redpanda_yaml: str) -> list[str]:
    errors: list[str] = []
    services = config.get("services", {})
    redpanda = services.get("redpanda", {})
    command = redpanda.get("command", [])
    command_text = " ".join(str(item) for item in command)
    for required in (
        "INTERNAL_TLS://0.0.0.0:9093",
        "OUTSIDE_TLS://0.0.0.0:19093",
        "INTERNAL_TLS://redpanda:9093",
        "OUTSIDE_TLS://127.0.0.1:19093",
    ):
        if required not in command_text:
            errors.append(f"redpanda command missing {required}")

    tls_port = next(
        (port for port in redpanda.get("ports", []) if int(port.get("target", 0)) == 19093),
        None,
    )
    if tls_port is None or tls_port.get("host_ip") != "127.0.0.1":
        errors.append("host TLS listener 19093 must bind to 127.0.0.1")

    for service_name in GO_SERVICES:
        service = services.get(service_name, {})
        environment = service.get("environment", {})
        if environment.get("XDR_KAFKA_TRANSPORT") != "native":
            errors.append(f"{service_name}: native transport override not resolved")
        if environment.get("XDR_REDPANDA_KAFKA_BROKERS") != "redpanda:9093":
            errors.append(f"{service_name}: TLS broker not resolved")
        if str(environment.get("XDR_REDPANDA_KAFKA_TLS_ENABLED", "")).lower() != "true":
            errors.append(f"{service_name}: Kafka TLS is not enabled")
        mounts = service.get("volumes", [])
        cert_mount = next((mount for mount in mounts if mount.get("target") == TLS_CERT_TARGET), None)
        if cert_mount is None or cert_mount.get("read_only") is not True:
            errors.append(f"{service_name}: certificate mount must be read-only")

    for listener in ("INTERNAL_TLS", "OUTSIDE_TLS"):
        if f"name: {listener}" not in redpanda_yaml:
            errors.append(f"redpanda.yaml missing TLS policy for {listener}")
    if "require_client_auth: false" not in redpanda_yaml:
        errors.append("redpanda.yaml must state the local server-TLS client-auth policy")
    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", help="optional JSON evidence path")
    args = parser.parse_args()
    try:
        config = resolved_compose()
        redpanda_yaml = (ROOT / "infra" / "redpanda" / "redpanda.yaml").read_text(encoding="utf-8")
        errors = validate_config(config, redpanda_yaml)
    except (OSError, ValueError, RuntimeError, subprocess.TimeoutExpired) as exc:
        errors = [str(exc)]

    report = {
        "status": "PASS" if not errors else "FAIL",
        "checks": 5 + len(GO_SERVICES) * 4 + 3,
        "services": list(GO_SERVICES),
        "errors": errors,
    }
    if args.output:
        output = Path(args.output)
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, indent=2))
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
