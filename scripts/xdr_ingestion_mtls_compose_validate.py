#!/usr/bin/env python3
"""Validate the coordinated production ingestion mTLS cutover."""

from __future__ import annotations

import json
import os
import subprocess
import sys
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parent.parent
CONNECTORS = (
    "log-connector-syslog",
    "log-connector-cloudtrail",
    "log-connector-guardduty",
    "log-connector-gcp-audit",
    "log-connector-o365",
)
APP_SERVICES = ("app", "queue", "scheduler")
CERT_TARGET = "/etc/xdr/internal-mtls"


def render_compose(production: bool) -> dict[str, Any]:
    command = ["docker", "compose", "-f", "docker-compose.yml"]
    if production:
        command.extend(
            [
                "-f",
                "docker-compose.prod.yml",
                "--env-file",
                ".env.production.example",
            ]
        )
    command.extend(
        ["--profile", "strangler", "--profile", "app", "config", "--format", "json"]
    )
    environment = os.environ.copy()
    environment.update(
        {
            "XDR_INGESTION_MTLS_SERVER_ENABLED": "false",
            "XDR_INGESTION_MTLS_CLIENT_ENABLED": "false",
            "XDR_INTERNAL_MTLS_ENABLED": "false",
        }
    )
    result = subprocess.run(
        command,
        cwd=ROOT,
        env=environment,
        capture_output=True,
        text=True,
        timeout=60,
        check=False,
    )
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip() or result.stdout.strip())
    return json.loads(result.stdout)


def enabled(value: Any) -> bool:
    return str(value).lower() in {"1", "true", "yes"}


def has_read_only_cert_mount(service: dict[str, Any]) -> bool:
    return any(
        volume.get("target") == CERT_TARGET and volume.get("read_only") is True
        for volume in service.get("volumes", [])
    )


def dependency_condition(service: dict[str, Any], dependency: str) -> str:
    return str(service.get("depends_on", {}).get(dependency, {}).get("condition", ""))


def port_pairs(service: dict[str, Any]) -> set[tuple[int, str]]:
    return {
        (int(port.get("target", 0)), str(port.get("protocol", "tcp")))
        for port in service.get("ports", [])
    }


def main() -> int:
    try:
        local = render_compose(production=False)
        production = render_compose(production=True)
    except (OSError, RuntimeError, subprocess.TimeoutExpired, json.JSONDecodeError) as exc:
        print(f"FAIL ingestion mTLS compose render: {exc}", file=sys.stderr)
        return 1

    checks = 0
    failures: list[str] = []

    def check(condition: bool, label: str) -> None:
        nonlocal checks
        checks += 1
        if not condition:
            failures.append(label)

    local_services = local["services"]
    prod_services = production["services"]

    local_gateway_env = local_services["ingestion-gateway"].get("environment", {})
    check(
        not enabled(local_gateway_env.get("XDR_INTERNAL_MTLS_ENABLED")),
        "local gateway server mTLS remains disabled",
    )
    check(
        not enabled(local_gateway_env.get("XDR_INTERNAL_MTLS_CLIENT_ENABLED")),
        "local gateway client mTLS remains disabled",
    )

    gateway = prod_services["ingestion-gateway"]
    gateway_env = gateway.get("environment", {})
    check(enabled(gateway_env.get("XDR_INTERNAL_MTLS_ENABLED")), "gateway server mTLS enabled")
    check(enabled(gateway_env.get("XDR_INTERNAL_MTLS_CLIENT_ENABLED")), "gateway client mTLS enabled")
    check(has_read_only_cert_mount(gateway), "gateway has read-only certificate mount")
    check("44444" in gateway.get("group_add", []), "gateway can read private-key group")
    check(
        dependency_condition(gateway, "internal-mtls-certs-init")
        == "service_completed_successfully",
        "gateway waits for certificate initialization",
    )
    health_command = " ".join(str(part) for part in gateway.get("healthcheck", {}).get("test", []))
    for required in (
        "curl",
        "--cacert",
        "--cert",
        "--key",
        "https://localhost:8091/health",
    ):
        check(required in health_command, f"gateway healthcheck contains {required}")

    for name in APP_SERVICES:
        environment = prod_services[name].get("environment", {})
        check(
            environment.get("XDR_INGESTION_GATEWAY_URL")
            == "https://ingestion-gateway:8091",
            f"{name} service-health gateway URL uses HTTPS",
        )
        check(
            environment.get("SCENARIO_INGESTION_GATEWAY_URL")
            == "https://ingestion-gateway:8091",
            f"{name} scenario gateway URL uses HTTPS",
        )

    expected_paths = {
        "XDR_INTERNAL_MTLS_CA": f"{CERT_TARGET}/ca.crt",
        "XDR_INTERNAL_MTLS_CLIENT_CERT": f"{CERT_TARGET}/client.crt",
        "XDR_INTERNAL_MTLS_CLIENT_KEY": f"{CERT_TARGET}/client.key",
    }
    for name in CONNECTORS:
        service = prod_services[name]
        environment = service.get("environment", {})
        check(
            environment.get("XDR_INGEST_URL")
            == "https://ingestion-gateway:8091/v1/ingest",
            f"{name} ingestion URL uses HTTPS",
        )
        check(
            not enabled(environment.get("XDR_INTERNAL_MTLS_ENABLED")),
            f"{name} metrics server remains independently disabled",
        )
        check(
            enabled(environment.get("XDR_INTERNAL_MTLS_CLIENT_ENABLED")),
            f"{name} ingestion client mTLS enabled",
        )
        for variable, expected in expected_paths.items():
            check(environment.get(variable) == expected, f"{name} has {variable}")
        check(has_read_only_cert_mount(service), f"{name} has read-only certificate mount")
        check("44444" in service.get("group_add", []), f"{name} can read private-key group")
        check(
            dependency_condition(service, "ingestion-gateway") == "service_healthy",
            f"{name} waits for healthy gateway",
        )
        check(
            dependency_condition(service, "internal-mtls-certs-init")
            == "service_completed_successfully",
            f"{name} waits for certificate initialization",
        )

    check(
        port_pairs(prod_services["log-connector-syslog"]) == {(5140, "tcp"), (5140, "udp")},
        "production syslog exposes only TCP/UDP ingestion ports",
    )
    for name in CONNECTORS[1:]:
        check(not prod_services[name].get("ports"), f"{name} metrics port is not host-exposed")

    dockerfile = (ROOT / "services" / "ingestion-gateway" / "Dockerfile").read_text(
        encoding="utf-8"
    )
    check("apk add --no-cache curl" in dockerfile, "gateway image contains curl healthcheck client")

    for name in CONNECTORS:
        source = (ROOT / "services" / name / "main.go").read_text(encoding="utf-8")
        check(
            'envBool("XDR_INTERNAL_MTLS_CLIENT_ENABLED", serverEnabled)' in source,
            f"{name} client flag inherits server mode",
        )

    if failures:
        for failure in failures:
            print(f"FAIL {failure}", file=sys.stderr)
        print(f"FAIL ingestion mTLS compose checks={checks} failures={len(failures)}", file=sys.stderr)
        return 1

    print(f"PASS ingestion mTLS compose checks={checks}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
