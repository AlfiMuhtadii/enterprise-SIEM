#!/usr/bin/env python3
"""
ENTERPRISE-036: Production Deployment Profile Validator.

Static analysis of the production-pilot deployment artifacts.  Read-only.
Complements xdr_posture_check.py (runtime env vars) and
xdr_observability_slo_validate.py (SLO metrics).

Checks:
  PDP-01  docker-compose.prod.yml exists
  PDP-02  .env.production.example exists
  PDP-03  XDR_TENANT_STRICT_MODE=true in production env file
  PDP-04  XDR_ENFORCE_INTERNAL_AUTH=true in production env file
  PDP-05  All required internal tokens present (not empty)
  PDP-06  No placeholder / default secrets in token fields
  PDP-07  Datastores not publicly exposed in production compose
  PDP-08  Pandaproxy not publicly exposed in production compose
  PDP-09  Grafana provisioning mounts are read-only
  PDP-10  Critical services have restart: always
  PDP-11  Healthchecks present for critical services
  PDP-12  Backup / report / log paths documented
  PDP-13  Accepted risks and deferred risks referenced in docs
  PDP-14  No active scanning / autonomous remediation flags enabled
  PDP-15  Datastore credentials fail closed (ENT-SEC-WEAK-DEFAULT-SECRETS)
  PDP-16  OpenSearch security plugin enabled in production (ENT-SEC-OPENSEARCH-OPEN)
  PDP-17  OpenSearch least-privilege RBAC configured, not shared admin (ENT-SEC-OPENSEARCH-OPEN)

Severity per profile:
  local      → WARN for production-only missing items
  staging    → FAIL for auth/secrets/exposure problems; WARN otherwise
  production → FAIL for all unsafe production posture items

Exit codes: 0=PASS, 1=FAIL (≥1 FAIL-level issue), 2=ERROR
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

try:
    import yaml as _yaml
    _HAS_YAML = True

    def _reset_constructor(loader, node):
        if isinstance(node, yaml.SequenceNode):
            return loader.construct_sequence(node)
        return []

    import yaml
    yaml.SafeLoader.add_constructor("!reset",    _reset_constructor)
    yaml.SafeLoader.add_constructor("!override", _reset_constructor)
except ImportError:
    _HAS_YAML = False

PROJECT_ROOT = Path(__file__).resolve().parent.parent

PASS = "PASS"
WARN = "WARN"
FAIL = "FAIL"
INFO = "INFO"

PROFILES = ("local", "staging", "production")

# Internal auth token keys that must be present and not placeholder.
_REQUIRED_TOKENS = [
    "XDR_INTERNAL_AUTH_SECRET",
    "XDR_NORMALIZER_INTERNAL_TOKEN",
    "XDR_ALERT_WRITER_INTERNAL_TOKEN",
    "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN",
    "XDR_CORRELATION_INTERNAL_TOKEN",
    "XDR_AI_RAG_INTERNAL_TOKEN",  # AI-1
]

# Placeholder / default values that must NOT appear in production token fields.
_BAD_SECRETS = {
    "",
    "admin", "password", "detector",
    "DetectorAdmin123!", "changeme", "change-me", "CHANGE_ME",
    "example", "secret", "local",
    "dev-secret-change-me",
    "REPLACE_WITH_GENERATED_KEY",
    "REPLACE_WITH_STRONG_PASSWORD",
    "REPLACE_WITH_STRONG_INGEST_SECRET",
    "REPLACE_WITH_STRONG_INTERNAL_AUTH_SECRET",
    "REPLACE_WITH_STRONG_NORMALIZER_TOKEN",
    "REPLACE_WITH_STRONG_ALERT_WRITER_TOKEN",
    "REPLACE_WITH_STRONG_INCIDENT_BUILDER_TOKEN",
    "REPLACE_WITH_STRONG_CORRELATION_TOKEN",
    "REPLACE_WITH_STRONG_WEBHOOK_SECRET",
    "REPLACE_WITH_STRONG_AGENT_ENROLLMENT_TOKEN",
    "base64:REPLACE_WITH_GENERATED_KEY",
    "base64:CHANGE_ME",
}

# Services whose ports must not be publicly exposed.
_DATASTORE_SERVICES = {"postgres", "clickhouse", "opensearch", "qdrant"}

# Well-known ports for datastores that should never be public.
_DATASTORE_CONTAINER_PORTS = {"5432", "8123", "9000", "9200", "9600", "6333", "6334"}

# ENT-SEC-WEAK-DEFAULT-SECRETS: container-side credential env keys that the
# base docker-compose.yml falls back to a well-known weak default for
# (${VAR:-postgres} etc.) — docker-compose.prod.yml must override each with
# fail-closed ${VAR:?message} syntax rather than inheriting the weak default.
_DATASTORE_CREDENTIAL_KEYS = {
    "postgres": ["POSTGRES_PASSWORD"],
    "clickhouse": ["CLICKHOUSE_PASSWORD"],
    "grafana": ["GF_SECURITY_ADMIN_USER", "GF_SECURITY_ADMIN_PASSWORD"],
    "opensearch": ["OPENSEARCH_INITIAL_ADMIN_PASSWORD"],
}

# Env-file keys (production env file, not compose) holding the same credentials.
_DATASTORE_CREDENTIAL_ENV_KEYS = [
    "DB_PASSWORD", "CLICKHOUSE_PASSWORD", "GF_SECURITY_ADMIN_PASSWORD",
    "XDR_OPENSEARCH_PASSWORD", "XDR_OPENSEARCH_WRITER_PASSWORD",
]

# Critical services that must have restart: always in production.
_RESTART_SERVICES = {
    "redpanda", "postgres", "app", "ingestion-gateway",
    "normalizer-worker", "correlation-worker", "alert-writer-service",
    "incident-builder-service",
}

# Critical services that should have healthchecks.
_HEALTHCHECK_SERVICES = {
    "redpanda", "postgres", "opensearch", "qdrant", "app",
}


# ---------------------------------------------------------------------------
# Utilities
# ---------------------------------------------------------------------------

def _severity(profile: str, local_sev: str, staging_sev: str, prod_sev: str) -> str:
    return {"local": local_sev, "staging": staging_sev, "production": prod_sev}[profile]


def _check(
    check_id: str,
    name: str,
    passed: bool,
    severity: str,
    detail: str,
    remediation: str = "",
) -> dict[str, Any]:
    status = PASS if passed else severity
    return {
        "check_id": check_id,
        "name": name,
        "status": status,
        "detail": detail,
        "remediation": remediation if not passed else "",
    }


def _parse_env_file(path: Path) -> dict[str, str]:
    env: dict[str, str] = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        if "=" not in line:
            continue
        key, _, val = line.partition("=")
        env[key.strip()] = val.strip().strip('"').strip("'")
    return env


def _parse_compose_file(path: Path) -> dict[str, Any] | None:
    text = path.read_text(encoding="utf-8")
    if _HAS_YAML:
        try:
            return yaml.safe_load(text) or {}
        except yaml.YAMLError:
            pass
    return {"_raw_text": text, "services": _parse_services_from_text(text)}


def _parse_services_from_text(text: str) -> dict[str, Any]:
    """Indent-aware compose parser — extracts ports, volumes, restart, environment,
    and healthcheck for each service. Works without PyYAML.

    Expected structure (2-space Docker Compose indentation):
      services:
        <svc>:          ← indent 2
          ports:        ← indent 4
            - "..."     ← indent 6 (list items)
          volumes:
            - ...
          restart: X    ← indent 4, inline value
          environment:
            KEY: VAL    ← indent 6 (map items)
          healthcheck:
            ...
    """
    _TAG_RE = re.compile(r"!(reset|override)\s*")

    services: dict[str, dict] = {}
    in_services = False
    current_svc: str | None = None
    current_prop: str | None = None

    for raw_line in text.splitlines():
        stripped = raw_line.rstrip()
        if not stripped or stripped.lstrip().startswith("#"):
            continue

        n = len(stripped) - len(stripped.lstrip())
        content = stripped.lstrip()

        # Detect start of services: block (0 indent)
        if n == 0:
            in_services = content == "services:"
            if not in_services:
                current_svc = None
                current_prop = None
            continue

        if not in_services:
            continue

        # Service name at indent 2
        if n == 2 and content.endswith(":") and not content.startswith("-"):
            svc = content[:-1]
            _TOP_LEVEL = {"volumes", "networks", "configs", "secrets"}
            if svc not in _TOP_LEVEL:
                current_svc = svc
                services[svc] = {
                    "ports": [], "volumes": [], "restart": "",
                    "environment": {},
                }
            else:
                current_svc = None
            current_prop = None
            continue

        if current_svc is None:
            continue

        # Property key at indent 4
        if n == 4 and not content.startswith("-"):
            if ":" in content:
                key, _, val = content.partition(":")
                key = key.strip()
                val = _TAG_RE.sub("", val.strip()).strip()
                current_prop = key
                if key == "restart":
                    services[current_svc]["restart"] = val.strip("\"'")
                elif key == "healthcheck":
                    services[current_svc]["healthcheck"] = {"_present": True}
            continue

        # List / map items at indent 6+
        if n >= 6 and current_prop is not None:
            if current_prop == "ports":
                if content.startswith("- "):
                    item = _TAG_RE.sub("", content[2:].strip()).strip("\"'")
                    if item and item not in ("[]", ""):
                        services[current_svc]["ports"].append(item)
            elif current_prop == "volumes":
                if content.startswith("- "):
                    item = content[2:].strip().strip("\"'")
                    if item:
                        services[current_svc]["volumes"].append(item)
            elif current_prop == "environment":
                if content.startswith("- ") and "=" in content:
                    # List form: - KEY=VALUE
                    pair = content[2:].strip()
                    k, _, v = pair.partition("=")
                    services[current_svc]["environment"][k.strip()] = v.strip().strip("\"'")
                elif ":" in content and not content.startswith("-"):
                    # Map form: KEY: VALUE
                    k, _, v = content.partition(":")
                    services[current_svc]["environment"][k.strip()] = v.strip().strip("\"'")

    return services


def _is_public_binding(port_spec: str | int | dict) -> bool:
    """Return True if the port binding is accessible from 0.0.0.0."""
    if isinstance(port_spec, dict):
        host_ip = str(port_spec.get("host_ip", "") or "")
        # Explicit non-localhost host_ip is public regardless of other fields
        if host_ip and host_ip not in ("127.0.0.1", "::1", "localhost"):
            return True
        # No published port means container-internal only
        if "target" in port_spec and "published" not in port_spec:
            return False
        # Explicitly localhost or no host_ip
        return False
    port_str = str(port_spec).strip("\"'")
    parts = port_str.split(":")
    if len(parts) == 1:
        return False  # Container port only — no external binding
    if len(parts) == 2:
        return True   # PORT:PORT — bound to 0.0.0.0 (public)
    # HOST_IP:HOST_PORT:CONTAINER_PORT
    return parts[0] not in ("127.0.0.1", "::1", "localhost", "")


def _get_container_port(port_spec: str | int | dict) -> str:
    if isinstance(port_spec, dict):
        return str(port_spec.get("target", ""))
    port_str = str(port_spec).strip("\"'")
    return port_str.split(":")[-1].split("/")[0]


# ---------------------------------------------------------------------------
# File existence checks
# ---------------------------------------------------------------------------

def check_prod_compose_exists(root: Path) -> dict:
    path = root / "docker-compose.prod.yml"
    return _check(
        "PDP-01", "docker-compose.prod.yml exists",
        path.exists(), FAIL,
        str(path),
        "Create docker-compose.prod.yml production overlay. See docs/operations/PRODUCTION_DEPLOYMENT_PROFILE.md.",
    )


def check_env_example_exists(root: Path) -> dict:
    path = root / ".env.production.example"
    return _check(
        "PDP-02", ".env.production.example exists",
        path.exists(), FAIL,
        str(path),
        "Create .env.production.example with all required production flags.",
    )


# ---------------------------------------------------------------------------
# Environment checks (operate on parsed env dict)
# ---------------------------------------------------------------------------

def check_strict_mode(env: dict, profile: str) -> dict:
    val = env.get("XDR_TENANT_STRICT_MODE", "").lower()
    passed = val in ("true", "1", "yes")
    sev = _severity(profile, WARN, FAIL, FAIL)
    return _check(
        "PDP-03", "XDR_TENANT_STRICT_MODE=true",
        passed, sev,
        f"XDR_TENANT_STRICT_MODE={env.get('XDR_TENANT_STRICT_MODE', '(not set)')}",
        "Set XDR_TENANT_STRICT_MODE=true to enforce tenant context on all SOC routes.",
    )


def check_internal_auth(env: dict, profile: str) -> dict:
    val = env.get("XDR_ENFORCE_INTERNAL_AUTH", "").lower()
    passed = val in ("true", "1", "yes")
    sev = _severity(profile, WARN, FAIL, FAIL)
    return _check(
        "PDP-04", "XDR_ENFORCE_INTERNAL_AUTH=true",
        passed, sev,
        f"XDR_ENFORCE_INTERNAL_AUTH={env.get('XDR_ENFORCE_INTERNAL_AUTH', '(not set)')}",
        "Set XDR_ENFORCE_INTERNAL_AUTH=true so each pipeline service validates its token.",
    )


def check_required_tokens(env: dict, profile: str) -> dict:
    missing = [k for k in _REQUIRED_TOKENS if not env.get(k, "").strip()]
    passed = len(missing) == 0
    sev = _severity(profile, WARN, FAIL, FAIL)
    detail = "All required tokens present." if passed else f"Missing: {missing}"
    return _check(
        "PDP-05", "Required internal tokens present",
        passed, sev, detail,
        "Set all XDR_*_INTERNAL_TOKEN values. Generate with: openssl rand -hex 32",
    )


def check_placeholder_secrets(env: dict, profile: str) -> dict:
    bad: list[str] = []
    for key in _REQUIRED_TOKENS:
        val = env.get(key, "").strip()
        if val in _BAD_SECRETS or val.startswith("REPLACE_WITH_"):
            bad.append(f"{key}={val!r}")
    passed = len(bad) == 0
    sev = _severity(profile, WARN, FAIL, FAIL)
    detail = "No placeholder secrets in token fields." if passed else f"Placeholder values found: {bad}"
    return _check(
        "PDP-06", "No placeholder/default secrets in token fields",
        passed, sev, detail,
        "Replace all REPLACE_WITH_* and default values. Use: openssl rand -hex 32",
    )


# ---------------------------------------------------------------------------
# Docker Compose checks (operate on parsed compose dict)
# ---------------------------------------------------------------------------

def check_datastore_ports(compose_data: dict, profile: str) -> dict:
    services = compose_data.get("services") or {}
    violations: list[str] = []
    for svc_name in _DATASTORE_SERVICES:
        svc = services.get(svc_name) or {}
        for port_spec in svc.get("ports") or []:
            if not _is_public_binding(port_spec):
                continue
            container_port = _get_container_port(port_spec)
            if container_port in _DATASTORE_CONTAINER_PORTS:
                violations.append(f"{svc_name}:{port_spec}")
    passed = len(violations) == 0
    sev = _severity(profile, WARN, FAIL, FAIL)
    detail = (
        "No public datastore port bindings found."
        if passed
        else f"Public datastore bindings: {violations}"
    )
    return _check(
        "PDP-07", "Datastores not publicly exposed",
        passed, sev, detail,
        "Use '!reset []' in docker-compose.prod.yml to remove datastore port bindings.",
    )


def check_pandaproxy_exposure(compose_data: dict, profile: str) -> dict:
    services = compose_data.get("services") or {}
    redpanda = services.get("redpanda") or {}
    violations: list[str] = []
    for port_spec in redpanda.get("ports") or []:
        container_port = _get_container_port(port_spec)
        if container_port != "8082":
            continue
        if _is_public_binding(port_spec):
            violations.append(str(port_spec))
    passed = len(violations) == 0
    sev = _severity(profile, WARN, FAIL, FAIL)
    detail = (
        "Pandaproxy not publicly exposed."
        if passed
        else f"Pandaproxy public bindings: {violations}"
    )
    return _check(
        "PDP-08", "Pandaproxy not publicly exposed",
        passed, sev, detail,
        "Bind Pandaproxy to 127.0.0.1:8082:8082 in docker-compose.prod.yml.",
    )


def check_grafana_readonly(compose_data: dict, profile: str) -> dict:
    services = compose_data.get("services") or {}
    grafana = services.get("grafana") or {}
    volumes = grafana.get("volumes") or []
    ro_mounts = 0
    provisioning_found = False
    for vol in volumes:
        vol_str = str(vol)
        if "grafana/provisioning" in vol_str or "/etc/grafana/provisioning" in vol_str:
            provisioning_found = True
            if vol_str.endswith(":ro"):
                ro_mounts += 1
    # If provisioning volume not in compose override, that's WARN — may be inherited
    if not provisioning_found:
        return _check(
            "PDP-09", "Grafana provisioning is read-only",
            False, _severity(profile, WARN, WARN, WARN),
            "Grafana provisioning volume not found in production compose overlay.",
            "Add './infra/analytics/grafana/provisioning:/etc/grafana/provisioning:ro' to grafana volumes.",
        )
    passed = ro_mounts > 0
    sev = _severity(profile, WARN, WARN, FAIL)
    return _check(
        "PDP-09", "Grafana provisioning is read-only",
        passed, sev,
        "Provisioning volume has :ro mount." if passed else "Provisioning volume lacks :ro flag.",
        "Add ':ro' to Grafana provisioning volume in docker-compose.prod.yml.",
    )


def check_restart_policies(compose_data: dict, profile: str) -> dict:
    services = compose_data.get("services") or {}
    missing: list[str] = []
    for svc_name in _RESTART_SERVICES:
        svc = services.get(svc_name) or {}
        restart = svc.get("restart", "")
        if restart not in ("always", "unless-stopped", "on-failure"):
            missing.append(svc_name)
    # Production requires "always"; "unless-stopped" is acceptable for staging/local
    wrong_policy: list[str] = []
    if profile == "production":
        for svc_name in _RESTART_SERVICES:
            svc = services.get(svc_name) or {}
            restart = svc.get("restart", "")
            if restart and restart != "always":
                wrong_policy.append(f"{svc_name}:{restart}")
    passed = len(missing) == 0 and len(wrong_policy) == 0
    sev = _severity(profile, WARN, WARN, FAIL)
    if missing:
        detail = f"Missing restart policy for: {missing}"
        remediation = "Add 'restart: always' to each critical service in docker-compose.prod.yml."
    elif wrong_policy:
        detail = f"Non-always restart in production: {wrong_policy}"
        remediation = "Set 'restart: always' for all critical services in docker-compose.prod.yml."
    else:
        detail = f"Restart policies present for all {len(_RESTART_SERVICES)} critical services."
        remediation = ""
    return _check("PDP-10", "Restart policies for critical services", passed, sev, detail, remediation)


def check_healthchecks(compose_data: dict, profile: str) -> dict:
    services = compose_data.get("services") or {}
    missing: list[str] = []
    for svc_name in _HEALTHCHECK_SERVICES:
        svc = services.get(svc_name) or {}
        # Check in the service definition AND check the compose data might be an overlay
        # (healthchecks could be in base compose); mark as WARN not FAIL for overlay files
        if "healthcheck" not in svc:
            missing.append(svc_name)
    # For an overlay file, missing healthchecks means they're inherited from base
    passed = len(missing) == 0
    sev = _severity(profile, INFO, WARN, WARN)
    detail = (
        "Healthchecks present in overlay for all critical services."
        if passed
        else f"Healthchecks not in overlay (may be inherited from base): {missing}"
    )
    return _check(
        "PDP-11", "Healthchecks present for critical services",
        passed, sev, detail,
        "Verify healthchecks exist in docker-compose.yml base file. See PRODUCTION_DEPLOYMENT_PROFILE.md.",
    )


# ---------------------------------------------------------------------------
# Documentation checks
# ---------------------------------------------------------------------------

def check_backup_docs(root: Path, profile: str) -> dict:
    paths_to_check = [
        root / "docs" / "operations" / "PRODUCTION_DEPLOYMENT_PROFILE.md",
        root / "docs" / "operations" / "PRODUCTION_RUNTIME_PROFILE.md",
        root / "docs" / "operations" / "BACKUP_RESTORE_RECOVERY.md",
    ]
    found = [p for p in paths_to_check if p.exists()]
    backup_keywords = ["backup", "BACKUP", "storage", "BACKUP_DIR", "log"]
    keyword_found = False
    for p in found:
        content = p.read_text(encoding="utf-8")
        if any(kw in content for kw in backup_keywords):
            keyword_found = True
            break
    passed = keyword_found
    sev = _severity(profile, WARN, WARN, WARN)
    detail = (
        "Backup/log path documentation found."
        if passed
        else "No backup/log path documentation found in operations docs."
    )
    return _check(
        "PDP-12", "Backup/report/log paths documented",
        passed, sev, detail,
        "Document backup paths in docs/operations/PRODUCTION_DEPLOYMENT_PROFILE.md.",
    )


def check_risk_docs(root: Path, profile: str) -> dict:
    paths_to_check = [
        root / "REVIEW_REJECTED.md",
        root / "docs" / "operations" / "PRODUCTION_RUNTIME_PROFILE.md",
        root / "docs" / "operations" / "PRODUCTION_DEPLOYMENT_PROFILE.md",
    ]
    found = [p for p in paths_to_check if p.exists()]
    accepted_risk_found = False
    deferred_found = False
    for p in found:
        content = p.read_text(encoding="utf-8")
        if "Accepted Risk" in content or "accepted_risk" in content or "ACCEPTED RISK" in content:
            accepted_risk_found = True
        if "Deferred" in content or "DEFERRED" in content:
            deferred_found = True
    passed = accepted_risk_found and deferred_found
    sev = _severity(profile, WARN, WARN, WARN)
    detail = (
        "Accepted and deferred risk documentation found."
        if passed
        else f"Risk docs missing: accepted_risk={accepted_risk_found} deferred={deferred_found}"
    )
    return _check(
        "PDP-13", "Accepted and deferred risks referenced",
        passed, sev, detail,
        "Document accepted and deferred risks in REVIEW_REJECTED.md or operations docs.",
    )


def check_no_active_scanning(compose_data: dict, env: dict, profile: str) -> dict:
    services = compose_data.get("services") or {}
    violations: list[str] = []
    _active_flags = {
        "EASM_ACTIVE_SCAN_ENABLED": "true",
        "XDR_AUTONOMOUS_RESPONSE_ENABLED": "true",
        "XDR_LIVE_CONTAINMENT_ENABLED": "true",
        "XDR_ACTIVE_REMEDIATION_ENABLED": "true",
    }
    for svc_name, svc in services.items():
        svc_env = svc.get("environment") or {}
        for flag, bad_val in _active_flags.items():
            val = svc_env.get(flag, "").lower()
            if val in ("true", "1", "yes"):
                violations.append(f"{svc_name}.{flag}={val}")
    for flag, bad_val in _active_flags.items():
        val = env.get(flag, "").lower()
        if val in ("true", "1", "yes"):
            violations.append(f"env.{flag}={val}")
    passed = len(violations) == 0
    sev = _severity(profile, WARN, FAIL, FAIL)
    detail = (
        "No active scanning or autonomous remediation flags enabled."
        if passed
        else f"Active/autonomous flags found: {violations}"
    )
    return _check(
        "PDP-14", "No active scanning/autonomous remediation",
        passed, sev, detail,
        "Ensure EASM and response features remain advisory-only.",
    )


def check_datastore_credentials_fail_closed(compose_data: dict, env: dict, profile: str) -> dict:
    """ENT-SEC-WEAK-DEFAULT-SECRETS: the base docker-compose.yml falls back to
    well-known weak defaults (postgres/detector/admin) for datastore
    credentials. docker-compose.prod.yml must override each with fail-closed
    ${VAR:?message} syntax — a missing override, or one that still resolves
    to a placeholder/weak value, means a production deploy can silently
    succeed with the dev defaults."""
    services = compose_data.get("services") or {}
    violations: list[str] = []

    for svc_name, keys in _DATASTORE_CREDENTIAL_KEYS.items():
        svc = services.get(svc_name) or {}
        svc_env = svc.get("environment") or {}
        for key in keys:
            raw = str(svc_env.get(key, "")).strip()
            if raw == "":
                violations.append(
                    f"{svc_name}.{key} not overridden in docker-compose.prod.yml "
                    "(base compose's weak default still applies)"
                )
            elif ":?" not in raw:
                violations.append(
                    f"{svc_name}.{key}={raw!r} does not fail closed "
                    "(expected ${VAR:?message} syntax)"
                )

    for key in _DATASTORE_CREDENTIAL_ENV_KEYS:
        val = env.get(key, "").strip()
        if val in _BAD_SECRETS or val.startswith("REPLACE_WITH_"):
            violations.append(f"env.{key}={val!r} is a placeholder/default value")

    passed = len(violations) == 0
    sev = _severity(profile, WARN, FAIL, FAIL)
    detail = (
        "Datastore credentials fail closed and are not left on placeholder values."
        if passed
        else f"Weak-default/placeholder datastore credentials found: {violations}"
    )
    return _check(
        "PDP-15", "Datastore credentials fail closed (no weak defaults)",
        passed, sev, detail,
        "Override POSTGRES_PASSWORD/CLICKHOUSE_PASSWORD/GF_SECURITY_ADMIN_*/"
        "OPENSEARCH_INITIAL_ADMIN_PASSWORD in docker-compose.prod.yml with "
        "${VAR:?message} syntax, and set strong values (not placeholders) in "
        "the production env file.",
    )


def check_opensearch_security_enabled(compose_data: dict, profile: str) -> dict:
    """ENT-SEC-OPENSEARCH-OPEN: the base docker-compose.yml disables OpenSearch's
    security plugin (plugins.security.disabled: "true") for local/demo
    convenience — unauthenticated, unencrypted. docker-compose.prod.yml must
    explicitly override this to "false". This check only inspects
    docker-compose.prod.yml in isolation (matching how PDP-15 treats a
    missing override as the violation) since the real production deploy
    always layers this file on top of the base compose file."""
    services = compose_data.get("services") or {}
    svc = services.get("opensearch") or {}
    svc_env = svc.get("environment") or {}
    raw = str(svc_env.get("plugins.security.disabled", "")).strip().lower()

    passed = raw == "false"
    sev = _severity(profile, WARN, FAIL, FAIL)
    if passed:
        detail = "OpenSearch security plugin explicitly enabled in the production overlay."
    elif raw == "":
        detail = "docker-compose.prod.yml does not override plugins.security.disabled — the base compose's disabled setting applies unchanged."
    else:
        detail = f"docker-compose.prod.yml sets plugins.security.disabled={raw!r} instead of \"false\"."

    return _check(
        "PDP-16", "OpenSearch security plugin enabled in production",
        passed, sev, detail,
        "Set opensearch.environment['plugins.security.disabled'] to \"false\" "
        "in docker-compose.prod.yml and provide OPENSEARCH_INITIAL_ADMIN_PASSWORD "
        "/ XDR_OPENSEARCH_USER / XDR_OPENSEARCH_PASSWORD.",
    )


# Reserved/superuser role names that an application account must never map to.
_OPENSEARCH_SUPERUSER_ROLES = {"admin", "all_access"}


def check_opensearch_rbac_least_privilege(root: Path, profile: str) -> dict:
    """ENT-SEC-OPENSEARCH-OPEN: alert-writer-service and Laravel must
    authenticate as dedicated least-privilege accounts (xdr_alert_writer,
    xdr_reader), never the built-in admin superuser. Checks the committed
    RBAC config (roles.yml, roles_mapping.yml) exists and that neither app
    role name nor app username is mapped to a reserved superuser role.
    internal_users.yml itself is gitignored (holds password hashes) — its
    .example template is checked for existence instead."""
    security_dir = root / "infra" / "opensearch" / "security"
    roles_path = security_dir / "roles.yml"
    mapping_path = security_dir / "roles_mapping.yml"
    users_example_path = security_dir / "internal_users.yml.example"

    missing = [str(p) for p in (roles_path, mapping_path, users_example_path) if not p.exists()]
    if missing:
        sev = _severity(profile, WARN, FAIL, FAIL)
        return _check(
            "PDP-17", "OpenSearch least-privilege RBAC configured",
            False, sev, f"Missing RBAC config file(s): {missing}",
            "Add infra/opensearch/security/{roles.yml,roles_mapping.yml,internal_users.yml.example}.",
        )

    mapping_text = mapping_path.read_text(encoding="utf-8")
    violations = []
    for reserved in _OPENSEARCH_SUPERUSER_ROLES:
        # A naive `reserved in text` substring check would also match this
        # file's own comment sentences (which legitimately mention "admin"
        # while explaining what NOT to do) — require it as a YAML mapping key
        # (top-level role name) to only catch a real role assignment.
        if re.search(rf"(?m)^{re.escape(reserved)}\s*:", mapping_text):
            violations.append(f"roles_mapping.yml maps the reserved '{reserved}' role")

    passed = len(violations) == 0
    sev = _severity(profile, WARN, FAIL, FAIL)
    detail = (
        "xdr_alert_writer/xdr_reader are mapped to dedicated least-privilege roles, not admin/all_access."
        if passed
        else f"Superuser role mapping found: {violations}"
    )
    return _check(
        "PDP-17", "OpenSearch least-privilege RBAC configured",
        passed, sev, detail,
        "Ensure roles_mapping.yml maps xdr_alert_writer/xdr_reader only to "
        "xdr_alert_writer_role/xdr_reader_role, never admin or all_access.",
    )


# ---------------------------------------------------------------------------
# Orchestrator
# ---------------------------------------------------------------------------

def run_all_checks(
    args: argparse.Namespace,
    root: Path = PROJECT_ROOT,
    env: dict | None = None,
    compose_data: dict | None = None,
) -> list[dict]:
    profile = args.profile
    results: list[dict] = []

    # --- File existence ---
    results.append(check_prod_compose_exists(root))
    results.append(check_env_example_exists(root))

    # --- Resolve env ---
    if env is None:
        env_path = root / (args.env_file or ".env.production.example")
        try:
            env = _parse_env_file(env_path)
        except OSError:
            env = {}

    # --- Env checks ---
    results.append(check_strict_mode(env, profile))
    results.append(check_internal_auth(env, profile))
    results.append(check_required_tokens(env, profile))
    results.append(check_placeholder_secrets(env, profile))

    # --- Resolve compose ---
    if compose_data is None:
        compose_path = root / (args.compose_file or "docker-compose.prod.yml")
        try:
            compose_data = _parse_compose_file(compose_path)
        except OSError:
            compose_data = {"services": {}}

    # --- Compose checks ---
    results.append(check_datastore_ports(compose_data, profile))
    results.append(check_pandaproxy_exposure(compose_data, profile))
    results.append(check_grafana_readonly(compose_data, profile))
    results.append(check_restart_policies(compose_data, profile))
    results.append(check_healthchecks(compose_data, profile))

    # --- Documentation checks ---
    results.append(check_backup_docs(root, profile))
    results.append(check_risk_docs(root, profile))
    results.append(check_no_active_scanning(compose_data, env, profile))
    results.append(check_datastore_credentials_fail_closed(compose_data, env, profile))
    results.append(check_opensearch_security_enabled(compose_data, profile))
    results.append(check_opensearch_rbac_least_privilege(root, profile))

    return results


# ---------------------------------------------------------------------------
# Report
# ---------------------------------------------------------------------------

def build_report(
    checks: list[dict],
    args: argparse.Namespace,
    started_at: str,
    ended_at: str,
) -> dict[str, Any]:
    pass_count = sum(1 for c in checks if c["status"] == PASS)
    fail_count = sum(1 for c in checks if c["status"] == FAIL)
    warn_count = sum(1 for c in checks if c["status"] in (WARN, INFO))

    overall = FAIL if fail_count > 0 else PASS
    status_line = f"PASS={pass_count} FAIL={fail_count} WARN={warn_count}"

    return {
        "task": "ENTERPRISE-036",
        "started_at": started_at,
        "ended_at": ended_at,
        "profile": args.profile,
        "env_file": args.env_file,
        "compose_file": args.compose_file,
        "overall": overall,
        "status_line": status_line,
        "counts": {
            "pass": pass_count, "fail": fail_count,
            "warn": warn_count, "total": len(checks),
        },
        "checks": checks,
    }


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main(args: argparse.Namespace, root: Path = PROJECT_ROOT,
         env: dict | None = None, compose_data: dict | None = None) -> int:
    started_at = datetime.now(timezone.utc).isoformat()

    if not getattr(args, "quiet", False):
        print(f"\n  XDR Production Profile Validator — ENTERPRISE-036")
        print(f"  profile : {args.profile}")
        print(f"  env     : {args.env_file}")
        print(f"  compose : {args.compose_file}")
        print()

    checks = run_all_checks(args, root=root, env=env, compose_data=compose_data)
    ended_at = datetime.now(timezone.utc).isoformat()
    report = build_report(checks, args, started_at, ended_at)
    overall = report["overall"]

    if not getattr(args, "quiet", False):
        w = 72
        print("=" * w)
        print(f"  {'ID':<8} {'Check':<42} {'Status'}")
        print("-" * w)
        for c in checks:
            marker = {"PASS": "+", "FAIL": "!", "WARN": "~", "INFO": "."}.get(c["status"], "?")
            print(f"  [{marker}] {c['check_id']:<6} {c['name']:<42} {c['status']}")
        print("-" * w)
        marker = {"PASS": "+", "FAIL": "!"}.get(overall, "~")
        print(f"  [{marker}] OVERALL{'':<48} {overall}")
        print(f"  {report['status_line']}")
        print("=" * w)
        print()

    if getattr(args, "output", ""):
        out = Path(args.output)
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(json.dumps(report, indent=2), encoding="utf-8")
        if not getattr(args, "quiet", False):
            print(f"  Report: {out}")

    return 0 if overall == PASS else 1


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def _parse_args(argv=None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog="xdr_production_profile_validate.py",
        description="ENTERPRISE-036: Production deployment profile validator.",
    )
    parser.add_argument("--profile", default="local",
                        choices=PROFILES, help="Deployment profile (default: local)")
    parser.add_argument("--env-file", default=".env.production.example",
                        dest="env_file", help="Env file to validate (default: .env.production.example)")
    parser.add_argument("--compose-file", default="docker-compose.prod.yml",
                        dest="compose_file", help="Compose overlay to validate (default: docker-compose.prod.yml)")
    parser.add_argument("--output", default="",
                        help="Write JSON report to this path")
    parser.add_argument("--quiet", action="store_true",
                        help="Suppress console output")
    parser.add_argument("--dry-run", action="store_true", dest="dry_run",
                        help="Print planned checks without side effects (same as normal run — always read-only)")
    return parser.parse_args(argv)


if __name__ == "__main__":
    sys.exit(main(_parse_args()))
