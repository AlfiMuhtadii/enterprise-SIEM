#!/usr/bin/env python3
"""Validate optional local and fail-closed production ClickHouse HTTPS wiring."""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import xml.etree.ElementTree as ET
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
CLIENT_CERT_DIR = "/etc/xdr/clickhouse-certs"
TLS_CLIENTS = ("app", "queue", "scheduler", "telemetry-worker")


def resolved_config(production: bool) -> dict[str, Any]:
    command = ["docker", "compose", "--profile", "app"]
    environment = os.environ.copy()
    if production:
        command += ["--env-file", ".env.production.example", "-f", "docker-compose.yml", "-f", "docker-compose.prod.yml"]
    else:
        environment["CLICKHOUSE_TLS_ENABLED"] = "false"
        environment["CLICKHOUSE_TLS_REQUIRED"] = "false"
    command += ["config", "--format", "json"]
    result = subprocess.run(command, cwd=ROOT, env=environment, capture_output=True, text=True, timeout=30, check=False)
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip())
    return json.loads(result.stdout)


def validate_configs(base: dict[str, Any], production: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    base_services = base.get("services", {})
    prod_services = production.get("services", {})
    init = base_services.get("clickhouse-tls-init", {})
    prod_init = prod_services.get("clickhouse-tls-init", {})
    clickhouse = base_services.get("clickhouse", {})

    if init.get("environment", {}).get("CLICKHOUSE_TLS_ENABLED") not in {"false", False}:
        errors.append("local ClickHouse TLS must remain disabled by default")
    if init.get("environment", {}).get("CLICKHOUSE_TLS_REQUIRED") not in {"false", False}:
        errors.append("local ClickHouse TLS initialization must remain optional")
    if prod_init.get("environment", {}).get("CLICKHOUSE_TLS_ENABLED") not in {"true", True}:
        errors.append("production ClickHouse TLS must be enabled")
    if prod_init.get("environment", {}).get("CLICKHOUSE_TLS_REQUIRED") not in {"true", True}:
        errors.append("production ClickHouse TLS initialization must fail closed")
    if clickhouse.get("depends_on", {}).get("clickhouse-tls-init", {}).get("condition") != "service_completed_successfully":
        errors.append("ClickHouse must wait for successful TLS initialization")

    for target in ("/etc/clickhouse-server/tls", "/etc/xdr/clickhouse-config/https.xml"):
        mount = next((item for item in clickhouse.get("volumes", []) if item.get("target") == target), None)
        if mount is None or mount.get("read_only") is not True:
            errors.append(f"ClickHouse mount {target} must be read-only")

    entrypoint = " ".join(str(item) for item in clickhouse.get("entrypoint", []))
    command = " ".join(str(item) for item in clickhouse.get("command", []))
    if "/bin/bash" not in entrypoint or "exec /entrypoint.sh" not in command:
        errors.append("ClickHouse must preserve the official image entrypoint after TLS config selection")
    if "cp /etc/xdr/clickhouse-config/https.xml /etc/clickhouse-server/config.d/https.xml" not in command:
        errors.append("ClickHouse entrypoint must install only the TLS config fragment")

    healthcheck = " ".join(str(item) for item in clickhouse.get("healthcheck", {}).get("test", []))
    for required in ("https://127.0.0.1:8443/ping", "--ca-certificate=", "http://127.0.0.1:8123/ping"):
        if required not in healthcheck:
            errors.append(f"ClickHouse healthcheck missing {required}")
    if prod_services.get("clickhouse", {}).get("ports"):
        errors.append("production ClickHouse must not publish host ports")

    for name in TLS_CLIENTS:
        service = prod_services.get(name, {})
        environment = service.get("environment", {})
        if environment.get("XDR_CLICKHOUSE_HTTP_URL") != "https://clickhouse:8443":
            errors.append(f"{name} must use ClickHouse HTTPS")
        if environment.get("XDR_CLICKHOUSE_VERIFY_TLS") not in {"true", True}:
            errors.append(f"{name} must verify ClickHouse TLS")
        if environment.get("XDR_CLICKHOUSE_CA_CERT") != f"{CLIENT_CERT_DIR}/ca.crt":
            errors.append(f"{name} must use the mounted ClickHouse CA")
        mount = next((item for item in service.get("volumes", []) if item.get("target") == CLIENT_CERT_DIR), None)
        if mount is None or mount.get("read_only") is not True:
            errors.append(f"{name} must mount ClickHouse certificates read-only")

    xml_path = ROOT / "infra" / "clickhouse" / "https.xml"
    root = ET.parse(xml_path).getroot()
    http_port = root.find("http_port")
    if http_port is None or http_port.attrib.get("remove") != "1":
        errors.append("TLS config must remove the plaintext ClickHouse HTTP listener")
    if root.findtext("https_port") != "8443":
        errors.append("TLS config must expose ClickHouse HTTPS on 8443")
    if root.findtext("openSSL/server/certificateFile") != "/etc/clickhouse-server/tls/server.crt":
        errors.append("TLS config has an unexpected server certificate path")
    if root.findtext("openSSL/server/privateKeyFile") != "/etc/clickhouse-server/tls/server.key":
        errors.append("TLS config has an unexpected server key path")
    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output")
    args = parser.parse_args()
    try:
        errors = validate_configs(resolved_config(False), resolved_config(True))
    except (OSError, ValueError, RuntimeError, ET.ParseError, subprocess.TimeoutExpired) as exc:
        errors = [str(exc)]
    report = {"status": "PASS" if not errors else "FAIL", "checks": 28, "errors": errors}
    if args.output:
        output = Path(args.output)
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, indent=2))
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
