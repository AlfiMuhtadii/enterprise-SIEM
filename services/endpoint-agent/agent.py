#!/usr/bin/env python3
"""
XDR Endpoint Telemetry Agent
=============================
Linux-first, Windows-compatible. No kernel driver. No privilege escalation.
Telemetry-only — collects process, network, DNS, file, scheduled-task, and
service events. On Linux, uses /proc and /proc/net/tcp directly (no subprocess).
Sends events to the XDR ingestion gateway (POST /v1/ingest) signed with
HMAC-SHA256. Registers with and heartbeats to the Laravel SOC control-plane.

Requirements: Python 3.9+, stdlib only (no pip dependencies).
"""

from __future__ import annotations

import argparse
import hashlib
import hmac
import ipaddress
import json
import logging
import os
import platform
import re
import socket
import struct
import subprocess
import sys
import time
import traceback
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Any
from urllib import request as urllib_request
from urllib.error import URLError, HTTPError

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------

logging.basicConfig(
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    datefmt="%Y-%m-%dT%H:%M:%S",
    level=logging.INFO,
    stream=sys.stdout,
)
log = logging.getLogger("xdr-agent")


# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------

AGENT_SOURCE = "endpoint-agent"
SCHEMA_VERSION = 1
TELEMETRY_TYPE = "endpoint"

# ---------------------------------------------------------------------------
# Behavioral visibility constants (Phase 1)
# ---------------------------------------------------------------------------

SHELL_PROCESS_NAMES: frozenset[str] = frozenset([
    "bash", "sh", "zsh", "dash", "ksh", "tcsh", "fish",
    "python", "python3", "python2", "perl", "ruby",
    "curl", "wget",
])

WEB_SERVER_PROCESS_NAMES: frozenset[str] = frozenset([
    "nginx", "apache", "apache2", "httpd", "gunicorn",
    "uwsgi", "php-fpm", "tomcat", "mysqld", "postgres", "mongod",
])

LONG_LIVED_THRESHOLD_SECONDS: int = 3600  # 1 hour

DEFAULT_CONFIG: dict[str, Any] = {
    "ingestion_gateway_url": "http://127.0.0.1:8091",
    "ingestion_gateway_secret": "dev-secret-change-me",
    "soc_api_url": "http://127.0.0.1:8000",
    "enrollment_token": "",
    "state_path": "/var/lib/xdr-agent/state.json",
    "buffer_path": "/var/lib/xdr-agent/buffer.jsonl",
    "collection_interval_seconds": 30,
    "heartbeat_interval_seconds": 60,
    "max_batch_size": 100,
    "buffer_size": 1000,
    "max_buffer_size": 5000,
    "disk_pressure_threshold_mb": 100,
    "retry_max_attempts": 3,
    "retry_base_seconds": 1.0,
    "telemetry": {
        "process": True,
        "network": True,
        "dns": True,
        "file": False,
        "scheduled_tasks": True,
        "services": True,
    },
    "watch_paths": [],
    # dns_fixture_path: if set, read JSONL fixture for DNS simulation (one {"domain":...,"query_type":...} per line)
    "dns_fixture_path": None,
    # log_paths: used when dns_fixture_path is not set (syslog tailing fallback)
    "log_paths": ["/var/log/syslog", "/var/log/messages"],
}

IS_WINDOWS = platform.system() == "Windows"
IS_LINUX = platform.system() == "Linux"


# ---------------------------------------------------------------------------
# Stable host identity
# ---------------------------------------------------------------------------

def _machine_id() -> str:
    """Return a stable machine UUID string regardless of OS."""
    if IS_LINUX:
        for candidate in ("/etc/machine-id", "/var/lib/dbus/machine-id"):
            try:
                data = Path(candidate).read_text().strip()
                if data:
                    return data
            except OSError:
                pass
    if IS_WINDOWS:
        try:
            result = subprocess.run(
                ["wmic", "csproduct", "get", "UUID"],
                capture_output=True, text=True, timeout=5,
            )
            for line in result.stdout.splitlines():
                line = line.strip()
                if line and line.upper() != "UUID":
                    return line
        except Exception:
            pass
    return socket.gethostname()


def _stable_host_id() -> str:
    """sha256(machine_uuid + hostname) — stable across agent restarts."""
    hostname = socket.gethostname()
    raw = _machine_id() + "|" + hostname
    return hashlib.sha256(raw.encode()).hexdigest()


HOST_ID = _stable_host_id()
HOSTNAME = socket.gethostname()


# ---------------------------------------------------------------------------
# Config loading
# ---------------------------------------------------------------------------

def load_config(path: str) -> dict[str, Any]:
    cfg = dict(DEFAULT_CONFIG)
    cfg["telemetry"] = dict(DEFAULT_CONFIG["telemetry"])
    try:
        raw = json.loads(Path(path).read_text(encoding="utf-8"))
        if "telemetry" in raw and isinstance(raw["telemetry"], dict):
            cfg["telemetry"].update(raw["telemetry"])
            raw.pop("telemetry")
        cfg.update(raw)
        log.info("Config loaded from %s", path)
    except FileNotFoundError:
        log.warning("Config file not found at %s — using defaults", path)
    except json.JSONDecodeError as exc:
        log.error("Config JSON parse error: %s — using defaults", exc)
    return cfg


# ---------------------------------------------------------------------------
# State persistence (enrollment)
# ---------------------------------------------------------------------------

def load_state(state_path: str) -> dict[str, Any]:
    try:
        return json.loads(Path(state_path).read_text(encoding="utf-8"))
    except FileNotFoundError:
        return {}
    except json.JSONDecodeError:
        log.warning("State file corrupt; starting fresh")
        return {}


def save_state(state_path: str, state: dict[str, Any]) -> None:
    p = Path(state_path)
    p.parent.mkdir(parents=True, exist_ok=True)
    p.write_text(json.dumps(state, indent=2), encoding="utf-8")


# ---------------------------------------------------------------------------
# HMAC signing
# ---------------------------------------------------------------------------

def sign_body(secret: str, body: bytes) -> str:
    """Return the X-XDR-Signature header value: 'sha256=<hex>'."""
    mac = hmac.new(secret.encode(), body, hashlib.sha256)
    return "sha256=" + mac.hexdigest()


# ---------------------------------------------------------------------------
# HTTP helpers
# ---------------------------------------------------------------------------

def _http_post(
    url: str,
    payload: bytes,
    headers: dict[str, str],
    timeout: int = 15,
) -> tuple[int, bytes]:
    """Simple HTTP POST using stdlib urllib. Returns (status_code, body_bytes)."""
    req = urllib_request.Request(url, data=payload, headers=headers, method="POST")
    try:
        with urllib_request.urlopen(req, timeout=timeout) as resp:
            return resp.status, resp.read()
    except HTTPError as exc:
        return exc.code, exc.read()


def _http_get(
    url: str,
    headers: dict[str, str] | None = None,
    timeout: int = 10,
) -> tuple[int, bytes]:
    req = urllib_request.Request(url, headers=headers or {}, method="GET")
    try:
        with urllib_request.urlopen(req, timeout=timeout) as resp:
            return resp.status, resp.read()
    except HTTPError as exc:
        return exc.code, exc.read()


# ---------------------------------------------------------------------------
# Replay-safe event ID
# ---------------------------------------------------------------------------

def make_event_id(*stable_parts: Any) -> str:
    """sha256 of stable content fields — deterministic, replay-safe."""
    content = "|".join(str(p) for p in stable_parts)
    return hashlib.sha256(content.encode()).hexdigest()


# ---------------------------------------------------------------------------
# Base event builder
# ---------------------------------------------------------------------------

def base_event(
    event_type: str,
    agent_id: str,
    trace_id: str,
    *,
    process_name: str | None = None,
    process_path: str | None = None,
    parent_process: str | None = None,
    user: str | None = None,
    extra: dict[str, Any] | None = None,
) -> dict[str, Any]:
    now = datetime.now(timezone.utc).isoformat()
    ev: dict[str, Any] = {
        "ts": now,
        "timestamp": now,
        "telemetry_type": TELEMETRY_TYPE,
        "event_type": event_type,
        "host": HOSTNAME,
        "hostname": HOSTNAME,
        "host_id": HOST_ID,
        "agent_id": agent_id,
        "trace_id": trace_id,
        "source": AGENT_SOURCE,
        "event_source": AGENT_SOURCE,
        "schema_version": SCHEMA_VERSION,
        "process_name": process_name,
        "process_path": process_path,
        "parent_process": parent_process,
        "user": user,
    }
    if extra:
        ev.update(extra)
    return ev


# ---------------------------------------------------------------------------
# Telemetry quality metrics
# ---------------------------------------------------------------------------

class QualityMetrics:
    """In-process telemetry quality counters. Thread-unsafe — single-threaded agent only."""

    __slots__ = (
        "events_sent", "events_dropped", "retry_count",
        "collection_cycles", "last_successful_send",
        "_cycle_start", "_events_this_cycle",
    )

    def __init__(self) -> None:
        self.events_sent: int = 0
        self.events_dropped: int = 0
        self.retry_count: int = 0
        self.collection_cycles: int = 0
        self.last_successful_send: str | None = None
        self._cycle_start: float = time.monotonic()
        self._events_this_cycle: int = 0

    def record_sent(self, count: int) -> None:
        self.events_sent += count
        self._events_this_cycle += count
        self.last_successful_send = datetime.now(timezone.utc).isoformat()

    def record_dropped(self, count: int = 1) -> None:
        self.events_dropped += count

    def record_retry(self) -> None:
        self.retry_count += 1

    def record_cycle(self) -> None:
        self.collection_cycles += 1
        self._cycle_start = time.monotonic()
        self._events_this_cycle = 0

    def events_per_sec(self) -> float:
        elapsed = max(time.monotonic() - self._cycle_start, 0.001)
        return round(self._events_this_cycle / elapsed, 2)

    def snapshot(self, buffer_depth: int = 0) -> dict[str, Any]:
        return {
            "events_per_sec":       self.events_per_sec(),
            "dropped_events":       self.events_dropped,
            "retry_count":          self.retry_count,
            "buffer_depth":         buffer_depth,
            "total_sent":           self.events_sent,
            "collection_cycles":    self.collection_cycles,
            "last_successful_send": self.last_successful_send,
        }


# ---------------------------------------------------------------------------
# Hardened buffer — max size + disk pressure guard
# ---------------------------------------------------------------------------

class HardenedBuffer:
    """
    In-memory event buffer with capacity and disk-pressure guards.
    Events are dropped (and counted) when the buffer is full or disk space is low.
    This sits in front of LocalBuffer (disk fallback) for short-term in-cycle buffering.
    """

    def __init__(
        self,
        max_size: int = 5000,
        disk_pressure_threshold_mb: float = 100.0,
    ) -> None:
        self.max_size = max_size
        self.disk_pressure_threshold_mb = disk_pressure_threshold_mb
        self._buffer: list[dict[str, Any]] = []
        self.dropped: int = 0

    def push(self, event: dict[str, Any]) -> bool:
        """Return True if the event was accepted, False if dropped."""
        if len(self._buffer) >= self.max_size:
            self.dropped += 1
            log.debug("HardenedBuffer full (%d/%d) — event dropped", len(self._buffer), self.max_size)
            return False
        if self._disk_pressure():
            self.dropped += 1
            log.warning(
                "HardenedBuffer: disk pressure below %dMB — event dropped",
                self.disk_pressure_threshold_mb,
            )
            return False
        self._buffer.append(event)
        return True

    def push_batch(self, events: list[dict[str, Any]]) -> tuple[int, int]:
        """Push a batch. Returns (accepted, dropped)."""
        accepted = 0
        dropped = 0
        for ev in events:
            if self.push(ev):
                accepted += 1
            else:
                dropped += 1
        return accepted, dropped

    def drain(self) -> list[dict[str, Any]]:
        """Return all buffered events and clear the in-memory buffer."""
        events = list(self._buffer)
        self._buffer.clear()
        return events

    def depth(self) -> int:
        return len(self._buffer)

    def _disk_pressure(self) -> bool:
        """Returns True when free disk space is below threshold."""
        try:
            import shutil
            free_mb = shutil.disk_usage("/").free / (1024 * 1024)
            return free_mb < self.disk_pressure_threshold_mb
        except Exception:
            return False


# ---------------------------------------------------------------------------
# Local JSONL buffer (offline fallback)
# ---------------------------------------------------------------------------

class LocalBuffer:
    """Append-only JSONL buffer written when the gateway is unreachable."""

    def __init__(self, path: str) -> None:
        self.path = Path(path)
        self.path.parent.mkdir(parents=True, exist_ok=True)

    def write(self, events: list[dict[str, Any]]) -> None:
        with self.path.open("a", encoding="utf-8") as fh:
            for ev in events:
                fh.write(json.dumps(ev, separators=(",", ":")) + "\n")

    def drain(self) -> list[dict[str, Any]]:
        """Read all buffered events and clear the file atomically."""
        if not self.path.exists():
            return []
        events: list[dict[str, Any]] = []
        with self.path.open("r", encoding="utf-8") as fh:
            for line in fh:
                line = line.strip()
                if not line:
                    continue
                try:
                    events.append(json.loads(line))
                except json.JSONDecodeError:
                    pass
        self.path.write_text("", encoding="utf-8")
        return events

    def size(self) -> int:
        if not self.path.exists():
            return 0
        with self.path.open(encoding="utf-8") as fh:
            return sum(1 for line in fh if line.strip())


# ---------------------------------------------------------------------------
# Ingestion gateway shipping
# ---------------------------------------------------------------------------

class GatewayClient:
    """Ships event batches to the ingestion gateway with retry + buffer fallback."""

    MAX_RETRIES = 3
    RETRY_BASE_SECONDS = 1.0

    def __init__(self, cfg: dict[str, Any], buffer: LocalBuffer) -> None:
        self.url = cfg["ingestion_gateway_url"].rstrip("/") + "/v1/ingest"
        self.secret = cfg["ingestion_gateway_secret"]
        self.buffer = buffer

    def _send_raw(self, events: list[dict[str, Any]]) -> bool:
        """Send a batch directly. Returns True on success."""
        body = json.dumps(events, separators=(",", ":")).encode()
        headers: dict[str, str] = {"Content-Type": "application/json"}
        if self.secret:
            headers["X-XDR-Signature"] = sign_body(self.secret, body)

        for attempt in range(self.MAX_RETRIES):
            try:
                status, resp_body = _http_post(self.url, body, headers)
                if 200 <= status < 300:
                    log.debug("Shipped %d events — HTTP %d", len(events), status)
                    return True
                log.warning(
                    "Gateway returned HTTP %d (attempt %d/%d): %s",
                    status, attempt + 1, self.MAX_RETRIES,
                    resp_body[:200].decode(errors="replace"),
                )
            except (URLError, OSError) as exc:
                log.warning(
                    "Gateway unreachable (attempt %d/%d): %s",
                    attempt + 1, self.MAX_RETRIES, exc,
                )
            if attempt < self.MAX_RETRIES - 1:
                delay = self.RETRY_BASE_SECONDS * (2 ** attempt)
                time.sleep(delay)
        return False

    def ship(self, events: list[dict[str, Any]]) -> None:
        """Ship events, falling back to local buffer on failure."""
        if not events:
            return
        buffered = self.buffer.drain()
        all_events = buffered + events
        if self._send_raw(all_events):
            return
        log.warning("Gateway unavailable; buffering %d events locally", len(all_events))
        self.buffer.write(all_events)


# ---------------------------------------------------------------------------
# SOC API client (enrollment + heartbeat)
# ---------------------------------------------------------------------------

class SOCClient:
    """Registers with and sends heartbeats to the Laravel SOC control-plane."""

    def __init__(self, cfg: dict[str, Any]) -> None:
        self.base_url = cfg["soc_api_url"].rstrip("/")
        self.enrollment_token = cfg.get("enrollment_token", "")

    def _auth_headers(self) -> dict[str, str]:
        headers = {"Content-Type": "application/json", "Accept": "application/json"}
        if self.enrollment_token:
            headers["Authorization"] = f"Bearer {self.enrollment_token}"
        return headers

    def register(self) -> str | None:
        """POST /api/agents/register. Returns agent_id string on success."""
        payload = json.dumps({
            "host_id": HOST_ID,
            "hostname": HOSTNAME,
            "platform": platform.system(),
            "platform_version": platform.version(),
            "agent_version": "1.0.0",
            "source": AGENT_SOURCE,
        }).encode()
        url = f"{self.base_url}/api/agents/register"
        try:
            status, body = _http_post(url, payload, self._auth_headers())
            if 200 <= status < 300:
                data = json.loads(body)
                agent_id = data.get("agent_id") or data.get("id")
                if agent_id:
                    log.info("Enrolled with SOC — agent_id=%s", agent_id)
                    return str(agent_id)
                log.warning("Register succeeded but no agent_id in response: %s", body[:200])
            else:
                log.warning(
                    "SOC register returned HTTP %d: %s",
                    status, body[:200].decode(errors="replace"),
                )
        except (URLError, OSError) as exc:
            log.warning("SOC register failed (network): %s", exc)
        except json.JSONDecodeError as exc:
            log.warning("SOC register response not JSON: %s", exc)
        return None

    def _sign_payload(self, payload: bytes) -> str:
        """HMAC-SHA256 over heartbeat payload using enrollment_token as key."""
        if not self.enrollment_token:
            return ""
        mac = hmac.new(self.enrollment_token.encode(), payload, hashlib.sha256)
        return "sha256=" + mac.hexdigest()

    def heartbeat(
        self,
        agent_id: str,
        metrics: dict[str, Any] | None = None,
        trace_id: str | None = None,
    ) -> bool:
        """
        POST /api/agents/{agentId}/heartbeat — signed with X-Agent-Signature.
        Returns True on success.
        """
        heartbeat_data = {
            "agent_id":  agent_id,
            "host_id":   HOST_ID,
            "hostname":  HOSTNAME,
            "timestamp": datetime.now(timezone.utc).isoformat(),
            "metrics":   metrics or {},
            "trace_id":  trace_id,
        }
        payload = json.dumps(heartbeat_data, sort_keys=True).encode()
        signature = self._sign_payload(payload)

        headers = self._auth_headers()
        if signature:
            headers["X-Agent-Signature"] = signature

        url = f"{self.base_url}/api/agents/{agent_id}/heartbeat"
        try:
            status, _ = _http_post(url, payload, headers)
            if 200 <= status < 300:
                log.debug("Heartbeat OK (signed) — agent_id=%s", agent_id)
                return True
            log.debug("Heartbeat HTTP %d — agent_id=%s", status, agent_id)
        except (URLError, OSError) as exc:
            log.debug("Heartbeat failed (network): %s", exc)
        return False

    def post_behavioral_snapshot(
        self,
        agent_id: str,
        snapshot: dict[str, Any],
    ) -> bool:
        """
        POST /api/agents/{agentId}/behavioral-snapshot
        Shadow-only behavioral visibility — no enforcement.
        Returns True on success.
        """
        payload = json.dumps(snapshot, separators=(",", ":")).encode()
        signature = self._sign_payload(payload)
        headers = self._auth_headers()
        if signature:
            headers["X-Agent-Signature"] = signature
        url = f"{self.base_url}/api/agents/{agent_id}/behavioral-snapshot"
        try:
            status, body = _http_post(url, payload, headers)
            if 200 <= status < 300:
                data = json.loads(body)
                log.debug("Behavioral snapshot stored — snapshot_id=%s", data.get("snapshot_id"))
                return True
            log.debug("behavioral-snapshot HTTP %d — agent_id=%s", status, agent_id)
        except (URLError, OSError) as exc:
            log.debug("behavioral-snapshot failed (network): %s", exc)
        except json.JSONDecodeError:
            pass
        return False

    def poll_commands(self, agent_id: str) -> list[dict[str, Any]]:
        """
        GET /api/agents/{agentId}/commands
        Returns list of dispatched commands pending acknowledgement.
        Safe — read-only, no side effects on the server.
        """
        url = f"{self.base_url}/api/agents/{agent_id}/commands"
        try:
            status, body = _http_get(url, self._auth_headers())
            if 200 <= status < 300:
                data = json.loads(body)
                return data.get("commands", [])
            log.debug("poll_commands HTTP %d — agent_id=%s", status, agent_id)
        except (URLError, OSError) as exc:
            log.debug("poll_commands failed (network): %s", exc)
        except json.JSONDecodeError as exc:
            log.debug("poll_commands response not JSON: %s", exc)
        return []

    def ack_command(self, agent_id: str, command_id: str) -> bool:
        """
        POST /api/agents/{agentId}/commands/{commandId}/ack
        Signed with X-Agent-Signature. Returns True on success.
        """
        payload_data = {
            "agent_id":   agent_id,
            "command_id": command_id,
            "timestamp":  datetime.now(timezone.utc).isoformat(),
        }
        payload = json.dumps(payload_data, sort_keys=True).encode()
        signature = self._sign_payload(payload)
        headers = self._auth_headers()
        if signature:
            headers["X-Agent-Signature"] = signature
        url = f"{self.base_url}/api/agents/{agent_id}/commands/{command_id}/ack"
        try:
            status, _ = _http_post(url, payload, headers)
            return 200 <= status < 300
        except (URLError, OSError):
            return False

    def post_command_result(
        self,
        agent_id: str,
        command_id: str,
        result_status: str,
        result: dict[str, Any] | None = None,
        error: str | None = None,
    ) -> bool:
        """
        POST /api/agents/{agentId}/commands/{commandId}/result
        Signed with X-Agent-Signature. Returns True on success.
        """
        payload_data: dict[str, Any] = {
            "agent_id":   agent_id,
            "command_id": command_id,
            "status":     result_status,
            "timestamp":  datetime.now(timezone.utc).isoformat(),
        }
        if result is not None:
            payload_data["result"] = result
        if error is not None:
            payload_data["error"] = error
        payload = json.dumps(payload_data, sort_keys=True).encode()
        signature = self._sign_payload(payload)
        headers = self._auth_headers()
        if signature:
            headers["X-Agent-Signature"] = signature
        url = f"{self.base_url}/api/agents/{agent_id}/commands/{command_id}/result"
        try:
            status, _ = _http_post(url, payload, headers)
            return 200 <= status < 300
        except (URLError, OSError):
            return False


# ---------------------------------------------------------------------------
# Command executor — safe allowlist only, rejects destructive types
# ---------------------------------------------------------------------------

# Phase 1: only these types are executed on the agent side.
ALLOWED_COMMAND_TYPES: frozenset[str] = frozenset([
    "noop",
    "collect_diagnostics",
    "refresh_config",
    "upload_health_snapshot",
])

# ---------------------------------------------------------------------------
# Phase 2: Host isolation simulation — config-gated, disabled by default.
# Writes a local marker file ONLY. No actual network changes. No kernel hooks.
# No persistence modifications. No stealth behavior.
# Must be explicitly enabled via config: allow_host_isolation_simulation = true
# ---------------------------------------------------------------------------

HOST_ISOLATION_SIMULATION_MARKER: str = ".xdr_isolation_simulation"


def simulate_host_isolation(
    cfg: dict[str, Any],
    marker_dir: str = ".",
) -> dict[str, Any]:
    """
    Simulation-only host isolation — writes a local marker file.
    Does NOT change network rules, firewall, or kernel state.
    Requires allow_host_isolation_simulation=true in config (default: false).
    Lab-scope only. Reversible via rollback_host_isolation().
    """
    if not cfg.get("allow_host_isolation_simulation", False):
        return {"status": "disabled", "message": "Host isolation simulation is disabled by config (allow_host_isolation_simulation=false)"}

    marker_path = Path(marker_dir) / HOST_ISOLATION_SIMULATION_MARKER
    try:
        marker_path.write_text(
            json.dumps({
                "simulation": True,
                "isolated_at": time.time(),
                "note": "Simulation marker only — no actual network isolation applied.",
                "reversible": True,
            }),
            encoding="utf-8",
        )
        return {
            "status": "simulated",
            "marker_path": str(marker_path),
            "simulation": True,
            "reversible": True,
            "note": "Simulation marker written. No network changes made.",
        }
    except OSError as exc:
        return {"status": "error", "message": str(exc)}


def rollback_host_isolation(
    cfg: dict[str, Any],
    marker_dir: str = ".",
) -> dict[str, Any]:
    """
    Remove the simulation isolation marker. No other side effects.
    Requires allow_host_isolation_simulation=true in config.
    """
    if not cfg.get("allow_host_isolation_simulation", False):
        return {"status": "disabled", "message": "Host isolation simulation is disabled by config"}

    marker_path = Path(marker_dir) / HOST_ISOLATION_SIMULATION_MARKER
    if not marker_path.exists():
        return {"status": "not_found", "message": "No isolation marker found — already rolled back or never simulated"}
    try:
        marker_path.unlink()
        return {"status": "rolled_back", "simulation": True, "note": "Simulation marker removed."}
    except OSError as exc:
        return {"status": "error", "message": str(exc)}

# Explicitly listed so tests can assert these are never executed.
FORBIDDEN_COMMAND_TYPES: frozenset[str] = frozenset([
    "isolate_host",
    "kill_process",
    "quarantine_file",
    "delete_file",
    "remove_persistence",
    "block_ip",
    "disable_service",
    "wipe_disk",
])


def _append_command_audit(audit_path: str, entry: dict[str, Any]) -> None:
    """Append a JSON line to the local command audit log (append-only)."""
    try:
        p = Path(audit_path)
        p.parent.mkdir(parents=True, exist_ok=True)
        with p.open("a", encoding="utf-8") as fh:
            fh.write(json.dumps(entry, separators=(",", ":")) + "\n")
    except OSError as exc:
        log.warning("Command audit write failed: %s", exc)


def execute_command(
    command: dict[str, Any],
    cfg: dict[str, Any],
    audit_path: str = "/var/lib/xdr-agent/command_audit.jsonl",
) -> tuple[str, dict[str, Any] | None, str | None]:
    """
    Execute a safe command from the SOC response queue.
    Returns (result_status, result_dict, error_str).
    result_status is 'completed' or 'failed'.

    Rejects unsupported and destructive command types — never executes them.
    All invocations (including rejections) are appended to the local audit log.
    """
    command_id   = command.get("command_id", "unknown")
    command_type = command.get("command_type", "")

    audit_entry: dict[str, Any] = {
        "ts":          datetime.now(timezone.utc).isoformat(),
        "command_id":  command_id,
        "command_type": command_type,
        "host":        HOSTNAME,
        "host_id":     HOST_ID,
    }

    # Hard reject: destructive types are never executed regardless of allowlist state
    if command_type in FORBIDDEN_COMMAND_TYPES:
        msg = f"REJECTED: forbidden command type '{command_type}' — Phase 1 prohibits destructive commands"
        log.error("Command %s rejected: %s", command_id, msg)
        audit_entry.update({"result": "rejected_forbidden", "error": msg})
        _append_command_audit(audit_path, audit_entry)
        return "failed", None, msg

    # Reject unknown/unsupported types
    if command_type not in ALLOWED_COMMAND_TYPES:
        msg = f"REJECTED: unsupported command type '{command_type}'"
        log.warning("Command %s rejected: %s", command_id, msg)
        audit_entry.update({"result": "rejected_unsupported", "error": msg})
        _append_command_audit(audit_path, audit_entry)
        return "failed", None, msg

    # Execute safe commands
    result: dict[str, Any] | None = None
    error: str | None = None
    status = "completed"

    try:
        if command_type == "noop":
            result = {"message": "noop — no action taken"}

        elif command_type == "collect_diagnostics":
            # Safe: collect non-sensitive host metadata only
            result = {
                "hostname":        HOSTNAME,
                "host_id":         HOST_ID,
                "platform":        platform.system(),
                "platform_version": platform.version(),
                "python_version":  platform.python_version(),
                "timestamp":       datetime.now(timezone.utc).isoformat(),
            }
            # Explicitly NOT included: credentials, keys, env vars, process args

        elif command_type == "refresh_config":
            # Safe: signals agent to reload config on next cycle
            result = {"message": "config refresh scheduled for next cycle"}

        elif command_type == "upload_health_snapshot":
            result = {
                "hostname":  HOSTNAME,
                "platform":  platform.system(),
                "timestamp": datetime.now(timezone.utc).isoformat(),
            }

    except Exception as exc:
        status = "failed"
        error  = str(exc)
        log.error("Command %s (%s) raised: %s", command_id, command_type, exc)

    audit_entry.update({
        "result": status,
        "result_data": result,
        "error": error,
    })
    _append_command_audit(audit_path, audit_entry)

    return status, result, error


def process_commands(
    soc: "SOCClient",
    agent_id: str,
    cfg: dict[str, Any],
    audit_path: str = "/var/lib/xdr-agent/command_audit.jsonl",
) -> None:
    """
    Poll command queue, ack, execute, post result.
    Called once per heartbeat cycle.
    """
    commands = soc.poll_commands(agent_id)
    if not commands:
        return

    for cmd in commands:
        command_id   = cmd.get("command_id", "")
        command_type = cmd.get("command_type", "")
        log.info("Received command %s type=%s", command_id, command_type)

        # Ack first so the server knows we received it
        soc.ack_command(agent_id, command_id)

        result_status, result, error = execute_command(cmd, cfg, audit_path)

        soc.post_command_result(
            agent_id, command_id, result_status,
            result=result, error=error,
        )
        log.info("Command %s result=%s", command_id, result_status)


# ---------------------------------------------------------------------------
# Collector state (process / network / file / task / service deltas)
# ---------------------------------------------------------------------------

class CollectorState:
    """Tracks last-seen sets for delta-based collectors."""

    def __init__(self) -> None:
        self.known_pids: set[str] = set()           # "pid:ppid:comm" keys
        self.known_conns: set[str] = set()          # "proto:laddr:raddr" keys
        self.known_cron_hashes: set[str] = set()    # sha256 of crontab lines
        self.known_service_hashes: set[str] = set() # sha256 of service file lists
        self.file_mtimes: dict[str, float] = {}     # path -> mtime
        self.dns_log_pos: dict[str, int] = {}       # log file path -> byte offset
        self.dns_fixture_pos: int = 0               # byte offset in dns_fixture_path
        # Behavioral visibility state (Phase 1)
        self.process_first_seen: dict[str, float] = {}  # process_key → monotonic time


# ---------------------------------------------------------------------------
# /proc helpers — Linux-specific, no subprocess
# ---------------------------------------------------------------------------

def _uid_to_username(uid: int) -> str:
    """Resolve UID to username using /etc/passwd — no subprocess."""
    try:
        passwd = Path("/etc/passwd").read_text(errors="replace")
        for line in passwd.splitlines():
            parts = line.split(":")
            if len(parts) >= 3 and parts[2] == str(uid):
                return parts[0]
    except OSError:
        pass
    return str(uid)


def _hex_addr_to_ip_port(hex_addr: str, ipv6: bool = False) -> tuple[str | None, int | None]:
    """
    Parse 'HEXIP:HEXPORT' from /proc/net/tcp[6].
    IPv4: 4-byte little-endian hex (e.g. '0101A8C0' = 192.168.1.1).
    IPv6: 16-byte stored as 4 little-endian 32-bit words.
    """
    try:
        hex_ip, hex_port = hex_addr.split(":")
        port = int(hex_port, 16)
        if ipv6:
            raw = bytes.fromhex(hex_ip)
            if len(raw) != 16:
                return None, None
            # Each 4-byte word is stored little-endian; reverse each word
            words = struct.unpack("<4I", raw)
            ip_bytes = struct.pack(">4I", *words)
            ip = str(ipaddress.IPv6Address(ip_bytes))
        else:
            raw = bytes.fromhex(hex_ip)
            if len(raw) != 4:
                return None, None
            # IPv4 stored little-endian
            ip = socket.inet_ntoa(bytes(reversed(raw)))
        return ip, port
    except Exception:
        return None, None


def _read_proc_net_tcp(path: str, ipv6: bool = False) -> list[dict[str, Any]]:
    """
    Parse /proc/net/tcp or /proc/net/tcp6 and return ESTABLISHED outbound
    connections as dicts with keys: local_ip, local_port, remote_ip, remote_port, uid, proto.
    Only state 01 (ESTABLISHED) entries are included.
    """
    connections: list[dict[str, Any]] = []
    try:
        content = Path(path).read_text(errors="replace")
    except OSError:
        return []

    for line in content.splitlines()[1:]:  # skip header row
        parts = line.split()
        if len(parts) < 10:
            continue
        local_hex = parts[1]
        rem_hex = parts[2]
        state_hex = parts[3]
        uid = parts[7]

        if state_hex != "01":  # 01 = TCP_ESTABLISHED
            continue

        local_ip, local_port = _hex_addr_to_ip_port(local_hex, ipv6)
        rem_ip, rem_port = _hex_addr_to_ip_port(rem_hex, ipv6)

        if not rem_ip:
            continue
        # Skip loopback and unroutable destinations
        if rem_ip in ("0.0.0.0", "::", "127.0.0.1", "::1"):
            continue

        connections.append({
            "local_ip": local_ip or "",
            "local_port": local_port,
            "remote_ip": rem_ip,
            "remote_port": rem_port,
            "uid": uid,
            "proto": "tcp",
        })

    return connections


# ---------------------------------------------------------------------------
# Process collector — /proc on Linux, subprocess on Windows
# ---------------------------------------------------------------------------

def _collect_processes_proc(
    state: CollectorState,
    agent_id: str,
    trace_id: str,
    proc_root: Path | None = None,
) -> list[dict[str, Any]]:
    """
    Enumerate running processes by reading /proc/[pid]/status and
    /proc/[pid]/cmdline directly — no subprocess, no ps.
    """
    root = proc_root or Path("/proc")
    events: list[dict[str, Any]] = []
    current_pids: set[str] = set()

    try:
        pid_dirs = [d for d in root.iterdir() if d.name.isdigit()]
    except (PermissionError, OSError):
        return []

    for pid_dir in pid_dirs:
        pid_str = pid_dir.name
        try:
            status_text = (pid_dir / "status").read_text(errors="replace")
        except OSError:
            continue

        fields: dict[str, str] = {}
        for line in status_text.splitlines():
            if ":" in line:
                k, _, v = line.partition(":")
                fields[k.strip()] = v.strip()

        name = fields.get("Name", "")
        ppid_str = fields.get("PPid", "0")
        uid_field = fields.get("Uid", "")
        uid_str = uid_field.split()[0] if uid_field else "0"

        user = _uid_to_username(int(uid_str)) if uid_str.isdigit() else uid_str

        try:
            cmdline_raw = (pid_dir / "cmdline").read_bytes()
            cmdline = cmdline_raw.replace(b"\x00", b" ").decode(errors="replace").strip()
        except OSError:
            cmdline = name

        process_path: str | None = None
        try:
            process_path = os.readlink(str(pid_dir / "exe"))
        except OSError:
            pass

        key = f"{pid_str}:{ppid_str}:{name}"
        current_pids.add(key)

        if key not in state.known_pids:
            try:
                pid = int(pid_str)
                ppid = int(ppid_str)
            except ValueError:
                continue

            ev = base_event(
                "process_start", agent_id, trace_id,
                process_name=name,
                process_path=process_path,
                parent_process=ppid_str,
                user=user if user else None,
            )
            ev["pid"] = pid
            ev["ppid"] = ppid
            ev["command_line"] = cmdline[:4096] if cmdline else None
            ev["event_id"] = make_event_id(
                HOSTNAME, "process_start", pid_str, ppid_str, name,
                datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M"),
            )
            events.append(ev)

    state.known_pids = current_pids
    return events


def _collect_processes_windows(
    state: CollectorState,
    agent_id: str,
    trace_id: str,
) -> list[dict[str, Any]]:
    """Windows fallback: parse 'ps -eo pid,ppid,user,comm,args'."""
    events: list[dict[str, Any]] = []
    try:
        result = subprocess.run(
            ["ps", "-eo", "pid,ppid,user,comm,args", "--no-headers"],
            capture_output=True, text=True, timeout=10,
        )
        output = result.stdout
    except (subprocess.TimeoutExpired, FileNotFoundError, OSError):
        return []

    current_pids: set[str] = set()
    for line in output.splitlines():
        parts = line.split(None, 4)
        if len(parts) < 4:
            continue
        pid_str, ppid_str, user, comm = parts[0], parts[1], parts[2], parts[3]
        args = parts[4] if len(parts) > 4 else comm
        key = f"{pid_str}:{ppid_str}:{comm}"
        current_pids.add(key)
        if key not in state.known_pids:
            try:
                pid = int(pid_str)
                ppid = int(ppid_str)
            except ValueError:
                continue
            ev = base_event(
                "process_start", agent_id, trace_id,
                process_name=comm, parent_process=ppid_str, user=user or None,
            )
            ev["pid"] = pid
            ev["ppid"] = ppid
            ev["command_line"] = args[:4096] if args else None
            ev["event_id"] = make_event_id(
                HOSTNAME, "process_start", pid_str, ppid_str, comm,
                datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M"),
            )
            events.append(ev)
    state.known_pids = current_pids
    return events


def collect_processes(
    state: CollectorState,
    agent_id: str,
    trace_id: str,
    cfg: dict[str, Any],
    *,
    proc_root: Path | None = None,
) -> list[dict[str, Any]]:
    """Dispatch to the platform-appropriate process collector."""
    if not cfg["telemetry"].get("process", True):
        return []
    if IS_LINUX or proc_root is not None:
        return _collect_processes_proc(state, agent_id, trace_id, proc_root)
    return _collect_processes_windows(state, agent_id, trace_id)


# ---------------------------------------------------------------------------
# Network collector — /proc/net/tcp on Linux, subprocess on Windows
# ---------------------------------------------------------------------------

def _collect_network_proc(
    state: CollectorState,
    agent_id: str,
    trace_id: str,
    *,
    proc_net_tcp: str = "/proc/net/tcp",
    proc_net_tcp6: str = "/proc/net/tcp6",
) -> list[dict[str, Any]]:
    """
    Parse /proc/net/tcp and /proc/net/tcp6 for ESTABLISHED outbound connections.
    No subprocess, no ss, no netstat.
    """
    all_conns = (
        _read_proc_net_tcp(proc_net_tcp, ipv6=False)
        + _read_proc_net_tcp(proc_net_tcp6, ipv6=True)
    )

    events: list[dict[str, Any]] = []
    current_conns: set[str] = set()

    for conn in all_conns:
        local_ip = conn["local_ip"]
        local_port = conn.get("local_port")
        rem_ip = conn["remote_ip"]
        rem_port = conn.get("remote_port")
        proto = conn.get("proto", "tcp")

        key = f"{proto}:{local_ip}:{local_port}:{rem_ip}:{rem_port}"
        current_conns.add(key)

        if key not in state.known_conns:
            ev = base_event("network_connection", agent_id, trace_id, user=None)
            ev["source_ip"] = local_ip
            ev["source_port"] = local_port
            ev["destination_ip"] = rem_ip
            ev["destination_port"] = rem_port
            ev["protocol"] = proto
            ev["direction"] = "outbound"
            ev["action"] = "monitored"
            ev["pid"] = None
            ev["event_id"] = make_event_id(
                HOSTNAME, "network_connection",
                local_ip, str(local_port), rem_ip, str(rem_port), proto,
            )
            events.append(ev)

    state.known_conns = current_conns
    return events


def _collect_network_windows(
    state: CollectorState,
    agent_id: str,
    trace_id: str,
) -> list[dict[str, Any]]:
    """Windows fallback: parse ss -tunp / netstat -tunp output."""
    try:
        result = subprocess.run(["ss", "-tunp"], capture_output=True, text=True, timeout=10)
        output = result.stdout
        tool = "ss"
        if not output.strip():
            result = subprocess.run(["netstat", "-tunp"], capture_output=True, text=True, timeout=10)
            output = result.stdout
            tool = "netstat"
    except (subprocess.TimeoutExpired, FileNotFoundError, OSError):
        return []

    events: list[dict[str, Any]] = []
    current_conns: set[str] = set()

    for line in output.splitlines():
        if line.startswith(("Netid", "Proto", "State", "Active")):
            continue
        parts = line.split()
        if len(parts) < 5:
            continue
        try:
            if tool == "ss":
                proto = parts[0].lower()
                local = parts[4] if len(parts) > 4 else ""
                remote = parts[5] if len(parts) > 5 else ""
            else:
                proto = parts[0].lower().replace("6", "")
                local = parts[3]
                remote = parts[4]

            if remote in ("*:*", "0.0.0.0:*", ":::*", ""):
                continue

            def _split_addr(addr: str) -> tuple[str, str]:
                if "]:" in addr:
                    i = addr.rindex("]")
                    return addr[1:i], addr[i + 2:]
                if ":" in addr:
                    ip, _, port = addr.rpartition(":")
                    return ip, port
                return addr, ""

            src_ip, src_port_str = _split_addr(local)
            dst_ip, dst_port_str = _split_addr(remote)

            if not dst_ip or dst_ip in ("*", "0.0.0.0", "::"):
                continue
            try:
                dst_port = int(dst_port_str)
            except ValueError:
                continue
            try:
                src_port: int | None = int(src_port_str)
            except ValueError:
                src_port = None

            if proto not in ("tcp", "udp", "icmp"):
                proto = "other"

            key = f"{proto}:{src_ip}:{src_port_str}:{dst_ip}:{dst_port_str}"
            current_conns.add(key)

            if key not in state.known_conns:
                ev = base_event("network_connection", agent_id, trace_id, user=None)
                ev["source_ip"] = src_ip
                ev["source_port"] = src_port
                ev["destination_ip"] = dst_ip
                ev["destination_port"] = dst_port
                ev["protocol"] = proto
                ev["direction"] = "outbound"
                ev["action"] = "monitored"
                ev["pid"] = None
                ev["event_id"] = make_event_id(
                    HOSTNAME, "network_connection",
                    src_ip, src_port_str, dst_ip, dst_port_str, proto,
                )
                events.append(ev)
        except (IndexError, ValueError):
            continue

    state.known_conns = current_conns
    return events


def collect_network(
    state: CollectorState,
    agent_id: str,
    trace_id: str,
    cfg: dict[str, Any],
    *,
    proc_net_tcp: str | None = None,
    proc_net_tcp6: str | None = None,
) -> list[dict[str, Any]]:
    """Dispatch to the platform-appropriate network collector."""
    if not cfg["telemetry"].get("network", True):
        return []
    if IS_LINUX or proc_net_tcp is not None:
        return _collect_network_proc(
            state, agent_id, trace_id,
            proc_net_tcp=proc_net_tcp or "/proc/net/tcp",
            proc_net_tcp6=proc_net_tcp6 or "/proc/net/tcp6",
        )
    return _collect_network_windows(state, agent_id, trace_id)


# ---------------------------------------------------------------------------
# DNS collector — fixture simulation hook or syslog tailing
# ---------------------------------------------------------------------------

_DNS_LINE_RE = re.compile(
    r'(?:query|DNS|named|dnsmasq|systemd-resolve).*?'
    r'(?:query\[([A-Z]+)\]|type=([A-Z]+))\s+'
    r'([\w.\-]+)',
    re.IGNORECASE,
)
_VALID_QUERY_TYPES = frozenset(
    {"A", "AAAA", "MX", "TXT", "CNAME", "NS", "PTR", "SOA", "SRV", "ANY"}
)


def _collect_dns_fixture(
    fixture_path: str,
    state: CollectorState,
    agent_id: str,
    trace_id: str,
) -> list[dict[str, Any]]:
    """
    Read new lines from a JSONL fixture file since last position.
    Each line: {"domain": "example.com", "query_type": "A"}
    Simulates DNS query events — safe, no packet sniffing.
    """
    p = Path(fixture_path)
    if not p.exists():
        return []

    try:
        current_size = p.stat().st_size
    except OSError:
        return []

    if current_size <= state.dns_fixture_pos:
        return []

    events: list[dict[str, Any]] = []
    try:
        with p.open("rb") as fh:
            fh.seek(state.dns_fixture_pos)
            new_bytes = fh.read(current_size - state.dns_fixture_pos)
            state.dns_fixture_pos = fh.tell()
    except OSError:
        return []

    for raw_line in new_bytes.decode(errors="replace").splitlines():
        raw_line = raw_line.strip()
        if not raw_line:
            continue
        try:
            record = json.loads(raw_line)
        except json.JSONDecodeError:
            continue
        domain = record.get("domain", "").rstrip(".")
        query_type = record.get("query_type", "A").upper()
        if not domain or "." not in domain:
            continue
        ev = base_event("dns_query", agent_id, trace_id, user=None)
        ev["domain"] = domain
        ev["query_type"] = query_type if query_type in _VALID_QUERY_TYPES else None
        ev["event_id"] = make_event_id(
            HOSTNAME, "dns_query", domain, query_type,
            datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M"),
        )
        events.append(ev)

    return events


def _collect_dns_from_logs(
    log_paths: list[str],
    state: CollectorState,
    agent_id: str,
    trace_id: str,
) -> list[dict[str, Any]]:
    """Tail syslog/messages for DNS-related lines since last read offset."""
    events: list[dict[str, Any]] = []
    for log_path_str in log_paths:
        log_path = Path(log_path_str)
        if not log_path.exists():
            continue
        try:
            current_size = log_path.stat().st_size
        except OSError:
            continue

        last_pos = state.dns_log_pos.get(log_path_str, 0)
        if current_size < last_pos:
            last_pos = 0
        if current_size == last_pos:
            continue

        try:
            with log_path.open("rb") as fh:
                fh.seek(last_pos)
                new_bytes = fh.read(min(current_size - last_pos, 256 * 1024))
                state.dns_log_pos[log_path_str] = fh.tell()
        except OSError:
            continue

        for raw_line in new_bytes.decode(errors="replace").splitlines():
            m = _DNS_LINE_RE.search(raw_line)
            if not m:
                continue
            query_type = (m.group(1) or m.group(2) or "A").upper()
            domain = m.group(3).rstrip(".")
            if not domain or "." not in domain:
                continue
            ev = base_event("dns_query", agent_id, trace_id, user=None)
            ev["domain"] = domain
            ev["query_type"] = query_type if query_type in _VALID_QUERY_TYPES else None
            ev["event_id"] = make_event_id(
                HOSTNAME, "dns_query", domain, query_type,
                datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M"),
            )
            events.append(ev)

    return events


def collect_dns(
    state: CollectorState,
    agent_id: str,
    trace_id: str,
    cfg: dict[str, Any],
) -> list[dict[str, Any]]:
    """
    DNS collector: fixture simulation mode (preferred) or syslog tailing fallback.
    Set dns_fixture_path in config for simulation mode.
    """
    if not cfg["telemetry"].get("dns", True):
        return []

    fixture_path = cfg.get("dns_fixture_path")
    if fixture_path:
        return _collect_dns_fixture(fixture_path, state, agent_id, trace_id)

    log_paths = cfg.get("log_paths", ["/var/log/syslog", "/var/log/messages"])
    return _collect_dns_from_logs(log_paths, state, agent_id, trace_id)


# ---------------------------------------------------------------------------
# File write watcher — configured watch_paths only, no full filesystem scan
# ---------------------------------------------------------------------------

def collect_file_writes(
    state: CollectorState,
    agent_id: str,
    trace_id: str,
    cfg: dict[str, Any],
) -> list[dict[str, Any]]:
    """
    Poll watch_paths for files whose mtime has changed since last cycle.
    Uses os.walk + stat — no inotify, no kernel driver.
    Only watches directories explicitly listed in config watch_paths.
    """
    if not cfg["telemetry"].get("file", False):
        return []

    watch_paths = cfg.get("watch_paths", [])
    if not watch_paths:
        return []

    events: list[dict[str, Any]] = []

    for watch_root in watch_paths:
        watch_root_path = Path(watch_root)
        if not watch_root_path.exists():
            continue
        try:
            for dir_path, _dirs, file_names in os.walk(watch_root_path):
                for fname in file_names:
                    full_path = os.path.join(dir_path, fname)
                    try:
                        st = os.stat(full_path)
                        mtime = st.st_mtime
                    except OSError:
                        continue

                    prev_mtime = state.file_mtimes.get(full_path)
                    state.file_mtimes[full_path] = mtime

                    if prev_mtime is None:
                        # First scan — establish baseline without emitting events
                        continue
                    if mtime <= prev_mtime:
                        continue

                    ev = base_event("file_write", agent_id, trace_id, user=None)
                    ev["file_path"] = full_path
                    ev["operation"] = "modify"
                    ev["file_size"] = st.st_size
                    ev["event_id"] = make_event_id(
                        HOSTNAME, "file_write", full_path, str(int(mtime))
                    )
                    events.append(ev)
        except PermissionError:
            pass

    return events


# ---------------------------------------------------------------------------
# Scheduled task collector
# ---------------------------------------------------------------------------

def _hash_lines(lines: list[str]) -> str:
    return hashlib.sha256("\n".join(sorted(lines)).encode()).hexdigest()


def collect_scheduled_tasks(
    state: CollectorState,
    agent_id: str,
    trace_id: str,
    cfg: dict[str, Any],
) -> list[dict[str, Any]]:
    """
    Linux: compare crontab and system cron file hashes.
    Windows: schtasks /query /fo LIST.
    Emits scheduled_task_create events for new/changed entries.
    """
    if not cfg["telemetry"].get("scheduled_tasks", True):
        return []

    events: list[dict[str, Any]] = []

    if IS_WINDOWS:
        try:
            result = subprocess.run(
                ["schtasks", "/query", "/fo", "LIST"],
                capture_output=True, text=True, timeout=10,
            )
            output = result.stdout
        except (subprocess.TimeoutExpired, FileNotFoundError, OSError):
            return []
        task_lines = [
            line.strip() for line in output.splitlines()
            if line.strip().startswith("TaskName:")
        ]
        current_hash = _hash_lines(task_lines)
        if current_hash not in state.known_cron_hashes:
            state.known_cron_hashes = {current_hash}
            for task_line in task_lines:
                task_name = task_line.replace("TaskName:", "").strip()
                ev = base_event("scheduled_task_create", agent_id, trace_id, user=None)
                ev["event_id"] = make_event_id(HOSTNAME, "scheduled_task_create", task_name)
                ev["task_name"] = task_name
                ev["platform"] = "windows"
                events.append(ev)
    else:
        cron_lines: list[str] = []
        system_cron_dirs = [
            "/etc/cron.d", "/etc/cron.daily",
            "/etc/cron.hourly", "/etc/cron.weekly", "/etc/cron.monthly",
        ]
        # Read user crontab by parsing /var/spool/cron — no subprocess
        for spool_dir in ("/var/spool/cron", "/var/spool/cron/crontabs"):
            spool = Path(spool_dir)
            if not spool.is_dir():
                continue
            try:
                for cron_file in spool.iterdir():
                    try:
                        content = cron_file.read_text(errors="replace")
                        cron_lines.extend(
                            line for line in content.splitlines()
                            if line.strip() and not line.startswith("#")
                        )
                    except OSError:
                        pass
            except PermissionError:
                pass

        for cron_dir in system_cron_dirs:
            cron_dir_path = Path(cron_dir)
            if not cron_dir_path.is_dir():
                continue
            try:
                for entry in sorted(cron_dir_path.iterdir()):
                    try:
                        content = entry.read_text(errors="replace")
                        cron_lines.extend(
                            line for line in content.splitlines()
                            if line.strip() and not line.startswith("#")
                        )
                    except OSError:
                        pass
            except PermissionError:
                pass

        current_hash = _hash_lines(cron_lines)
        if current_hash not in state.known_cron_hashes:
            if state.known_cron_hashes:
                ev = base_event("scheduled_task_create", agent_id, trace_id, user=None)
                ev["event_id"] = make_event_id(HOSTNAME, "scheduled_task_create", current_hash)
                ev["cron_hash"] = current_hash
                ev["entry_count"] = len(cron_lines)
                ev["platform"] = "linux"
                events.append(ev)
            state.known_cron_hashes.add(current_hash)

    return events


# ---------------------------------------------------------------------------
# Service collector
# ---------------------------------------------------------------------------

def collect_services(
    state: CollectorState,
    agent_id: str,
    trace_id: str,
    cfg: dict[str, Any],
) -> list[dict[str, Any]]:
    """
    Linux: scan /etc/systemd/system/*.service for new files — no subprocess.
    Windows: sc query (enumerate service names).
    Emits service_install events for newly observed services.
    """
    if not cfg["telemetry"].get("services", True):
        return []

    events: list[dict[str, Any]] = []

    if IS_WINDOWS:
        try:
            result = subprocess.run(
                ["sc", "query", "type=", "all"],
                capture_output=True, text=True, timeout=10,
            )
            output = result.stdout
        except (subprocess.TimeoutExpired, FileNotFoundError, OSError):
            return []
        service_names = [
            line.split(":", 1)[1].strip()
            for line in output.splitlines()
            if "SERVICE_NAME" in line
        ]
        current_hash = _hash_lines(service_names)
        if current_hash not in state.known_service_hashes:
            if state.known_service_hashes:
                for svc_name in service_names:
                    svc_hash = hashlib.sha256(svc_name.encode()).hexdigest()
                    if svc_hash not in state.known_service_hashes:
                        ev = base_event("service_install", agent_id, trace_id, user=None)
                        ev["event_id"] = make_event_id(HOSTNAME, "service_install", svc_name)
                        ev["service_name"] = svc_name
                        ev["platform"] = "windows"
                        events.append(ev)
            state.known_service_hashes = {current_hash}
    else:
        systemd_dir = Path("/etc/systemd/system")
        if systemd_dir.is_dir():
            try:
                service_files = sorted(str(p) for p in systemd_dir.glob("*.service"))
            except PermissionError:
                service_files = []

            current_hash = _hash_lines(service_files)
            if current_hash not in state.known_service_hashes:
                if state.known_service_hashes:
                    for sf in service_files:
                        sf_hash = hashlib.sha256(sf.encode()).hexdigest()
                        if sf_hash not in state.known_service_hashes:
                            service_name = Path(sf).stem
                            ev = base_event("service_install", agent_id, trace_id, user=None)
                            ev["event_id"] = make_event_id(
                                HOSTNAME, "service_install", service_name
                            )
                            ev["service_name"] = service_name
                            ev["service_path"] = sf
                            ev["platform"] = "linux"
                            events.append(ev)
                state.known_service_hashes = {
                    hashlib.sha256(sf.encode()).hexdigest()
                    for sf in service_files
                }
                state.known_service_hashes.add(current_hash)

    return events


# ---------------------------------------------------------------------------
# Heartbeat telemetry event
# ---------------------------------------------------------------------------

def collect_heartbeat_event(
    agent_id: str,
    trace_id: str,
    buffer_size: int,
) -> dict[str, Any]:
    """Build a heartbeat telemetry event (distinct from the SOC API heartbeat call)."""
    ev = base_event("heartbeat", agent_id, trace_id, user=None)
    ev["event_id"] = make_event_id(
        HOSTNAME, "heartbeat",
        datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M"),
    )
    ev["buffered_events"] = buffer_size
    ev["platform"] = platform.system()
    ev["python_version"] = platform.python_version()
    return ev


# ---------------------------------------------------------------------------
# Behavioral visibility helpers (Phase 1)
# Shadow-only — no active containment, no process killing, no enforcement.
# ---------------------------------------------------------------------------

def _get_session_id(pid_dir: "Path") -> str | None:
    """Read session ID from /proc/[pid]/sessionid (Linux 4.14+). Returns None if unavailable."""
    try:
        return (pid_dir / "sessionid").read_text().strip() or None
    except OSError:
        return None


def build_process_inventory(
    state: "CollectorState",
    agent_id: str,
    cfg: dict[str, Any],
    proc_root: "Path | None" = None,
) -> list[dict[str, Any]]:
    """
    Build a full process inventory with behavioral ancestry metadata.
    Returns ALL current processes (not just new ones) with enriched fields.
    Shadow-only visibility — read-only /proc access.
    """
    # Allow running with an explicit proc_root (e.g. in tests) even on non-Linux
    if not IS_LINUX and proc_root is None:
        return []

    root = proc_root or Path("/proc")
    now_ts = datetime.now(timezone.utc).isoformat()
    now_mono = time.monotonic()

    # First pass: build pid → name map for parent resolution
    pid_to_name: dict[str, str] = {}
    try:
        for pid_dir in root.iterdir():
            if not pid_dir.name.isdigit():
                continue
            try:
                status_text = (pid_dir / "status").read_text(errors="replace")
                for line in status_text.splitlines():
                    if line.startswith("Name:"):
                        pid_to_name[pid_dir.name] = line.split(":", 1)[1].strip()
                        break
            except OSError:
                pass
    except (PermissionError, OSError):
        return []

    processes: list[dict[str, Any]] = []

    try:
        pid_dirs = [d for d in root.iterdir() if d.name.isdigit()]
    except (PermissionError, OSError):
        return []

    for pid_dir in pid_dirs:
        pid_str = pid_dir.name
        try:
            status_text = (pid_dir / "status").read_text(errors="replace")
        except OSError:
            continue

        fields: dict[str, str] = {}
        for line in status_text.splitlines():
            if ":" in line:
                k, _, v = line.partition(":")
                fields[k.strip()] = v.strip()

        name    = fields.get("Name", "")
        ppid_str= fields.get("PPid", "0")
        uid_field = fields.get("Uid", "")
        uid_str = uid_field.split()[0] if uid_field else "0"
        user    = _uid_to_username(int(uid_str)) if uid_str.isdigit() else uid_str

        try:
            cmdline_raw = (pid_dir / "cmdline").read_bytes()
            command_line = cmdline_raw.replace(b"\x00", b" ").decode(errors="replace").strip()[:4096]
        except OSError:
            command_line = name

        executable_path: str | None = None
        try:
            executable_path = os.readlink(str(pid_dir / "exe"))
        except OSError:
            pass

        session_id = _get_session_id(pid_dir)
        parent_process_name = pid_to_name.get(ppid_str, "")
        process_key = f"{pid_str}:{ppid_str}:{name}"

        # Track first-seen for long-lived detection
        if process_key not in state.process_first_seen:
            state.process_first_seen[process_key] = now_mono

        first_mono = state.process_first_seen[process_key]
        duration_seconds = int(now_mono - first_mono)
        first_seen_at = datetime.fromtimestamp(
            time.time() - (now_mono - first_mono), tz=timezone.utc
        ).isoformat()

        is_shell = name.lower() in SHELL_PROCESS_NAMES
        is_long_lived = is_shell and duration_seconds >= LONG_LIVED_THRESHOLD_SECONDS
        is_suspicious = (
            is_shell and
            parent_process_name.lower() in WEB_SERVER_PROCESS_NAMES
        )

        try:
            pid = int(pid_str)
            ppid = int(ppid_str)
        except ValueError:
            continue

        processes.append({
            "pid":                pid,
            "ppid":               ppid,
            "process_name":       name,
            "parent_process_name":parent_process_name,
            "executable_path":    executable_path,
            "command_line":       command_line,
            "user":               user or None,
            "session_id":         session_id,
            "first_seen_at":      first_seen_at,
            "last_seen_at":       now_ts,
            "duration_seconds":   duration_seconds,
            "is_shell":           is_shell,
            "is_long_lived":      is_long_lived,
            "is_suspicious":      is_suspicious,
        })

    # Prune process_first_seen entries for PIDs no longer in process table
    current_keys = {p["process_name"] for p in processes}
    stale_keys = [k for k in state.process_first_seen if k.split(":")[-1] not in current_keys]
    for k in stale_keys[:500]:  # cap cleanup to avoid O(n) on large tables
        del state.process_first_seen[k]

    return processes


def collect_persistence_items(cfg: dict[str, Any]) -> list[dict[str, Any]]:
    """
    Collect persistence inventory: systemd services, cron jobs, startup scripts.
    Read-only. Never modifies, disables, or deletes any persistence mechanism.
    """
    # Persistence inventory only makes sense on Linux (systemd, cron paths)
    if not IS_LINUX:
        return []

    items: list[dict[str, Any]] = []

    # Systemd service files
    for svc_dir in ["/etc/systemd/system", "/lib/systemd/system", "/usr/lib/systemd/system"]:
        try:
            for f in Path(svc_dir).glob("*.service"):
                items.append({
                    "item_type": "systemd_service",
                    "item_key":  f.name,
                    "item_name": f.stem,
                    "item_path": str(f),
                })
        except (PermissionError, OSError):
            pass

    # Cron job files
    for cron_dir in ["/var/spool/cron/crontabs", "/etc/cron.d"]:
        try:
            for f in Path(cron_dir).iterdir():
                if f.is_file():
                    items.append({
                        "item_type": "cron_job",
                        "item_key":  f"cron:{cron_dir}/{f.name}",
                        "item_name": f.name,
                        "item_path": str(f),
                    })
        except (PermissionError, OSError):
            pass

    # Startup scripts
    for autorun in ["/etc/rc.local", "/etc/rc.d", "/etc/init.d"]:
        try:
            p = Path(autorun)
            if p.is_file():
                items.append({
                    "item_type": "startup_script",
                    "item_key":  f"startup:{autorun}",
                    "item_name": p.name,
                    "item_path": str(p),
                })
            elif p.is_dir():
                for f in p.iterdir():
                    if f.is_file():
                        items.append({
                            "item_type": "startup_script",
                            "item_key":  f"startup:{str(f)}",
                            "item_name": f.name,
                            "item_path": str(f),
                        })
        except (PermissionError, OSError):
            pass

    return items[:500]


def build_network_correlations(
    processes: list[dict[str, Any]],
    proc_root: "Path | None" = None,
) -> list[dict[str, Any]]:
    """
    Build approximate process-to-network correlations by UID matching.
    For each outbound connection from /proc/net/tcp, find processes with matching UID.
    Confidence = 1.0 if single process with matching UID, lower if multiple share UID.
    Read-only — no socket manipulation.
    """
    if not IS_LINUX and proc_root is None:
        return []

    root = proc_root or Path("/proc")
    tcp_path = root / "net" / "tcp"
    tcp6_path = root / "net" / "tcp6"

    # Read all connections
    connections = _read_proc_net_tcp(str(tcp_path), ipv6=False)
    connections += _read_proc_net_tcp(str(tcp6_path), ipv6=True)

    if not connections:
        return []

    # Group processes by UID (username)
    uid_to_processes: dict[str, list[dict[str, Any]]] = {}
    for proc in processes:
        user = proc.get("user", "") or ""
        uid_to_processes.setdefault(user, []).append(proc)

    correlations: list[dict[str, Any]] = []
    for conn in connections:
        uid = conn.get("uid", "")
        user = _uid_to_username(int(uid)) if uid.isdigit() else uid
        matched = uid_to_processes.get(user, [])

        # Filter to shell/network processes for higher-confidence correlation
        shell_procs = [p for p in matched if p.get("is_shell")]
        target_procs = shell_procs if shell_procs else matched

        if not target_procs:
            continue

        confidence = 1.0 / max(len(target_procs), 1)
        for proc in target_procs[:3]:  # cap at 3 candidates per connection
            correlations.append({
                "pid":                    proc.get("pid"),
                "process_name":           proc.get("process_name", ""),
                "remote_ip":              conn["remote_ip"],
                "remote_port":            conn["remote_port"],
                "proto":                  conn["proto"],
                "correlation_confidence": round(min(confidence, 1.0), 2),
            })

    return correlations[:1000]


def collect_behavioral_snapshot(
    state: "CollectorState",
    agent_id: str,
    cfg: dict[str, Any],
    proc_root: "Path | None" = None,
) -> dict[str, Any]:
    """
    Build a complete behavioral snapshot.
    Returns: {agent_id, trace_id, collected_at, processes, persistence_items, network_correlations}
    Shadow-only — no enforcement, no active containment.
    """
    trace_id = str(uuid.uuid4())
    collected_at = datetime.now(timezone.utc).isoformat()

    processes         = build_process_inventory(state, agent_id, cfg, proc_root)
    persistence_items = collect_persistence_items(cfg)
    network_correlations = build_network_correlations(processes, proc_root)

    return {
        "agent_id":            agent_id,
        "trace_id":            trace_id,
        "collected_at":        collected_at,
        "processes":           processes,
        "persistence_items":   persistence_items,
        "network_correlations": network_correlations,
    }


# ---------------------------------------------------------------------------
# Enrollment flow
# ---------------------------------------------------------------------------

def enroll(cfg: dict[str, Any], state: dict[str, Any], state_path: str) -> str:
    """
    Ensure the agent has an agent_id.
    Tries SOC API first; falls back to a locally-generated UUID if SOC is
    unreachable (agent still ships telemetry, SOC heartbeats will fail gracefully).
    """
    if state.get("agent_id"):
        log.info("Already enrolled — agent_id=%s", state["agent_id"])
        return state["agent_id"]

    soc = SOCClient(cfg)
    agent_id = soc.register()
    if not agent_id:
        agent_id = str(uuid.uuid4())
        log.warning(
            "SOC unreachable during enrollment — using local UUID agent_id=%s", agent_id
        )

    state["agent_id"] = agent_id
    state["enrolled_at"] = datetime.now(timezone.utc).isoformat()
    state["host_id"] = HOST_ID
    save_state(state_path, state)
    return agent_id


# ---------------------------------------------------------------------------
# Main collection cycle
# ---------------------------------------------------------------------------

def run_collection_cycle(
    cfg: dict[str, Any],
    col_state: CollectorState,
    agent_id: str,
    gateway: GatewayClient,
    hardened_buffer: HardenedBuffer | None = None,
    quality: QualityMetrics | None = None,
) -> int:
    """Collect all enabled event types, batch them, and ship. Returns event count."""
    trace_id = str(uuid.uuid4())
    max_batch = cfg.get("max_batch_size", 100)

    collectors = [
        collect_processes,
        collect_network,
        collect_dns,
        collect_file_writes,
        collect_scheduled_tasks,
        collect_services,
    ]

    all_events: list[dict[str, Any]] = []
    for collector in collectors:
        try:
            new_events = collector(col_state, agent_id, trace_id, cfg)
            all_events.extend(new_events)
        except Exception as exc:
            log.error(
                "Collector %s raised: %s\n%s",
                collector.__name__, exc, traceback.format_exc(),
            )

    if quality:
        quality.record_cycle()

    if not all_events:
        return 0

    # Route events through HardenedBuffer if available
    if hardened_buffer is not None:
        accepted, dropped = hardened_buffer.push_batch(all_events)
        if quality and dropped > 0:
            quality.record_dropped(dropped)
        events_to_ship = hardened_buffer.drain()
    else:
        events_to_ship = all_events

    if not events_to_ship:
        return 0

    for i in range(0, len(events_to_ship), max_batch):
        batch = events_to_ship[i: i + max_batch]
        gateway.ship(batch)

    if quality:
        quality.record_sent(len(events_to_ship))

    return len(events_to_ship)


# ---------------------------------------------------------------------------
# Main loop
# ---------------------------------------------------------------------------

def main() -> None:
    parser = argparse.ArgumentParser(
        description="XDR Endpoint Telemetry Agent — telemetry-only, no kernel driver"
    )
    default_config = str(Path(__file__).parent / "config.json")
    parser.add_argument(
        "--config", default=default_config,
        help=f"Path to config.json (default: {default_config})",
    )
    parser.add_argument(
        "--once", action="store_true",
        help="Run one collection cycle then exit (useful for testing)",
    )
    parser.add_argument(
        "--debug", action="store_true",
        help="Enable DEBUG log level",
    )
    args = parser.parse_args()

    if args.debug:
        logging.getLogger().setLevel(logging.DEBUG)

    log.info(
        "XDR Endpoint Agent starting — host=%s host_id=%s platform=%s",
        HOSTNAME, HOST_ID, platform.system(),
    )

    cfg = load_config(args.config)
    state_path = cfg["state_path"]
    state = load_state(state_path)

    disk_guard_mb   = cfg.get("disk_pressure_threshold_mb", 100)
    max_buf_size    = cfg.get("max_buffer_size", 5000)
    buffer          = LocalBuffer(cfg["buffer_path"])
    hardened_buffer = HardenedBuffer(max_size=max_buf_size, disk_pressure_threshold_mb=disk_guard_mb)
    quality         = QualityMetrics()
    gateway         = GatewayClient(cfg, buffer)
    soc             = SOCClient(cfg)

    agent_id = enroll(cfg, state, state_path)

    col_state = CollectorState()
    collection_interval    = cfg.get("collection_interval_seconds", 30)
    heartbeat_interval     = cfg.get("heartbeat_interval_seconds", 60)
    behavioral_interval    = cfg.get("behavioral_snapshot_interval_seconds", 300)
    last_heartbeat         = 0.0
    last_collection        = 0.0
    last_behavioral_snapshot = 0.0

    log.info(
        "Agent ready — agent_id=%s collection=%ds heartbeat=%ds max_buffer=%d disk_guard=%dMB",
        agent_id, collection_interval, heartbeat_interval, max_buf_size, disk_guard_mb,
    )

    if args.once:
        count = run_collection_cycle(cfg, col_state, agent_id, gateway, hardened_buffer, quality)
        log.info("Single-cycle run complete — %d events collected", count)
        return

    try:
        while True:
            now = time.monotonic()

            if now - last_collection >= collection_interval:
                last_collection = now
                try:
                    count = run_collection_cycle(
                        cfg, col_state, agent_id, gateway, hardened_buffer, quality
                    )
                    if count:
                        log.info("Collected and shipped %d events", count)
                    else:
                        log.debug("No new events this cycle")
                except Exception as exc:
                    log.error("Collection cycle error: %s\n%s", exc, traceback.format_exc())

            if now - last_heartbeat >= heartbeat_interval:
                last_heartbeat = now
                try:
                    trace_id = str(uuid.uuid4())
                    metrics  = quality.snapshot(buffer_depth=hardened_buffer.depth())
                    hb_event = collect_heartbeat_event(agent_id, trace_id, buffer.size())
                    gateway.ship([hb_event])
                    soc.heartbeat(agent_id, metrics, trace_id=trace_id)
                    log.debug("Heartbeat sent (signed) — agent_id=%s", agent_id)
                except Exception as exc:
                    log.error("Heartbeat error: %s", exc)
                try:
                    process_commands(soc, agent_id, cfg)
                except Exception as exc:
                    log.error("Command processing error: %s", exc)

            if now - last_behavioral_snapshot >= behavioral_interval:
                last_behavioral_snapshot = now
                try:
                    snapshot = collect_behavioral_snapshot(col_state, agent_id, cfg)
                    soc.post_behavioral_snapshot(agent_id, snapshot)
                    log.debug(
                        "Behavioral snapshot sent — processes=%d persistence=%d",
                        len(snapshot["processes"]),
                        len(snapshot["persistence_items"]),
                    )
                except Exception as exc:
                    log.error("Behavioral snapshot error: %s", exc)

            time.sleep(1)

    except KeyboardInterrupt:
        log.info("Received interrupt — agent shutting down cleanly")


if __name__ == "__main__":
    main()
