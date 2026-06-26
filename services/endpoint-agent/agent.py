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
import collections
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
        spool_stats: dict[str, Any] | None = None,
    ) -> bool:
        """
        POST /api/agents/{agentId}/heartbeat — signed with X-Agent-Signature.
        Returns True on success.
        spool_stats: optional local telemetry durability snapshot (queued_events,
                     dropped_events, spool_disk_bytes, spool_capped, disk_pressure, etc.)
        """
        heartbeat_data = {
            "agent_id":   agent_id,
            "host_id":    HOST_ID,
            "hostname":   HOSTNAME,
            "timestamp":  datetime.now(timezone.utc).isoformat(),
            "metrics":    metrics or {},
            "spool_stats": spool_stats or {},  # local telemetry durability snapshot
            "trace_id":   trace_id,
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

# ---------------------------------------------------------------------------
# Streaming Telemetry Engine — Phase 1
# Near-real-time advisory telemetry streaming.
# No kernel EDR. No eBPF. No syscall hooking. No packet sniffing.
# No autonomous containment. Collection sources: /proc, safe filesystem,
# safe process inspection, safe persistence inventory.
# ---------------------------------------------------------------------------

STREAM_QUEUE_MAX: int = 500        # bounded queue — prevents unbounded memory growth
STREAM_BATCH_SIZE: int = 50        # events per flush
STREAM_FLUSH_INTERVAL: float = 5.0 # seconds between periodic flushes
STREAM_SPOOL_MAX_BYTES: int = 10 * 1024 * 1024  # 10 MiB local spool cap


class StreamingState:
    """Per-agent streaming state: sequence counter, bounded queue, spool."""

    def __init__(self, spool_dir: str = ".") -> None:
        self.sequence_id: int = 0
        self.queue: collections.deque[dict[str, Any]] = collections.deque(maxlen=STREAM_QUEUE_MAX)
        self.spool_path: Path = Path(spool_dir) / ".xdr_stream_spool.jsonl"
        self.last_flush_at: float = 0.0
        self.dropped_count: int = 0
        self.batch_count: int = 0

    def next_sequence(self) -> int:
        self.sequence_id += 1
        return self.sequence_id


def emit_stream_event(
    state: StreamingState,
    event_type: str,
    agent_id: str,
    host_id: str,
    trace_id: str,
    **fields: Any,
) -> dict[str, Any]:
    """
    Build a streaming event and enqueue it. Returns the event dict.
    If queue is full (maxlen reached), the oldest entry is auto-dropped and
    dropped_count is incremented to track backpressure.
    No kernel telemetry. No credential capture.
    """
    seq = state.next_sequence()
    event: dict[str, Any] = {
        "event_id":   f"sev-{uuid.uuid4()}",
        "event_type": event_type,
        "sequence_id": seq,
        "agent_id":   agent_id,
        "host_id":    host_id,
        "occurred_at": datetime.now(timezone.utc).isoformat(),
        "trace_id":   trace_id,
    }
    event.update(fields)

    was_full = len(state.queue) == state.queue.maxlen
    state.queue.append(event)
    if was_full:
        state.dropped_count += 1

    return event


def flush_stream_batch(
    state: StreamingState,
    cfg: dict[str, Any],
    agent_id: str,
    gateway_url: str = "",
    *,
    http_timeout: int = 10,
) -> dict[str, Any]:
    """
    Flush up to STREAM_BATCH_SIZE events from the queue to the server.
    Attempts replay from spool first (reconnect-safe delivery).
    Returns flush result metadata.
    No autonomous containment. Single bounded batch.
    """
    # Replay spool first if it exists
    spooled = replay_from_spool(state)

    batch = list(state.queue)[:STREAM_BATCH_SIZE]
    for ev in batch:
        state.queue.remove(ev) if ev in state.queue else None

    all_events = spooled + batch
    if not all_events:
        return {"status": "empty", "sent": 0, "batch_id": None}

    batch_id = f"batch-{uuid.uuid4()}"
    payload = {
        "batch_id": batch_id,
        "agent_id": agent_id,
        "events":   all_events,
    }

    target_url = cfg.get("stream_endpoint", gateway_url)
    if not target_url:
        # No endpoint configured — spool locally
        spool_to_disk(state, all_events)
        return {"status": "spooled", "sent": 0, "spooled": len(all_events), "batch_id": batch_id}

    try:
        body = json.dumps(payload).encode("utf-8")
        req  = urllib_request.Request(
            target_url,
            data=body,
            headers={"Content-Type": "application/json"},
            method="POST",
        )
        with urllib_request.urlopen(req, timeout=http_timeout) as resp:
            state.batch_count += 1
            state.last_flush_at = time.monotonic()
            return {"status": "ok", "sent": len(all_events), "batch_id": batch_id, "http_status": resp.status}
    except (URLError, OSError) as exc:
        log.warning("Stream flush failed: %s — spooling %d events", exc, len(all_events))
        spool_to_disk(state, all_events)
        return {"status": "spooled", "sent": 0, "spooled": len(all_events), "batch_id": batch_id, "error": str(exc)}


def spool_to_disk(state: StreamingState, events: list[dict[str, Any]]) -> bool:
    """
    Write events to local spool file. Bounded by STREAM_SPOOL_MAX_BYTES.
    Returns True on success. No sensitive data capture.
    """
    try:
        if state.spool_path.exists() and state.spool_path.stat().st_size >= STREAM_SPOOL_MAX_BYTES:
            log.warning("Spool cap reached (%d bytes) — oldest events discarded", STREAM_SPOOL_MAX_BYTES)
            state.dropped_count += len(events)
            return False
        with state.spool_path.open("a", encoding="utf-8") as fh:
            for ev in events:
                fh.write(json.dumps(ev) + "\n")
        return True
    except OSError as exc:
        log.error("Spool write failed: %s", exc)
        return False


def replay_from_spool(state: StreamingState) -> list[dict[str, Any]]:
    """
    Read and drain the local spool file for reconnect replay.
    Returns list of events (may be empty). Removes spool after reading.
    Bounded replay: never reads beyond STREAM_SPOOL_MAX_BYTES.
    """
    if not state.spool_path.exists():
        return []
    try:
        lines = state.spool_path.read_text(encoding="utf-8").splitlines()
        events = []
        for line in lines:
            line = line.strip()
            if not line:
                continue
            try:
                events.append(json.loads(line))
            except json.JSONDecodeError:
                pass
        state.spool_path.unlink(missing_ok=True)
        return events
    except OSError as exc:
        log.error("Spool read failed: %s", exc)
        return []


def get_spool_stats(state: StreamingState, quality: "QualityMetrics | None" = None) -> dict[str, Any]:
    """
    Return a serializable spool health snapshot for heartbeat reporting.
    Deterministic — based on current filesystem state and in-memory counters.
    """
    spool_bytes = 0
    spool_capped = False
    oldest_age_seconds = None

    try:
        if state.spool_path.exists():
            stat = state.spool_path.stat()
            spool_bytes = stat.st_size
            spool_capped = spool_bytes >= STREAM_SPOOL_MAX_BYTES
            oldest_age_seconds = int(time.time() - stat.st_mtime)
    except OSError:
        pass

    disk_pressure = False
    try:
        import shutil
        free_mb = shutil.disk_usage("/").free / (1024 * 1024)
        disk_pressure = free_mb < 100.0
    except Exception:
        pass

    return {
        "queued_events":            len(state.queue),
        "dropped_events":           state.dropped_count,
        "spool_disk_bytes":         spool_bytes,
        "spool_capped":             spool_capped,
        "oldest_spool_age_seconds": oldest_age_seconds,
        "disk_pressure":            disk_pressure,
        "retry_count":              quality.retry_count if quality else 0,
        "buffer_depth":             0,
        "events_per_sec":           quality.events_per_sec() if quality else 0.0,
    }


def check_tamper_visibility(
    cfg: dict[str, Any],
    last_heartbeat_timestamp: "str | None" = None,
    last_config_hash: "str | None" = None,
    enabled_collectors: "list[str] | None" = None,
) -> list[dict[str, Any]]:
    """
    Advisory-only tamper visibility check.

    Detects behavioral indicators that suggest potential tampering:
    - heartbeat_gap: no heartbeat sent in expected window
    - config_mismatch: current config hash differs from expected
    - disabled_collector: a required collector is not enabled in config

    Returns a list of advisory tamper indicators.
    NO enforcement action is performed. NO process kill. NO isolation.
    All findings are explainable, evidence-linked, and operator-visible.
    """
    indicators: list[dict[str, Any]] = []
    now_ts = datetime.now(timezone.utc)

    # 1. Heartbeat gap check
    if last_heartbeat_timestamp:
        try:
            last_hb = datetime.fromisoformat(last_heartbeat_timestamp.replace("Z", "+00:00"))
            gap_seconds = (now_ts - last_hb).total_seconds()
            expected_interval = cfg.get("heartbeat_interval_seconds", 60)
            stale_threshold = expected_interval * 10  # 10x interval = stale
            if gap_seconds > stale_threshold:
                indicators.append({
                    "type":               "heartbeat_gap",
                    "gap_seconds":        int(gap_seconds),
                    "expected_interval":  expected_interval,
                    "stale_threshold":    stale_threshold,
                    "last_heartbeat_at":  last_heartbeat_timestamp,
                    "advisory":           True,
                    "autonomous_action":  False,
                })
        except (ValueError, TypeError):
            pass

    # 2. Config hash mismatch (compare current config to last known-good hash)
    if last_config_hash and cfg:
        current_hash = hashlib.sha256(
            json.dumps(cfg, sort_keys=True).encode()
        ).hexdigest()
        if current_hash != last_config_hash:
            indicators.append({
                "type":           "config_mismatch",
                "current_hash":   current_hash,
                "expected_hash":  last_config_hash,
                "advisory":       True,
                "autonomous_action": False,
            })

    # 3. Disabled collector check
    if enabled_collectors:
        telemetry_cfg = cfg.get("telemetry", {})
        for collector in enabled_collectors:
            if not telemetry_cfg.get(collector, True):
                indicators.append({
                    "type":           "disabled_collector",
                    "collector":      collector,
                    "advisory":       True,
                    "autonomous_action": False,
                })

    return indicators


def detect_rapid_stream_shell_chain(
    events: list[dict[str, Any]],
    threshold: int = 3,
) -> list[dict[str, Any]]:
    """
    Advisory analytics: detect rapid shell execution chain from streaming events.
    Returns list of findings. No autonomous response. No containment.
    """
    SHELL_NAMES = frozenset(["bash", "sh", "zsh", "cmd", "powershell", "python", "python3"])
    shell_events = [
        ev for ev in events
        if ev.get("event_type") in ("shell_execution_detected", "process_started")
        and str(ev.get("process_name", "")).lower() in SHELL_NAMES
    ]
    if len(shell_events) < threshold:
        return []
    return [{
        "finding_type":  "rapid_shell_chain",
        "event_count":   len(shell_events),
        "advisory_only": True,
        "no_autonomous": True,
        "detected_at":   datetime.now(timezone.utc).isoformat(),
    }]


def detect_stream_burst_outbound(
    events: list[dict[str, Any]],
    threshold: int = 10,
) -> list[dict[str, Any]]:
    """
    Advisory analytics: detect burst outbound connection activity from streaming events.
    Returns list of findings. No autonomous response. No containment.
    """
    conn_events = [ev for ev in events if ev.get("event_type") == "outbound_connection_opened"]
    if len(conn_events) < threshold:
        return []
    dests = {ev.get("connection_dest") for ev in conn_events if ev.get("connection_dest")}
    return [{
        "finding_type":       "burst_outbound_activity",
        "connection_count":   len(conn_events),
        "unique_destinations": len(dests),
        "advisory_only":      True,
        "no_autonomous":      True,
        "detected_at":        datetime.now(timezone.utc).isoformat(),
    }]


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
# Low-level behavioral telemetry collectors — Phase 1
# No kernel EDR. No eBPF. No syscall hooking. No memory scanning.
# No autonomous enforcement. Read-only observation only.
# ---------------------------------------------------------------------------

# Script/interpreter names that warrant script_execution events
_SCRIPT_INTERPRETERS: frozenset[str] = frozenset([
    "powershell", "powershell.exe", "pwsh", "pwsh.exe",
    "cmd", "cmd.exe", "wscript.exe", "cscript.exe", "mshta.exe",
    "python", "python3", "python2", "python.exe",
    "perl", "ruby", "node", "node.exe", "php", "php.exe",
    "bash", "sh", "zsh", "dash", "ksh",
])

# Processes that indicate privilege escalation attempts on Linux
_PRIV_ESC_NAMES: frozenset[str] = frozenset([
    "sudo", "su", "doas", "pkexec", "newgrp", "runuser",
])

# Windows registry persistence key paths (HKCU/HKLM Run keys)
_WIN_REGISTRY_PERSIST_KEYS: list[str] = [
    r"HKCU\Software\Microsoft\Windows\CurrentVersion\Run",
    r"HKLM\Software\Microsoft\Windows\CurrentVersion\Run",
    r"HKCU\Software\Microsoft\Windows\CurrentVersion\RunOnce",
    r"HKLM\Software\Microsoft\Windows\CurrentVersion\RunOnce",
]


def _detect_encoded_payload(cmdline: str) -> tuple[bool, str | None]:
    """
    Detect base64-encoded payload in a command line.
    Returns (is_encoded, decoded_preview_or_None).
    Advisory — does not block or alter execution.
    """
    import base64 as _b64
    import re as _re

    # PowerShell -EncodedCommand / -enc / -e flags
    ps_enc = _re.search(
        r'(?:-EncodedCommand|-enc|-e)\s+([A-Za-z0-9+/=]{20,})',
        cmdline, _re.IGNORECASE,
    )
    if ps_enc:
        try:
            decoded = _b64.b64decode(ps_enc.group(1) + "==").decode("utf-16-le", errors="replace")
            return True, decoded[:256]
        except Exception:
            return True, None

    # Generic long base64 blob (80+ chars, no spaces)
    generic = _re.search(r'[A-Za-z0-9+/=]{80,}', cmdline)
    if generic:
        try:
            decoded = _b64.b64decode(generic.group(0) + "==").decode("utf-8", errors="replace")
            if any(c.isalpha() for c in decoded):
                return True, decoded[:256]
        except Exception:
            pass

    return False, None


def collect_script_executions(
    state: CollectorState,
    agent_id: str,
    trace_id: str,
    cfg: dict[str, Any] | None = None,
    *,
    proc_root: Path | None = None,
) -> list[dict[str, Any]]:
    """
    Detect script/interpreter executions from running processes.
    Identifies encoded payloads and suspicious interpreter chains.
    Read-only — no process interference.
    """
    if not IS_LINUX and proc_root is None:
        return []

    root = proc_root or Path("/proc")
    events: list[dict[str, Any]] = []

    try:
        pid_dirs = [d for d in root.iterdir() if d.name.isdigit()]
    except (PermissionError, OSError):
        return []

    for pid_dir in pid_dirs:
        try:
            status_text = (pid_dir / "status").read_text(errors="replace")
        except OSError:
            continue

        fields: dict[str, str] = {}
        for line in status_text.splitlines():
            if ":" in line:
                k, _, v = line.partition(":")
                fields[k.strip()] = v.strip()

        name = fields.get("Name", "").lower()
        if name not in _SCRIPT_INTERPRETERS:
            continue

        ppid_str = fields.get("PPid", "0")
        uid_field = fields.get("Uid", "")
        uid_str = uid_field.split()[0] if uid_field else "0"
        user = _uid_to_username(int(uid_str)) if uid_str.isdigit() else uid_str

        try:
            cmdline_raw = (pid_dir / "cmdline").read_bytes()
            cmdline = cmdline_raw.replace(b"\x00", b" ").decode(errors="replace").strip()
        except OSError:
            cmdline = name

        # Determine parent process name
        parent_name: str | None = None
        try:
            parent_status = (root / ppid_str / "status").read_text(errors="replace")
            for line in parent_status.splitlines():
                if line.startswith("Name:"):
                    parent_name = line.split(":", 1)[1].strip()
                    break
        except OSError:
            pass

        is_encoded, decoded_preview = _detect_encoded_payload(cmdline)
        script_source = "encoded" if is_encoded else "inline"

        script_hash: str | None = None
        if is_encoded and decoded_preview:
            import hashlib as _hl
            script_hash = _hl.sha256(decoded_preview.encode()).hexdigest()

        ev = base_event(
            "script_execution", agent_id, trace_id,
            process_name=name,
            parent_process=parent_name,
            user=user,
        )
        ev["event_id"] = make_event_id(
            HOSTNAME, "script_execution", pid_dir.name, name,
            datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M"),
        )
        ev["parent_process_name"] = parent_name
        ev["command_line"] = cmdline[:4096] if cmdline else None
        ev["script_source"] = script_source
        ev["is_encoded"] = is_encoded
        ev["decoded_preview"] = decoded_preview
        ev["script_hash"] = script_hash
        ev["telemetry_source"] = "agent_proc"
        ev["is_advisory"] = True
        ev["autonomous_action"] = False
        events.append(ev)

    return events


def collect_privilege_escalation_indicators(
    state: CollectorState,
    agent_id: str,
    trace_id: str,
    cfg: dict[str, Any] | None = None,
    *,
    proc_root: Path | None = None,
) -> list[dict[str, Any]]:
    """
    Detect privilege escalation indicators from /proc.
    Observes uid transitions and sudo/su invocations.
    Read-only — no process manipulation, no signal sending.
    """
    if not IS_LINUX and proc_root is None:
        return []

    root = proc_root or Path("/proc")
    events: list[dict[str, Any]] = []

    try:
        pid_dirs = [d for d in root.iterdir() if d.name.isdigit()]
    except (PermissionError, OSError):
        return []

    for pid_dir in pid_dirs:
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
        uid_field = fields.get("Uid", "")
        if not uid_field:
            continue

        uid_parts = uid_field.split()
        if len(uid_parts) < 2:
            continue

        try:
            ruid = int(uid_parts[0])  # real uid
            euid = int(uid_parts[1])  # effective uid
        except ValueError:
            continue

        ppid_str = fields.get("PPid", "0")

        try:
            cmdline_raw = (pid_dir / "cmdline").read_bytes()
            cmdline = cmdline_raw.replace(b"\x00", b" ").decode(errors="replace").strip()
        except OSError:
            cmdline = name

        name_lower = name.lower()
        escalation_type: str | None = None
        confidence: float = 0.0
        original_user = _uid_to_username(ruid)
        escalated_user = _uid_to_username(euid) if euid == 0 else None

        if name_lower in ("sudo", "pkexec", "doas", "runuser"):
            escalation_type = "sudo_invocation"
            confidence = 0.85
        elif name_lower in ("su", "newgrp"):
            escalation_type = "su_invocation"
            confidence = 0.80
        elif euid == 0 and ruid != 0:
            escalation_type = "uid_transition"
            confidence = 0.90

        if escalation_type is None:
            continue

        ev = base_event(
            "privilege_escalation_attempt", agent_id, trace_id,
            process_name=name,
            user=original_user,
        )
        ev["event_id"] = make_event_id(
            HOSTNAME, "priv_esc", pid_dir.name, name, escalation_type,
            datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M"),
        )
        ev["pid"] = int(pid_dir.name)
        ev["original_uid"] = ruid
        ev["escalated_uid"] = euid
        ev["original_user"] = original_user
        ev["escalated_user"] = escalated_user
        ev["escalation_type"] = escalation_type
        ev["command_line"] = cmdline[:4096] if cmdline else None
        ev["telemetry_source"] = "agent_proc"
        ev["confidence"] = confidence
        ev["is_advisory"] = True
        ev["autonomous_action"] = False
        events.append(ev)

    return events


def collect_container_activity(
    state: CollectorState,
    agent_id: str,
    trace_id: str,
    cfg: dict[str, Any] | None = None,
    *,
    proc_root: Path | None = None,
) -> list[dict[str, Any]]:
    """
    Detect container namespace activity from /proc/[pid]/cgroup.
    Identifies docker, containerd, lxc, kubernetes containers.
    Read-only — no container manipulation.
    """
    if not IS_LINUX and proc_root is None:
        return []

    root = proc_root or Path("/proc")
    events: list[dict[str, Any]] = []
    seen_containers: set[str] = set()

    # Detect container type from cgroup content
    def _detect_namespace(cgroup_text: str) -> tuple[str | None, str | None]:
        import re as _re
        # Docker: /docker/<id> or /system.slice/docker-<id>
        m = _re.search(r'/docker[/-]([a-f0-9]{12,64})', cgroup_text)
        if m:
            return NS_DOCKER, m.group(1)[:64]
        # Kubernetes: /kubepods/.../<pod-id>/<container-id>
        m = _re.search(r'/kubepods/[^/]+/[^/]+/([a-f0-9]{12,64})', cgroup_text)
        if m:
            return NS_K8S, m.group(1)[:64]
        # containerd
        if "containerd" in cgroup_text:
            m = _re.search(r'/([a-f0-9]{12,64})', cgroup_text)
            if m:
                return NS_CONTAINERD, m.group(1)[:64]
        # LXC
        if "/lxc/" in cgroup_text:
            m = _re.search(r'/lxc/([^/\n]+)', cgroup_text)
            if m:
                return NS_LXC, m.group(1)[:64]
        return None, None

    NS_DOCKER = "docker"
    NS_K8S = "kubernetes"
    NS_CONTAINERD = "containerd"
    NS_LXC = "lxc"

    try:
        pid_dirs = [d for d in root.iterdir() if d.name.isdigit()]
    except (PermissionError, OSError):
        return []

    for pid_dir in pid_dirs:
        try:
            cgroup_text = (pid_dir / "cgroup").read_text(errors="replace")
        except OSError:
            continue

        ns_type, container_id = _detect_namespace(cgroup_text)
        if ns_type is None or container_id is None:
            continue
        if container_id in seen_containers:
            continue
        seen_containers.add(container_id)

        # Get process name
        name = ""
        try:
            status_text = (pid_dir / "status").read_text(errors="replace")
            for line in status_text.splitlines():
                if line.startswith("Name:"):
                    name = line.split(":", 1)[1].strip()
                    break
        except OSError:
            pass

        ev = base_event(
            "container_activity", agent_id, trace_id,
            process_name=name,
        )
        ev["event_id"] = make_event_id(
            HOSTNAME, "container_activity", container_id, ns_type,
            datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M"),
        )
        ev["container_id"] = container_id
        ev["container_name"] = None
        ev["image_name"] = None
        ev["activity_type"] = "namespace_detected"
        ev["pid"] = int(pid_dir.name)
        ev["namespace_type"] = ns_type
        ev["telemetry_source"] = "agent_proc"
        ev["is_advisory"] = True
        ev["autonomous_action"] = False
        events.append(ev)

    return events


def collect_windows_sysmon(
    state: CollectorState,
    agent_id: str,
    trace_id: str,
    cfg: dict[str, Any] | None = None,
    *,
    fixture_path: str | None = None,
) -> list[dict[str, Any]]:
    """
    Ingest Sysmon events.
    In production (IS_WINDOWS, no fixture): reads via wevtutil subprocess.
    In tests: reads from a JSONL fixture file.
    Advisory-only — no process termination, no rule blocking.
    Supports event IDs: 1 (process create), 3 (network connect),
    11 (file create), 13 (registry value set).
    """
    events: list[dict[str, Any]] = []

    def _parse_fixture(path: str) -> list[dict[str, Any]]:
        result = []
        try:
            for line in Path(path).read_text(encoding="utf-8").splitlines():
                line = line.strip()
                if line:
                    try:
                        result.append(json.loads(line))
                    except json.JSONDecodeError:
                        pass
        except OSError:
            pass
        return result

    def _normalize_sysmon_event(raw: dict[str, Any]) -> dict[str, Any] | None:
        event_id = raw.get("event_id") or raw.get("EventID")
        try:
            event_id = int(event_id)
        except (TypeError, ValueError):
            return None

        if event_id == 1:
            event_type = "script_execution" if raw.get("process_name", "").lower() in _SCRIPT_INTERPRETERS else "process_start"
        elif event_id == 3:
            event_type = "network_connection"
        elif event_id == 11:
            event_type = "file_write"
        elif event_id == 13:
            event_type = "registry_value_set"
        else:
            event_type = f"sysmon_event_{event_id}"

        ev = base_event(
            event_type, agent_id, trace_id,
            process_name=raw.get("process_name") or raw.get("Image", ""),
            user=raw.get("user") or raw.get("User"),
        )
        ev["event_id"] = make_event_id(
            HOSTNAME, "sysmon", str(event_id),
            raw.get("process_guid") or raw.get("ProcessGuid") or str(time.time()),
        )
        ev["command_line"] = raw.get("command_line") or raw.get("CommandLine")
        ev["parent_process_name"] = raw.get("parent_process_name") or raw.get("ParentImage")
        ev["telemetry_source"] = "sysmon"
        ev["sysmon_event_id"] = event_id
        ev["is_advisory"] = True
        ev["autonomous_action"] = False
        # Network fields for event 3
        if event_id == 3:
            ev["destination_ip"] = raw.get("destination_ip") or raw.get("DestinationIp")
            ev["destination_port"] = raw.get("destination_port") or raw.get("DestinationPort")
        # Registry fields for event 13
        if event_id == 13:
            ev["registry_key"] = raw.get("registry_key") or raw.get("TargetObject")
            ev["registry_value"] = raw.get("registry_value") or raw.get("Details")
        return ev

    if fixture_path is not None:
        raw_events = _parse_fixture(fixture_path)
    elif IS_WINDOWS:
        # Production: query Sysmon operational log via wevtutil
        try:
            result = subprocess.run(
                [
                    "wevtutil", "qe",
                    "Microsoft-Windows-Sysmon/Operational",
                    "/f:Text", "/c:50", "/rd:true",
                ],
                capture_output=True, text=True, timeout=10,
            )
            # wevtutil text output is not JSON; parse key:value lines
            raw_events = []
            current: dict[str, Any] = {}
            for line in result.stdout.splitlines():
                line = line.strip()
                if not line:
                    if current:
                        raw_events.append(current)
                        current = {}
                elif ":" in line:
                    k, _, v = line.partition(":")
                    current[k.strip()] = v.strip()
            if current:
                raw_events.append(current)
        except Exception:
            return []
    else:
        return []

    for raw in raw_events[:100]:
        ev = _normalize_sysmon_event(raw)
        if ev:
            events.append(ev)

    return events


def collect_windows_powershell_events(
    state: CollectorState,
    agent_id: str,
    trace_id: str,
    cfg: dict[str, Any] | None = None,
    *,
    fixture_path: str | None = None,
) -> list[dict[str, Any]]:
    """
    Ingest Windows PowerShell operational log events (Event IDs 4103, 4104).
    In tests: reads from a JSONL fixture file.
    Advisory-only — no PS execution blocking.
    """
    events: list[dict[str, Any]] = []

    def _parse_fixture(path: str) -> list[dict[str, Any]]:
        result = []
        try:
            for line in Path(path).read_text(encoding="utf-8").splitlines():
                line = line.strip()
                if line:
                    try:
                        result.append(json.loads(line))
                    except json.JSONDecodeError:
                        pass
        except OSError:
            pass
        return result

    if fixture_path is not None:
        raw_events = _parse_fixture(fixture_path)
    elif IS_WINDOWS:
        try:
            result = subprocess.run(
                [
                    "wevtutil", "qe",
                    "Microsoft-Windows-PowerShell/Operational",
                    "/f:Text", "/c:50", "/rd:true",
                ],
                capture_output=True, text=True, timeout=10,
            )
            raw_events = []
            current: dict[str, Any] = {}
            for line in result.stdout.splitlines():
                line = line.strip()
                if not line:
                    if current:
                        raw_events.append(current)
                        current = {}
                elif ":" in line:
                    k, _, v = line.partition(":")
                    current[k.strip()] = v.strip()
            if current:
                raw_events.append(current)
        except Exception:
            return []
    else:
        return []

    for raw in raw_events[:100]:
        event_id = raw.get("event_id") or raw.get("EventID")
        try:
            event_id = int(event_id)
        except (TypeError, ValueError):
            continue

        cmdline = raw.get("script_block") or raw.get("ScriptBlockText") or raw.get("command_line") or ""
        is_encoded, decoded_preview = _detect_encoded_payload(cmdline)

        ev = base_event(
            "script_execution", agent_id, trace_id,
            process_name="powershell.exe",
            user=raw.get("user") or raw.get("User"),
        )
        ev["event_id"] = make_event_id(
            HOSTNAME, "ps_event", str(event_id),
            raw.get("script_block_id") or str(time.time()),
        )
        ev["command_line"] = cmdline[:4096]
        ev["script_source"] = "encoded" if is_encoded else "inline"
        ev["is_encoded"] = is_encoded
        ev["decoded_preview"] = decoded_preview
        ev["telemetry_source"] = "powershell_operational"
        ev["ps_event_id"] = event_id
        ev["is_advisory"] = True
        ev["autonomous_action"] = False
        events.append(ev)

    return events


def collect_windows_security_events(
    state: CollectorState,
    agent_id: str,
    trace_id: str,
    cfg: dict[str, Any] | None = None,
    *,
    fixture_path: str | None = None,
) -> list[dict[str, Any]]:
    """
    Ingest Windows Security Event Log entries relevant to process creation and
    privilege use (Event IDs 4688, 4672, 4697, 4698).
    In tests: reads from a JSONL fixture file.
    Advisory-only — no account modification, no policy enforcement.
    """
    events: list[dict[str, Any]] = []

    def _parse_fixture(path: str) -> list[dict[str, Any]]:
        result = []
        try:
            for line in Path(path).read_text(encoding="utf-8").splitlines():
                line = line.strip()
                if line:
                    try:
                        result.append(json.loads(line))
                    except json.JSONDecodeError:
                        pass
        except OSError:
            pass
        return result

    if fixture_path is not None:
        raw_events = _parse_fixture(fixture_path)
    elif IS_WINDOWS:
        try:
            result = subprocess.run(
                [
                    "wevtutil", "qe", "Security",
                    "/q:*[System[(EventID=4688 or EventID=4672 or EventID=4697 or EventID=4698)]]",
                    "/f:Text", "/c:50", "/rd:true",
                ],
                capture_output=True, text=True, timeout=10,
            )
            raw_events = []
            current: dict[str, Any] = {}
            for line in result.stdout.splitlines():
                line = line.strip()
                if not line:
                    if current:
                        raw_events.append(current)
                        current = {}
                elif ":" in line:
                    k, _, v = line.partition(":")
                    current[k.strip()] = v.strip()
            if current:
                raw_events.append(current)
        except Exception:
            return []
    else:
        return []

    for raw in raw_events[:100]:
        event_id = raw.get("event_id") or raw.get("EventID")
        try:
            event_id = int(event_id)
        except (TypeError, ValueError):
            continue

        process_name = raw.get("process_name") or raw.get("NewProcessName") or ""
        # Strip path to basename
        process_name = Path(process_name).name if process_name else ""
        user = raw.get("user") or raw.get("SubjectUserName") or raw.get("AccountName")
        integrity = raw.get("integrity_level") or raw.get("MandatoryLabel")

        if event_id == 4688:
            # Process creation
            ev = base_event(
                "process_start", agent_id, trace_id,
                process_name=process_name,
                user=user,
            )
            ev["command_line"] = raw.get("command_line") or raw.get("CommandLine")
            ev["integrity_level"] = integrity
            ev["parent_process_name"] = raw.get("parent_process_name") or raw.get("ParentProcessName")
            ev["telemetry_source"] = "security_event"

            # High integrity → potential privilege escalation indicator
            if integrity and ("high" in str(integrity).lower() or "system" in str(integrity).lower()):
                ev["escalation_type"] = "integrity_level_high"
                ev["is_privilege_escalation_indicator"] = True

        elif event_id == 4672:
            # Special privileges assigned to new logon
            ev = base_event(
                "privilege_escalation_attempt", agent_id, trace_id,
                process_name=process_name or "lsass.exe",
                user=user,
            )
            ev["escalation_type"] = "token_impersonation"
            ev["telemetry_source"] = "security_event"

        elif event_id in (4697, 4698):
            # Service/scheduled task installation — persistence indicator
            ev = base_event(
                "persistence_indicator", agent_id, trace_id,
                process_name=process_name or "services.exe",
                user=user,
            )
            ev["persistence_type"] = "service_install" if event_id == 4697 else "scheduled_task"
            ev["telemetry_source"] = "security_event"
        else:
            continue

        ev["event_id"] = make_event_id(
            HOSTNAME, "sec_event", str(event_id),
            raw.get("record_id") or str(time.time()),
        )
        ev["security_event_id"] = event_id
        ev["is_advisory"] = True
        ev["autonomous_action"] = False
        events.append(ev)

    return events


def collect_windows_registry_persistence(
    state: CollectorState,
    agent_id: str,
    trace_id: str,
    cfg: dict[str, Any] | None = None,
    *,
    fixture_path: str | None = None,
) -> list[dict[str, Any]]:
    """
    Check Windows registry Run keys for persistence entries.
    In tests: reads from a JSONL fixture file (list of {key, name, value}).
    Advisory-only — never modifies or deletes registry entries.
    """
    events: list[dict[str, Any]] = []

    def _parse_fixture(path: str) -> list[dict[str, Any]]:
        result = []
        try:
            for line in Path(path).read_text(encoding="utf-8").splitlines():
                line = line.strip()
                if line:
                    try:
                        result.append(json.loads(line))
                    except json.JSONDecodeError:
                        pass
        except OSError:
            pass
        return result

    def _entries_from_raw(raw_items: list[dict[str, Any]]) -> list[dict[str, Any]]:
        result = []
        for item in raw_items:
            key = item.get("key") or item.get("RegistryKey", "")
            name = item.get("name") or item.get("ValueName", "")
            value = item.get("value") or item.get("ValueData", "")
            if not name:
                continue
            ev = base_event(
                "registry_persistence", agent_id, trace_id,
                process_name="reg.exe",
            )
            ev["event_id"] = make_event_id(
                HOSTNAME, "reg_persist", key, name,
                datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M"),
            )
            ev["registry_key"] = key
            ev["registry_value_name"] = name
            ev["registry_value_data"] = str(value)[:1024]
            ev["persistence_type"] = "registry_run_key"
            ev["telemetry_source"] = "registry"
            ev["is_advisory"] = True
            ev["autonomous_action"] = False
            result.append(ev)
        return result

    if fixture_path is not None:
        return _entries_from_raw(_parse_fixture(fixture_path))

    if not IS_WINDOWS:
        return []

    for reg_key in _WIN_REGISTRY_PERSIST_KEYS:
        try:
            result = subprocess.run(
                ["reg", "query", reg_key],
                capture_output=True, text=True, timeout=5,
            )
            raw_items: list[dict[str, Any]] = []
            for line in result.stdout.splitlines():
                parts = line.strip().split(None, 2)
                if len(parts) >= 3 and parts[1].upper() in ("REG_SZ", "REG_EXPAND_SZ"):
                    raw_items.append({"key": reg_key, "name": parts[0], "value": parts[2]})
            events.extend(_entries_from_raw(raw_items))
        except Exception:
            pass

    return events


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

    # Low-level behavioral telemetry collectors — Phase 1
    low_level_collectors = [
        collect_script_executions,
        collect_privilege_escalation_indicators,
        collect_container_activity,
    ]
    for collector in low_level_collectors:
        try:
            new_events = collector(col_state, agent_id, trace_id, cfg)
            all_events.extend(new_events)
        except Exception as exc:
            log.error(
                "Low-level collector %s raised: %s\n%s",
                collector.__name__, exc, traceback.format_exc(),
            )

    # Windows-specific collectors — only invoked on Windows
    if IS_WINDOWS:
        windows_collectors = [
            collect_windows_sysmon,
            collect_windows_powershell_events,
            collect_windows_security_events,
            collect_windows_registry_persistence,
        ]
        for collector in windows_collectors:
            try:
                new_events = collector(col_state, agent_id, trace_id, cfg)
                all_events.extend(new_events)
            except Exception as exc:
                log.error(
                    "Windows collector %s raised: %s\n%s",
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
    # ENTERPRISE-053: Real endpoint enrollment mode
    parser.add_argument(
        "--enroll", action="store_true",
        help="Run enrollment snapshot (one-shot: collect + report enrollment data, then exit)",
    )
    parser.add_argument(
        "--tenant-id", default="",
        help="Tenant ID to associate with this enrollment",
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

    if args.enroll:
        # ENTERPRISE-053: enrollment snapshot mode
        # Collect one cycle, report enrollment data, exit safely
        tenant_id = args.tenant_id or cfg.get("tenant_id", "")
        log.info("Enrollment mode — hostname=%s platform=%s tenant_id=%s", HOSTNAME, platform.system(), tenant_id or "none")
        count = run_collection_cycle(cfg, col_state, agent_id, gateway, hardened_buffer, quality)
        enrollment_data = {
            "hostname": HOSTNAME,
            "os_platform": platform.system().lower(),
            "os_version": platform.version(),
            "agent_version": getattr(cfg, "get", lambda k, d=None: d)("agent_version", "1.0.0"),
            "tenant_id": tenant_id,
            "events_collected": count,
            "is_real": True,
            "is_advisory": True,
        }
        log.info("Enrollment snapshot complete: %s", json.dumps(enrollment_data))
        return

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
                    # Build spool stats snapshot for fleet hardening observability
                    spool_stats = {
                        "dropped_events":   quality.events_dropped,
                        "retry_count":      quality.retry_count,
                        "buffer_depth":     hardened_buffer.depth(),
                        "events_per_sec":   quality.events_per_sec(),
                        "spool_disk_bytes": buffer.size(),
                        "spool_capped":     False,
                        "disk_pressure":    hardened_buffer._disk_pressure(),
                    }
                    hb_event = collect_heartbeat_event(agent_id, trace_id, buffer.size())
                    gateway.ship([hb_event])
                    soc.heartbeat(agent_id, metrics, trace_id=trace_id, spool_stats=spool_stats)
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
