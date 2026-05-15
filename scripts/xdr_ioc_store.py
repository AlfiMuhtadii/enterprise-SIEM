#!/usr/bin/env python3
"""Threat intelligence IOC store — SQLite-backed, replay-safe, idempotent.

Usage as module:
    from xdr_ioc_store import IoCStore
    store = IoCStore()
    store.load_batch(iocs)
    result = store.lookup("ip", "185.220.101.200")

Usage as HTTP lookup service:
    python scripts/xdr_ioc_store.py --serve --port 8097 --db storage/threat_intel/iocs.db

The service exposes:
    GET /v1/lookup?type=<ip|domain|url|file_hash>&value=<value>
    GET /health
    GET /metrics
"""

from __future__ import annotations

import argparse
import json
import sqlite3
import sys
from collections import defaultdict
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
from typing import Any, Dict, List, Optional
from urllib.parse import parse_qs, urlparse

NORMALIZATION_VERSION = "ioc-v1"
IOC_TYPES = {"ip", "domain", "url", "file_hash"}
SEVERITY_ORDER = {"critical": 4, "high": 3, "medium": 2, "low": 1, "info": 0}

_DEFAULT_DB = "storage/threat_intel/iocs.db"


class IoCStore:
    """SQLite-backed IOC store. Replay-safe, idempotent, supports expiration."""

    def __init__(self, db_path: Optional[str] = None, root: Optional[Path] = None):
        base = root or Path(__file__).resolve().parents[1]
        self.db_path = base / (db_path or _DEFAULT_DB)
        self.db_path.parent.mkdir(parents=True, exist_ok=True)
        self._metrics: Dict[str, int] = defaultdict(int)
        self._init_schema()

    def _conn(self) -> sqlite3.Connection:
        conn = sqlite3.connect(str(self.db_path))
        conn.row_factory = sqlite3.Row
        return conn

    def _init_schema(self) -> None:
        with self._conn() as conn:
            conn.execute("""
                CREATE TABLE IF NOT EXISTS iocs (
                    ioc_id              TEXT NOT NULL,
                    ioc_type            TEXT NOT NULL,
                    value               TEXT NOT NULL,
                    severity            TEXT,
                    confidence          REAL,
                    source              TEXT,
                    tags                TEXT,
                    first_seen          TEXT,
                    last_seen           TEXT,
                    expires_at          TEXT,
                    ttl_seconds         INTEGER,
                    active              INTEGER NOT NULL DEFAULT 1,
                    created_at          TEXT NOT NULL,
                    normalization_version TEXT,
                    updated_at          TEXT NOT NULL,
                    PRIMARY KEY (ioc_id),
                    UNIQUE (ioc_type, value)
                )
            """)
            conn.execute(
                "CREATE INDEX IF NOT EXISTS idx_ioc_lookup "
                "ON iocs(ioc_type, value, active, expires_at)"
            )

    # ---- Write operations (replay-safe) ----

    def insert_ioc(self, ioc: Dict[str, Any]) -> str:
        """Insert or update IOC. Returns 'inserted', 'updated', or 'duplicate'.

        Idempotency: same ioc_id → 'duplicate' (no-op).
        Same (ioc_type, value) with different ioc_id → 'updated'.
        """
        now = _now()
        ioc_id = ioc.get("ioc_id", "")
        ioc_type = str(ioc.get("ioc_type", "")).lower()
        value = str(ioc.get("value", ""))

        if not ioc_id or not ioc_type or not value:
            return "invalid"

        value = _normalize_value(ioc_type, value)
        expires_at = _compute_expires_at(ioc, now)

        with self._conn() as conn:
            existing_by_id = conn.execute(
                "SELECT ioc_id FROM iocs WHERE ioc_id = ?", (ioc_id,)
            ).fetchone()
            if existing_by_id:
                self._metrics["ioc_duplicate_total"] += 1
                return "duplicate"

            existing_by_val = conn.execute(
                "SELECT ioc_id FROM iocs WHERE ioc_type = ? AND value = ?",
                (ioc_type, value),
            ).fetchone()

            tags_json = json.dumps(ioc.get("tags") or [])
            active = 1 if ioc.get("active", True) else 0

            if existing_by_val:
                conn.execute(
                    """UPDATE iocs SET ioc_id=?, severity=?, confidence=?, source=?,
                       tags=?, first_seen=?, last_seen=?, expires_at=?, ttl_seconds=?,
                       active=?, normalization_version=?, updated_at=?
                       WHERE ioc_type=? AND value=?""",
                    (
                        ioc_id, ioc.get("severity"), ioc.get("confidence"),
                        ioc.get("source"), tags_json,
                        ioc.get("first_seen"), ioc.get("last_seen"),
                        expires_at, ioc.get("ttl_seconds"),
                        active, ioc.get("normalization_version", NORMALIZATION_VERSION),
                        now, ioc_type, value,
                    ),
                )
                self._metrics["ioc_loaded_total"] += 1
                return "updated"

            conn.execute(
                """INSERT INTO iocs
                   (ioc_id, ioc_type, value, severity, confidence, source, tags,
                    first_seen, last_seen, expires_at, ttl_seconds, active,
                    created_at, normalization_version, updated_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
                (
                    ioc_id, ioc_type, value,
                    ioc.get("severity"), ioc.get("confidence"),
                    ioc.get("source"), tags_json,
                    ioc.get("first_seen"), ioc.get("last_seen"),
                    expires_at, ioc.get("ttl_seconds"), active,
                    ioc.get("created_at", now),
                    ioc.get("normalization_version", NORMALIZATION_VERSION),
                    now,
                ),
            )
            self._metrics["ioc_loaded_total"] += 1
            return "inserted"

    def load_batch(self, iocs: List[Dict[str, Any]]) -> Dict[str, int]:
        """Load a list of IOCs. Returns operation counts."""
        stats: Dict[str, int] = defaultdict(int)
        for ioc in iocs:
            result = self.insert_ioc(ioc)
            stats[result] += 1
        return dict(stats)

    # ---- Read operations ----

    def lookup(self, ioc_type: str, value: str) -> Optional[Dict[str, Any]]:
        """Lookup an IOC. Returns None if not found, inactive, or expired."""
        self._metrics["ioc_lookup_total"] += 1
        value = _normalize_value(ioc_type, value)
        now = _now()
        with self._conn() as conn:
            row = conn.execute(
                """SELECT * FROM iocs
                   WHERE ioc_type = ? AND value = ? AND active = 1
                   AND (expires_at IS NULL OR expires_at > ?)""",
                (ioc_type, value, now),
            ).fetchone()
        if row is None:
            return None
        self._metrics["ioc_match_total"] += 1
        result = dict(row)
        result["tags"] = json.loads(result.get("tags") or "[]")
        result["matched"] = True
        return result

    def get_expired_count(self) -> int:
        now = _now()
        with self._conn() as conn:
            count = conn.execute(
                "SELECT COUNT(*) FROM iocs WHERE expires_at IS NOT NULL AND expires_at <= ?",
                (now,),
            ).fetchone()[0]
        self._metrics["ioc_expired_total"] = count
        return count

    def get_total_count(self) -> int:
        with self._conn() as conn:
            return conn.execute("SELECT COUNT(*) FROM iocs").fetchone()[0]

    def get_active_count(self) -> int:
        now = _now()
        with self._conn() as conn:
            return conn.execute(
                "SELECT COUNT(*) FROM iocs WHERE active=1 AND (expires_at IS NULL OR expires_at > ?)",
                (now,),
            ).fetchone()[0]

    def get_metrics(self) -> Dict[str, Any]:
        expired = self.get_expired_count()
        m = dict(self._metrics)
        m["ioc_expired_total"] = expired
        m["ioc_total_in_store"] = self.get_total_count()
        m["ioc_active_in_store"] = self.get_active_count()
        return m


# ---- Helpers ----

def _now() -> str:
    return datetime.now(timezone.utc).isoformat()


def _normalize_value(ioc_type: str, value: str) -> str:
    if ioc_type in ("ip", "domain", "file_hash"):
        return value.strip().lower()
    return value.strip()


def _compute_expires_at(ioc: Dict[str, Any], now: str) -> Optional[str]:
    if ioc.get("expires_at"):
        return ioc["expires_at"]
    ttl = ioc.get("ttl_seconds")
    if ttl is not None:
        from datetime import timedelta
        created = datetime.fromisoformat(ioc.get("created_at", now).replace("Z", "+00:00"))
        return (created + timedelta(seconds=int(ttl))).isoformat()
    return None


# ---- Enrichment helpers (Python-side shadow correlation) ----

def enrich_endpoint_events(events: List[Dict[str, Any]], store: IoCStore) -> List[Dict[str, Any]]:
    """Check endpoint events against the IOC store. Returns IOC match shadow alerts."""
    alerts = []

    def _ep(ev: Dict[str, Any], *path: str) -> str:
        cur: Any = ev
        for key in path:
            if not isinstance(cur, dict):
                return ""
            cur = cur.get(key)
        return str(cur) if cur is not None else ""

    def _make_alert(rule_id: str, ev: Dict[str, Any], ioc: Dict[str, Any], evidence: Dict[str, Any]) -> Dict[str, Any]:
        return {
            "rule_id": rule_id,
            "shadow_mode": True,
            "trace_id": _ep(ev, "trace_id"),
            "host": _ep(ev, "host"),
            "user": _ep(ev, "user"),
            "event_id": _ep(ev, "normalized_event_id"),
            "ioc_id": ioc.get("ioc_id"),
            "ioc_severity": ioc.get("severity"),
            "ioc_confidence": ioc.get("confidence"),
            "ioc_source": ioc.get("source"),
            "ioc_tags": ioc.get("tags"),
            "evidence": evidence,
        }

    for ev in events:
        if _ep(ev, "telemetry_type") != "endpoint":
            continue

        # IOC IP match
        for field in ("destination_ip", "source_ip"):
            ip = _ep(ev, "network", field)
            if ip:
                ioc = store.lookup("ip", ip)
                if ioc:
                    alerts.append(_make_alert(
                        "ioc_ip_match", ev, ioc,
                        {"matched_ip": ip, "matched_field": f"network.{field}"},
                    ))

        # IOC domain match
        domain = _ep(ev, "dns", "domain")
        if domain:
            ioc = store.lookup("domain", domain)
            if ioc:
                alerts.append(_make_alert(
                    "ioc_domain_match", ev, ioc,
                    {"matched_domain": domain},
                ))

        # IOC file hash match
        for section, key in [("process", "hash"), ("file", "hash")]:
            h = _ep(ev, section, key)
            if h:
                ioc = store.lookup("file_hash", h)
                if ioc:
                    alerts.append(_make_alert(
                        "ioc_file_hash_match", ev, ioc,
                        {"matched_hash": h, "matched_section": f"{section}.{key}"},
                    ))

    return alerts


# ---- HTTP Lookup Service ----

def _make_handler(store: IoCStore):
    class IoCLookupHandler(BaseHTTPRequestHandler):
        def log_message(self, fmt, *args):
            pass  # suppress per-request log noise

        def do_GET(self):
            parsed = urlparse(self.path)
            path = parsed.path

            if path == "/health":
                self._respond(200, {"status": "ok", "service": "ioc-lookup-service"})
                return

            if path == "/metrics":
                self._respond(200, store.get_metrics())
                return

            if path == "/v1/lookup":
                params = parse_qs(parsed.query)
                ioc_type = (params.get("type") or [""])[0]
                value = (params.get("value") or [""])[0]
                if not ioc_type or not value:
                    self._respond(400, {"error": "missing type or value"})
                    return
                if ioc_type not in IOC_TYPES:
                    self._respond(400, {"error": f"invalid type '{ioc_type}'"})
                    return
                result = store.lookup(ioc_type, value)
                if result is None:
                    self._respond(200, {"matched": False, "ioc_type": ioc_type, "value": value})
                else:
                    self._respond(200, result)
                return

            self._respond(404, {"error": "not found"})

        def _respond(self, status: int, body: Any) -> None:
            data = json.dumps(body, default=str).encode("utf-8")
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            self.wfile.write(data)

    return IoCLookupHandler


def serve(store: IoCStore, host: str = "127.0.0.1", port: int = 8097) -> None:
    handler = _make_handler(store)
    server = HTTPServer((host, port), handler)
    print(f"ioc-lookup-service listening on {host}:{port} db={store.db_path}", flush=True)
    server.serve_forever()


# ---- CLI ----

def _parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="IOC store — module or HTTP service")
    parser.add_argument("--serve", action="store_true", help="Start HTTP lookup service")
    parser.add_argument("--port", type=int, default=8097)
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--db", default=None, help="SQLite database path")
    parser.add_argument("--load", default=None, help="Load IOC batch JSON file on startup")
    return parser.parse_args()


if __name__ == "__main__":
    args = _parse_args()
    store = IoCStore(db_path=args.db)

    if args.load:
        path = Path(args.load)
        iocs = json.loads(path.read_text(encoding="utf-8"))
        stats = store.load_batch(iocs)
        print(f"loaded {stats}")

    if args.serve:
        try:
            serve(store, host=args.host, port=args.port)
        except KeyboardInterrupt:
            print("ioc-lookup-service stopped")
            sys.exit(0)
    else:
        metrics = store.get_metrics()
        print(json.dumps(metrics, indent=2))
