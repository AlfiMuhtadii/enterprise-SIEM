#!/usr/bin/env python3
"""Runtime clients for distributed XDR infrastructure."""

from __future__ import annotations

import base64
import hashlib
import json
import os
import ssl
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, Iterable, List, Optional, Tuple


def env(name: str, default: str) -> str:
    return os.getenv(name, default)


def env_bool(name: str, default: bool = True) -> bool:
    value = os.getenv(name)
    if value is None:
        return default
    normalized = value.strip().lower()
    if normalized in {"1", "true", "yes", "on"}:
        return True
    if normalized in {"0", "false", "no", "off"}:
        return False
    raise ValueError(f"{name} must be a boolean value")


def tls_context_for_url(url: str, verify_tls: bool = True, ca_cert: str = "") -> Optional[ssl.SSLContext]:
    if urllib.parse.urlparse(url).scheme.lower() != "https":
        return None
    if not verify_tls:
        return ssl._create_unverified_context()
    return ssl.create_default_context(cafile=ca_cert or None)


@dataclass
class HttpResult:
    ok: bool
    status: int
    elapsed_ms: float
    body: str
    error: str = ""


class HttpClient:
    def __init__(self, timeout: float = 5.0, retries: int = 2, headers: Optional[Dict[str, str]] = None,
                 ssl_context: Optional[ssl.SSLContext] = None):
        self.timeout = timeout
        self.retries = retries
        self.headers = headers or {}
        self.ssl_context = ssl_context

    def request(self, method: str, url: str, body: Any = None, headers: Optional[Dict[str, str]] = None) -> HttpResult:
        payload = None
        merged = dict(self.headers)
        if headers:
            merged.update(headers)
        if body is not None:
            payload = body if isinstance(body, bytes) else json.dumps(body, separators=(",", ":")).encode("utf-8")
            merged.setdefault("Content-Type", "application/json")
        last = HttpResult(False, 0, 0.0, "", "")
        for attempt in range(max(1, self.retries + 1)):
            started = time.perf_counter()
            try:
                req = urllib.request.Request(url, data=payload, headers=merged, method=method.upper())
                options = {"timeout": self.timeout}
                if self.ssl_context is not None:
                    options["context"] = self.ssl_context
                with urllib.request.urlopen(req, **options) as resp:
                    text = resp.read().decode("utf-8", errors="replace")
                    return HttpResult(200 <= resp.status < 300, resp.status, (time.perf_counter() - started) * 1000, text)
            except urllib.error.HTTPError as exc:
                text = exc.read().decode("utf-8", errors="replace")
                last = HttpResult(False, exc.code, (time.perf_counter() - started) * 1000, text, str(exc))
            except Exception as exc:
                last = HttpResult(False, 0, (time.perf_counter() - started) * 1000, "", str(exc))
            if attempt < self.retries:
                time.sleep(0.2 * (2 ** attempt))
        return last


class RedpandaClient:
    def __init__(self, rest_url: str, timeout: float = 5.0):
        self.rest_url = rest_url.rstrip("/")
        self.http = HttpClient(timeout=timeout, retries=2)

    def health(self) -> Dict[str, Any]:
        res = self.http.request("GET", f"{self.rest_url}/topics")
        topics: List[str] = []
        if res.ok:
            try:
                parsed = json.loads(res.body)
                topics = parsed if isinstance(parsed, list) else parsed.get("topics", [])
            except Exception:
                topics = []
        return {"ok": res.ok, "status": res.status, "latency_ms": res.elapsed_ms, "topics": topics, "error": res.error}

    def create_topic(self, topic: str) -> HttpResult:
        # PandaProxy creates topics lazily in many dev setups; this records a validation request.
        return self.http.request("GET", f"{self.rest_url}/topics/{urllib.parse.quote(topic)}")

    def produce(self, topic: str, events: Iterable[Dict[str, Any]]) -> Tuple[int, int]:
        sent = failed = 0
        url = f"{self.rest_url}/topics/{urllib.parse.quote(topic)}"
        for event in events:
            res = self.http.request(
                "POST",
                url,
                {"records": [{"value": event}]},
                {"Content-Type": "application/vnd.kafka.json.v2+json", "Accept": "application/vnd.kafka.v2+json"},
            )
            if res.ok:
                sent += 1
            else:
                failed += 1
                write_dlq(topic, event, res.error or res.body)
        return sent, failed


class ClickHouseClient:
    def __init__(self, base_url: str, database: str, user: str, password: str, timeout: float = 5.0,
                 verify_tls: bool = True, ca_cert: str = ""):
        self.base_url = base_url.rstrip("/")
        self.database = database
        auth = base64.b64encode(f"{user}:{password}".encode()).decode()
        self.http = HttpClient(
            timeout=timeout,
            retries=2,
            headers={"Authorization": f"Basic {auth}"},
            ssl_context=tls_context_for_url(self.base_url, verify_tls, ca_cert),
        )

    def query(self, sql: str) -> HttpResult:
        # date_time_input_format=best_effort: ClickHouse's default DateTime64
        # text parser only accepts "YYYY-MM-DD HH:MM:SS[.sss]" (no "T"/"Z"),
        # so a plain ISO-8601 string like this codebase's telemetry events
        # already carry (e.g. "2026-07-12T00:00:00Z") is rejected outright
        # with CANNOT_PARSE_INPUT_ASSERTION_FAILED -- confirmed against a
        # real ClickHouse instance, not assumed. best_effort is a strict
        # superset (accepts more formats, rejects nothing the strict parser
        # already accepted), so this is safe to set unconditionally for
        # every query, not just inserts into telemetry_events.
        url = (
            f"{self.base_url}/?database={urllib.parse.quote(self.database)}"
            "&date_time_input_format=best_effort"
        )
        return self.http.request("POST", url, sql.encode("utf-8"), {"Content-Type": "text/plain"})

    def health(self) -> Dict[str, Any]:
        ping = self.http.request("GET", f"{self.base_url}/ping")
        return {"ok": ping.ok and "Ok" in ping.body, "status": ping.status, "latency_ms": ping.elapsed_ms, "error": ping.error}

    def setup_schema(self) -> Dict[str, Any]:
        statements = [
            f"CREATE DATABASE IF NOT EXISTS {self.database}",
            """
            CREATE TABLE IF NOT EXISTS raw_telemetry (
                ts DateTime64(3),
                event_id String,
                topic String,
                event_source String,
                telemetry_type String,
                raw String
            ) ENGINE = MergeTree ORDER BY (ts, event_source, telemetry_type)
            TTL toDateTime(ts) + INTERVAL 30 DAY
            """,
            """
            CREATE TABLE IF NOT EXISTS normalized_telemetry (
                ts DateTime64(3),
                event_id String,
                telemetry_type String,
                event_type String,
                user String,
                host String,
                source_ip String,
                destination_ip String,
                domain String,
                risk_score Float64,
                payload String
            ) ENGINE = MergeTree ORDER BY (ts, telemetry_type, event_type)
            TTL toDateTime(ts) + INTERVAL 90 DAY
            """,
            """
            CREATE TABLE IF NOT EXISTS xdr_pipeline_metrics (
                measured_at DateTime64(3),
                metric_type String,
                topic String,
                value Float64,
                metadata String
            ) ENGINE = MergeTree ORDER BY (measured_at, metric_type, topic)
            TTL toDateTime(measured_at) + INTERVAL 180 DAY
            """,
            # ARCH-DB-SPLIT: mirrors Postgres's telemetry_events column set
            # (see database/migrations/2026_05_11_000009_create_telemetry_events_table.php
            # + 2026_05_12_000012_add_xdr_telemetry_and_incident_fields.php) so the
            # ClickHouse write path is a drop-in target, not a redesign. Adds
            # tenant_id from day one (Postgres's telemetry_events never got one —
            # see DETECT-BACKTEST-TENANCY in REVIEW_REJECTED.md) as a leading
            # ORDER BY key, ahead of host_id (the leading key of the two
            # single-host point-lookup read paths, SocEndpointTimelineController/
            # SocForensicController — a future read-path migration keeps its
            # existing (host_id, ts) access pattern fast this way).
            #
            # ReplacingMergeTree(inserted_at) + event_id as a trailing ORDER BY
            # key gives *eventual* (background-merge-time) dedup on exact
            # (tenant_id, host_id, ts, event_id) duplicates — the closest
            # available approximation of Postgres's ON CONFLICT (event_id) DO
            # NOTHING, but NOT the same guarantee: a duplicate row can still be
            # visible to a plain SELECT until the next merge (or a query using
            # FINAL, which costs real read performance). ingest_telemetry_events.py's
            # own offset-file tracking remains the primary defense against
            # re-processing; this is a weaker backstop than Postgres's, not an
            # equivalent one — documented here rather than silently assumed away.
            """
            CREATE TABLE IF NOT EXISTS telemetry_events (
                ts DateTime64(3),
                event_id String,
                tenant_id String DEFAULT '',
                telemetry_type LowCardinality(String),
                event_type LowCardinality(String),
                host_id String DEFAULT '',
                src_ip String DEFAULT '',
                dst_ip String DEFAULT '',
                dst_port Int32 DEFAULT 0,
                protocol String DEFAULT '',
                process_name String DEFAULT '',
                user_name_hash String DEFAULT '',
                xdr_user String DEFAULT '',
                xdr_host String DEFAULT '',
                source_ip String DEFAULT '',
                destination_ip String DEFAULT '',
                domain String DEFAULT '',
                file_hash String DEFAULT '',
                email_sender String DEFAULT '',
                email_recipient String DEFAULT '',
                cloud_account String DEFAULT '',
                xdr_action String DEFAULT '',
                xdr_result String DEFAULT '',
                risk_score Float64 DEFAULT 0,
                event_source String DEFAULT '',
                payload String,
                inserted_at DateTime64(3) DEFAULT now64(3)
            ) ENGINE = ReplacingMergeTree(inserted_at)
            ORDER BY (tenant_id, host_id, ts, event_id)
            TTL toDateTime(ts) + INTERVAL 30 DAY
            """,
            # DATA-TIERING (warm tier): the real, indexed, months-scale
            # searchable tier the phase 1/2 local gzip archive
            # (SecurityRetentionArchiveService/ArchiveSearchService) was
            # always documented as not being. One generic table across all
            # 3 archived source tables (security_events/security_alerts/
            # security_incidents) rather than one ClickHouse table per
            # source -- their column shapes differ, so `payload` carries the
            # full original row as JSON (identical to what the gzip archive
            # already stores) while `source_table`/`tenant_id`/`archived_at`
            # are promoted to real, indexed columns for fast range/tenant/
            # table-scoped queries -- the exact three dimensions
            # ArchiveSearchService's linear gzip scan already filters on, now
            # actually indexed instead of grepped. `record_id` kept as
            # String (not UInt64) since it's used purely for identification/
            # de-dup, never arithmetic, and callers already have it as a
            # loosely-typed value from `(array) $row['id']`. No TTL here --
            # this warm tier's own retention is a separate, later policy
            # decision, not implicitly inherited from the hot tables' TTLs.
            """
            CREATE TABLE IF NOT EXISTS archived_records (
                source_table LowCardinality(String),
                tenant_id String DEFAULT '',
                record_id String,
                original_ts DateTime64(3),
                archived_at DateTime64(3) DEFAULT now64(3),
                payload String
            ) ENGINE = MergeTree
            ORDER BY (source_table, tenant_id, original_ts, record_id)
            """,
        ]
        results = [self.query(stmt) for stmt in statements]
        return {"ok": all(r.ok for r in results), "statuses": [r.status for r in results], "errors": [r.error for r in results if not r.ok]}

    def insert_json_each_row(self, table: str, rows: List[Dict[str, Any]]) -> HttpResult:
        if not rows:
            return HttpResult(True, 200, 0.0, "empty")
        body = "\n".join(json.dumps(row, separators=(",", ":"), ensure_ascii=False) for row in rows)
        return self.query(f"INSERT INTO {table} FORMAT JSONEachRow\n{body}")


class OpenSearchClient:
    def __init__(self, base_url: str, user: str = "", password: str = "", timeout: float = 5.0):
        headers = {}
        if user:
            headers["Authorization"] = "Basic " + base64.b64encode(f"{user}:{password}".encode()).decode()
        self.base_url = base_url.rstrip("/")
        self.http = HttpClient(timeout=timeout, retries=2, headers=headers)

    def health(self) -> Dict[str, Any]:
        res = self.http.request("GET", f"{self.base_url}/_cluster/health")
        return {"ok": res.ok, "status": res.status, "latency_ms": res.elapsed_ms, "body": safe_json(res.body), "error": res.error}

    def setup_indexes(self) -> Dict[str, Any]:
        template = {
            "index_patterns": ["xdr-*"],
            "template": {
                "settings": {"number_of_shards": 1, "number_of_replicas": 0},
                "mappings": {
                    "properties": {
                        "ts": {"type": "date"},
                        "telemetry_type": {"type": "keyword"},
                        "event_type": {"type": "keyword"},
                        "user": {"type": "keyword"},
                        "host": {"type": "keyword"},
                        "domain": {"type": "keyword"},
                        "source_ip": {"type": "ip", "ignore_malformed": True},
                        "risk_score": {"type": "float"},
                        "payload": {"type": "object", "enabled": False},
                    }
                },
            },
        }
        res = self.http.request("PUT", f"{self.base_url}/_index_template/xdr-template", template)
        return {"ok": res.ok, "status": res.status, "error": res.error or res.body[:300]}

    def index_many(self, index: str, docs: List[Dict[str, Any]]) -> HttpResult:
        if not docs:
            return HttpResult(True, 200, 0.0, "empty")
        lines: List[str] = []
        for doc in docs:
            doc_id = doc.get("event_id") or hashlib.sha256(json.dumps(doc, sort_keys=True).encode()).hexdigest()
            lines.append(json.dumps({"index": {"_index": index, "_id": doc_id}}, separators=(",", ":")))
            lines.append(json.dumps(doc, separators=(",", ":"), ensure_ascii=False))
        return self.http.request("POST", f"{self.base_url}/_bulk", ("\n".join(lines) + "\n").encode("utf-8"), {"Content-Type": "application/x-ndjson"})

    def search(self, index: str, query: Dict[str, Any]) -> HttpResult:
        return self.http.request("GET", f"{self.base_url}/{index}/_search", query)


class QdrantClient:
    def __init__(self, base_url: str, collection: str, vector_size: int = 384, timeout: float = 5.0,
                 verify_tls: bool = True, ca_cert: str = ""):
        self.base_url = base_url.rstrip("/")
        self.collection = collection
        self.vector_size = vector_size
        self.http = HttpClient(
            timeout=timeout,
            retries=2,
            ssl_context=tls_context_for_url(self.base_url, verify_tls, ca_cert),
        )

    def health(self) -> Dict[str, Any]:
        res = self.http.request("GET", f"{self.base_url}/")
        return {"ok": res.ok, "status": res.status, "latency_ms": res.elapsed_ms, "body": safe_json(res.body), "error": res.error}

    def setup_collection(self) -> Dict[str, Any]:
        body = {"vectors": {"size": self.vector_size, "distance": "Cosine"}}
        res = self.http.request("PUT", f"{self.base_url}/collections/{urllib.parse.quote(self.collection)}", body)
        return {"ok": res.ok or res.status == 409, "status": res.status, "error": res.error or res.body[:300]}

    def upsert_texts(self, items: List[Dict[str, Any]]) -> HttpResult:
        points = []
        for item in items:
            text = str(item.get("text") or json.dumps(item, sort_keys=True))
            points.append({
                "id": stable_int_id(str(item.get("id") or text)),
                "vector": pseudo_embedding(text, self.vector_size),
                "payload": item,
            })
        return self.http.request("PUT", f"{self.base_url}/collections/{urllib.parse.quote(self.collection)}/points", {"points": points})

    def search_text(self, text: str, limit: int = 5) -> HttpResult:
        return self.http.request(
            "POST",
            f"{self.base_url}/collections/{urllib.parse.quote(self.collection)}/points/search",
            {"vector": pseudo_embedding(text, self.vector_size), "limit": limit, "with_payload": True},
        )


def pseudo_embedding(text: str, size: int) -> List[float]:
    digest = hashlib.sha256(text.encode()).digest()
    values = []
    for idx in range(size):
        byte = digest[idx % len(digest)]
        values.append((byte / 127.5) - 1.0)
    return values


def stable_int_id(text: str) -> int:
    return int(hashlib.sha256(text.encode()).hexdigest()[:15], 16)


def safe_json(text: str) -> Any:
    try:
        return json.loads(text)
    except Exception:
        return text[:300]


def write_dlq(topic: str, event: Dict[str, Any], error: str) -> None:
    path = Path("storage/streams") / f"{topic}.dlq.jsonl"
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("a", encoding="utf-8") as f:
        f.write(json.dumps({"topic": topic, "error": error, "event": event, "ts": time.time()}, separators=(",", ":"), ensure_ascii=False) + "\n")


def load_jsonl(path: Path) -> List[Dict[str, Any]]:
    if not path.exists():
        return []
    rows = []
    with path.open("r", encoding="utf-8") as f:
        for line in f:
            if line.strip():
                rows.append(json.loads(line))
    return rows


def clients_from_env() -> Tuple[RedpandaClient, ClickHouseClient, OpenSearchClient, QdrantClient]:
    redpanda = RedpandaClient(env("XDR_REDPANDA_REST_URL", env("KAFKA_REST_URL", "http://127.0.0.1:8082")), float(env("XDR_REDPANDA_TIMEOUT_SECONDS", "5")))
    clickhouse = ClickHouseClient(
        env("XDR_CLICKHOUSE_HTTP_URL", env("CLICKHOUSE_HTTP_URL", "http://127.0.0.1:8123")),
        env("XDR_CLICKHOUSE_DB", env("CLICKHOUSE_DB", "detector_analytics")),
        env("XDR_CLICKHOUSE_USER", env("CLICKHOUSE_USER", "detector")),
        env("XDR_CLICKHOUSE_PASSWORD", env("CLICKHOUSE_PASSWORD", "detector")),
        float(env("XDR_CLICKHOUSE_TIMEOUT_SECONDS", "5")),
        env_bool("XDR_CLICKHOUSE_VERIFY_TLS", True),
        env("XDR_CLICKHOUSE_CA_CERT", ""),
    )
    opensearch = OpenSearchClient(
        env("XDR_OPENSEARCH_URL", "http://127.0.0.1:9200"),
        env("XDR_OPENSEARCH_USER", ""),
        env("XDR_OPENSEARCH_PASSWORD", ""),
        float(env("XDR_OPENSEARCH_TIMEOUT_SECONDS", "5")),
    )
    qdrant = QdrantClient(
        env("XDR_QDRANT_URL", env("SOC_QDRANT_BASE_URL", "http://127.0.0.1:6333")),
        env("XDR_QDRANT_COLLECTION", env("SOC_QDRANT_COLLECTION", "soc_knowledge")),
        int(env("XDR_QDRANT_VECTOR_SIZE", "384")),
        float(env("XDR_QDRANT_TIMEOUT_SECONDS", "5")),
        env_bool("XDR_QDRANT_VERIFY_TLS", True),
        env("XDR_QDRANT_CA_CERT", ""),
    )
    return redpanda, clickhouse, opensearch, qdrant
