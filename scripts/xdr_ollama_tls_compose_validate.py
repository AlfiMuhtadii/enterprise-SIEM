#!/usr/bin/env python3
"""Validate plaintext-local and TLS-only production Ollama wiring."""

from __future__ import annotations

import argparse
import json
import subprocess
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
OLLAMA_ENDPOINT = "https://ollama-tls:11434"
CERT_DIR = "/etc/xdr/internal-mtls"
LARAVEL_SERVICES = ("app", "queue", "scheduler")
PROXY_IMAGE = "nginx:1.28.0-alpine@sha256:30f1c0d78e0ad60901648be663a710bdadf19e4c10ac6782c235200619158284"


def resolved_config(production: bool) -> dict[str, Any]:
    command = ["docker", "compose", "--profile", "app", "--profile", "ai-embeddings"]
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


def _healthcheck(service: dict[str, Any]) -> str:
    return " ".join(str(item) for item in service.get("healthcheck", {}).get("test", []))


def validate_configs(
    base: dict[str, Any],
    production: dict[str, Any],
    *,
    nginx_config: str | None = None,
    cert_generator: str | None = None,
    local_env: str | None = None,
    production_env: str | None = None,
) -> list[str]:
    errors: list[str] = []
    base_services = base.get("services", {})
    prod_services = production.get("services", {})
    local_ollama = base_services.get("ollama", {})
    prod_ollama = prod_services.get("ollama", {})
    proxy = prod_services.get("ollama-tls", {})

    nginx_config = nginx_config if nginx_config is not None else (ROOT / "infra/ai/ollama-tls-nginx.conf").read_text(encoding="utf-8")
    cert_generator = cert_generator if cert_generator is not None else (ROOT / "scripts/xdr_generate_internal_mtls_certs.py").read_text(encoding="utf-8")
    local_env = local_env if local_env is not None else (ROOT / ".env.example").read_text(encoding="utf-8")
    production_env = production_env if production_env is not None else (ROOT / ".env.production.example").read_text(encoding="utf-8")

    if "ai-embeddings" not in local_ollama.get("profiles", []):
        errors.append("local Ollama must remain opt-in through the ai-embeddings profile")
    local_ports = local_ollama.get("ports", [])
    if not any(str(item.get("target")) == "11434" and str(item.get("host_ip")) == "127.0.0.1" for item in local_ports):
        errors.append("local Ollama must retain its loopback-only development port")
    if "ollama-tls" in base_services:
        errors.append("local Compose must not deploy the production Ollama TLS proxy")

    if "ai-embeddings" not in prod_ollama.get("profiles", []):
        errors.append("production Ollama must remain opt-in")
    if prod_ollama.get("ports"):
        errors.append("production Ollama backend must not publish host ports")
    if "ai-embeddings" not in proxy.get("profiles", []):
        errors.append("production Ollama TLS proxy must share the ai-embeddings profile")
    if proxy.get("ports"):
        errors.append("production Ollama TLS proxy must not publish host ports")
    if proxy.get("image") != PROXY_IMAGE:
        errors.append("production Ollama TLS proxy must use the pinned Nginx image")

    dependencies = proxy.get("depends_on", {})
    if dependencies.get("ollama", {}).get("condition") != "service_healthy":
        errors.append("Ollama TLS proxy must wait for a healthy backend")
    if dependencies.get("internal-mtls-certs-init", {}).get("condition") != "service_completed_successfully":
        errors.append("Ollama TLS proxy must wait for successful certificate initialization")
    cert_mount = _mount(proxy, CERT_DIR)
    if cert_mount is None or cert_mount.get("read_only") is not True:
        errors.append("Ollama TLS proxy certificate mount must be read-only")
    config_mount = _mount(proxy, "/etc/nginx/nginx.conf")
    if config_mount is None or config_mount.get("read_only") is not True:
        errors.append("Ollama TLS proxy configuration mount must be read-only")
    if "44444" not in {str(group) for group in proxy.get("group_add", [])}:
        errors.append("Ollama TLS proxy must receive only the shared certificate reader group")

    healthcheck = _healthcheck(proxy)
    for token in (OLLAMA_ENDPOINT + "/api/tags", "--cacert", f"{CERT_DIR}/ca.crt"):
        if token not in healthcheck:
            errors.append(f"Ollama TLS proxy health check missing {token}")
    if "--insecure" in healthcheck or " -k" in healthcheck:
        errors.append("Ollama TLS proxy health check must not disable certificate verification")

    required_nginx_tokens = (
        "listen 11434 ssl;",
        "server_name ollama-tls;",
        f"ssl_certificate {CERT_DIR}/server.crt;",
        f"ssl_certificate_key {CERT_DIR}/server.key;",
        "ssl_protocols TLSv1.2 TLSv1.3;",
        "proxy_pass http://ollama:11434;",
        "proxy_buffering off;",
        "proxy_request_buffering off;",
        "proxy_read_timeout 300s;",
    )
    for token in required_nginx_tokens:
        if token not in nginx_config:
            errors.append(f"Ollama Nginx configuration missing {token}")
    for forbidden in ("ssl_verify_client on", "proxy_ssl_verify off", "listen 11434;"):
        if forbidden in nginx_config:
            errors.append(f"Ollama Nginx configuration contains forbidden {forbidden}")

    for name in LARAVEL_SERVICES:
        service = prod_services.get(name, {})
        environment = service.get("environment", {})
        if environment.get("SOC_OLLAMA_BASE_URL") != OLLAMA_ENDPOINT:
            errors.append(f"{name} must use the production Ollama HTTPS endpoint")
        if str(environment.get("SOC_OLLAMA_VERIFY_TLS", "")).lower() != "true":
            errors.append(f"{name} must enforce Ollama TLS verification")
        if environment.get("SOC_OLLAMA_CA_CERT") != f"{CERT_DIR}/ca.crt":
            errors.append(f"{name} must verify Ollama with the mounted private CA")
        if "SOC_AI_PROVIDER" not in environment or "SOC_OLLAMA_MODEL" not in environment:
            errors.append(f"{name} must receive production Ollama provider and model settings")
        if "SOC_EMBEDDING_MODEL_URL" not in environment or "SOC_EMBEDDING_MODEL_NAME" not in environment:
            errors.append(f"{name} must receive production embedding settings")
        mount = _mount(service, CERT_DIR)
        if mount is None or mount.get("read_only") is not True:
            errors.append(f"{name} must mount Ollama CA material read-only")

    if '"ollama-tls"' not in cert_generator:
        errors.append("internal certificate SAN list must include ollama-tls")
    if "SOC_OLLAMA_BASE_URL=http://127.0.0.1:11434" not in local_env:
        errors.append("local environment example must retain plaintext loopback Ollama")
    if f"SOC_OLLAMA_BASE_URL={OLLAMA_ENDPOINT}" not in production_env:
        errors.append("production environment example must use the Ollama HTTPS endpoint")
    if f"SOC_OLLAMA_CA_CERT={CERT_DIR}/ca.crt" not in production_env:
        errors.append("production environment example must use the mounted Ollama CA")
    if "SOC_OLLAMA_VERIFY_TLS=true" not in production_env:
        errors.append("production environment example must enforce Ollama TLS verification")
    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output")
    args = parser.parse_args()
    try:
        errors = validate_configs(resolved_config(False), resolved_config(True))
    except (OSError, ValueError, RuntimeError, subprocess.TimeoutExpired) as exc:
        errors = [str(exc)]
    report = {"status": "PASS" if not errors else "FAIL", "checks": 55, "errors": errors}
    if args.output:
        output = Path(args.output)
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, indent=2))
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
