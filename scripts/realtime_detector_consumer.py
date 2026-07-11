#!/usr/bin/env python3
"""
Near-real-time detector consumer via Redpanda Pandaproxy REST.

Consumes topic records using consumer groups and writes alerts to Postgres.
No external Python package required.
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import hmac
import json
import os
import pickle
import time
import urllib.error
import urllib.parse
import urllib.request
from collections import defaultdict, deque
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Deque, Dict, List, Optional, Set, Tuple

from security_event_contract import validate_event


@dataclass
class RuleThresholds:
    brute_force_30s: int = 12
    stuffing_30s: int = 15
    stuffing_unique_emails_30s: int = 8
    scan_30s_404: int = 20
    scan_30s_unique_paths: int = 20


DETECTOR_NAME = "realtime"
DETECTOR_VERSION = "v2-idempotent-windowed"

# ENT-DETECT-ML-NOT-LIVE: this detector scores HTTP-request features
# (status/latency_ms/has_sql_keywords/...) captured from SecurityRequestLogger's
# security_events — a materially different domain from correlation-worker's
# identity/cloud/SaaS telemetry, and not currently soak-validated for active
# promotion. --output-mode defaults to "shadow": findings go to advisory_findings
# only (never security_alerts/security_responses, no auto-promotion), matching
# the platform's existing shadow-alert-consumer pattern
# (services/alert-writer-service/main.py's shadow_event_loop). "active" mode
# preserves the original direct-to-security_alerts behavior for operators who
# have already completed a domain-specific 6h soak PASS per CLAUDE.md.
SHADOW_DOMAIN = "web_request"
SHADOW_PROMOTION_CONFIDENCE_THRESHOLD = 0.75

MITRE_BY_ALERT = {
    "BRUTE_FORCE_IP": [{"tactic": "Credential Access", "technique": "T1110", "name": "Brute Force"}],
    "CREDENTIAL_STUFFING": [{"tactic": "Credential Access", "technique": "T1110.004", "name": "Credential Stuffing"}],
    "SCAN_BURST": [{"tactic": "Reconnaissance", "technique": "T1595", "name": "Active Scanning"}],
    "INJECTION_INDICATOR": [{"tactic": "Initial Access", "technique": "T1190", "name": "Exploit Public-Facing Application"}],
    "PRIVILEGE_PROBING": [{"tactic": "Discovery", "technique": "T1087", "name": "Account Discovery"}],
    "ML_BRUTEFORCE": [{"tactic": "Credential Access", "technique": "T1110", "name": "Brute Force"}],
    "ML_SCAN": [{"tactic": "Reconnaissance", "technique": "T1595", "name": "Active Scanning"}],
    "ML_INJECTION": [{"tactic": "Initial Access", "technique": "T1190", "name": "Exploit Public-Facing Application"}],
    "ANOMALY_BEHAVIOR": [{"tactic": "Defense Evasion", "technique": "T1027", "name": "Obfuscated/Compressed Files and Information"}],
    "LOW_AND_SLOW_SCAN": [{"tactic": "Reconnaissance", "technique": "T1595", "name": "Active Scanning"}],
    "DISTRIBUTED_BRUTE_FORCE": [{"tactic": "Credential Access", "technique": "T1110", "name": "Brute Force"}],
    "EXPLOIT_CHAIN_SUSPECTED": [{"tactic": "Initial Access", "technique": "T1190", "name": "Exploit Public-Facing Application"}],
    "PRIVILEGE_ESCALATION_SUSPECTED": [{"tactic": "Privilege Escalation", "technique": "T1068", "name": "Exploitation for Privilege Escalation"}],
    "C2_BEACON_PATTERN": [{"tactic": "Command and Control", "technique": "T1071", "name": "Application Layer Protocol"}],
    "PERSISTENCE_INDICATOR": [{"tactic": "Persistence", "technique": "T1053", "name": "Scheduled Task/Job"}],
    "LATERAL_MOVEMENT_SUSPECTED": [{"tactic": "Lateral Movement", "technique": "T1021", "name": "Remote Services"}],
    "INTERNAL_RECON_SUSPECTED": [{"tactic": "Discovery", "technique": "T1046", "name": "Network Service Discovery"}],
    "SANDBOX_EVASION_INDICATOR": [{"tactic": "Defense Evasion", "technique": "T1497", "name": "Virtualization/Sandbox Evasion"}],
    "C2_DNS_BEACON_PATTERN": [{"tactic": "Command and Control", "technique": "T1071.004", "name": "DNS"}],
}


def mitre_for_alert(alert_type: str) -> List[Dict[str, str]]:
    return list(MITRE_BY_ALERT.get(alert_type, []))


def load_thresholds(path: Path) -> RuleThresholds:
    if not path.exists():
        return RuleThresholds()
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return RuleThresholds()
    if not isinstance(data, dict):
        return RuleThresholds()
    return RuleThresholds(
        brute_force_30s=int(data.get("brute_force_30s", 12)),
        stuffing_30s=int(data.get("stuffing_30s", 15)),
        stuffing_unique_emails_30s=int(data.get("stuffing_unique_emails_30s", 8)),
        scan_30s_404=int(data.get("scan_30s_404", 20)),
        scan_30s_unique_paths=int(data.get("scan_30s_unique_paths", 20)),
    )


def load_allowlist(path: Path) -> Set[str]:
    if not path.exists():
        return set()
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return set()
    if isinstance(data, dict):
        ips = data.get("ips", [])
    else:
        ips = data
    out: Set[str] = set()
    if isinstance(ips, list):
        for x in ips:
            if isinstance(x, str) and x.strip():
                out.add(x.strip())
    return out


def load_correlation_config(path: Path) -> Dict[str, Any]:
    defaults = {
        "enabled": True,
        "window_minutes": 10,
        "distributed_window_seconds": 120,
        "low_slow_min_paths": 12,
        "low_slow_min_404": 6,
        "distributed_min_failures": 20,
        "distributed_min_ips": 3,
        "exploit_chain_min_stages": 3,
        "beacon_min_hits": 6,
        "beacon_max_interval_cv": 0.35,
    }
    if not path.exists():
        return defaults
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return defaults
    if not isinstance(data, dict):
        return defaults
    out = dict(defaults)
    out.update(data)
    return out


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Realtime detector consumer (REST, rules + ML).")
    parser.add_argument("--rest-url", default=os.getenv("KAFKA_REST_URL", "http://127.0.0.1:8082"))
    parser.add_argument("--topic", default=os.getenv("KAFKA_TOPIC", "security_events"))
    parser.add_argument("--group-id", default=os.getenv("KAFKA_GROUP_ID", "detector-realtime-v1"))
    parser.add_argument("--instance-id", default=f"detector-{int(time.time())}")
    parser.add_argument("--offset-reset", choices=["earliest"], default="earliest")
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--model", default="storage/app/ai_detector_model.pkl")
    parser.add_argument("--anomaly-profile", default="storage/app/anomaly_profile.json")
    parser.add_argument("--enable-anomaly", type=int, choices=[0, 1], default=1)
    parser.add_argument("--detection-mode", choices=["current", "advanced"], default=os.getenv("DETECTION_MODE", "current"))
    parser.add_argument("--correlation-file", default="storage/app/detector_correlation.json")
    parser.add_argument("--thresholds-file", default="storage/app/detector_thresholds.json")
    parser.add_argument("--allowlist-file", default="storage/app/detector_allowlist.json")
    parser.add_argument("--response-mode", choices=["off", "recommend", "auto"], default=os.getenv("RESPONSE_MODE", "recommend"))
    parser.add_argument("--response-policy-dir", default=os.getenv("RESPONSE_POLICY_DIR", "storage/app/response"))
    parser.add_argument(
        "--use-active-deployment",
        type=int,
        choices=[0, 1],
        default=1 if os.getenv("ML_USE_ACTIVE_DEPLOYMENT", "1") == "1" else 0,
    )
    parser.add_argument("--deployment-env", default=os.getenv("ML_DEPLOYMENT_ENV", "local"))
    parser.add_argument("--model-lock-sha256", default=os.getenv("ML_ALLOWED_ARTIFACT_SHA256", ""))
    parser.add_argument(
        "--require-lock",
        type=int,
        choices=[0, 1],
        default=1 if os.getenv("ML_DEPLOYMENT_LOCK", "1") == "1" else 0,
    )
    parser.add_argument("--app-key", default=os.getenv("APP_KEY", "demo-alert-key"))
    parser.add_argument("--poll-interval-ms", type=int, default=800)
    parser.add_argument("--max-empty-polls", type=int, default=0, help="0 means run forever")
    parser.add_argument(
        "--output-mode",
        choices=["shadow", "active"],
        default=os.getenv("DETECTOR_OUTPUT_MODE", "shadow"),
        help=(
            "shadow (default): write advisory_findings only — never security_alerts or "
            "security_responses, no auto-promotion. active: write directly to "
            "security_alerts/security_responses (pre-existing behavior; requires a "
            "domain-specific 6h soak PASS before production use per CLAUDE.md)."
        ),
    )
    return parser.parse_args()


def parse_env_file(path: Path) -> Dict[str, str]:
    values: Dict[str, str] = {}
    if not path.exists():
        return values
    for line in path.read_text(encoding="utf-8").splitlines():
        s = line.strip()
        if not s or s.startswith("#") or "=" not in s:
            continue
        k, v = s.split("=", 1)
        values[k.strip()] = v.strip().strip('"').strip("'")
    return values


def build_dsn_from_env(project_root: Path) -> str:
    env = parse_env_file(project_root / ".env")
    if env.get("DB_CONNECTION") != "pgsql":
        return ""
    return (
        f"host={env.get('DB_HOST','127.0.0.1')} "
        f"port={env.get('DB_PORT','5432')} "
        f"dbname={env.get('DB_DATABASE','detector')} "
        f"user={env.get('DB_USERNAME','postgres')} "
        f"password={env.get('DB_PASSWORD','postgres')}"
    )


def parse_ts(value: Optional[str]) -> datetime:
    if not value:
        return datetime.now(timezone.utc)
    text = value.replace("Z", "+00:00")
    try:
        dt = datetime.fromisoformat(text)
        if dt.tzinfo is None:
            return dt.replace(tzinfo=timezone.utc)
        return dt
    except ValueError:
        return datetime.now(timezone.utc)


def as_int(value: Any) -> Optional[int]:
    if value is None:
        return None
    try:
        return int(value)
    except Exception:
        return None


def as_bool(value: Any) -> bool:
    if isinstance(value, bool):
        return value
    if value is None:
        return False
    return str(value).strip().lower() in {"1", "true", "t", "yes", "y"}


def hmac_hex(secret: str, text: str) -> str:
    key: bytes
    if secret.startswith("base64:"):
        try:
            key = base64.b64decode(secret[7:])
        except Exception:
            key = secret.encode("utf-8")
    else:
        key = secret.encode("utf-8")
    return hmac.new(key, text.encode("utf-8"), hashlib.sha256).hexdigest()


def floor_to_window(ts: datetime, seconds: int) -> datetime:
    epoch = int(ts.timestamp())
    floored = epoch - (epoch % max(1, seconds))
    return datetime.fromtimestamp(floored, tz=ts.tzinfo or timezone.utc)


def window_for_alert(alert_type: str, event_ts: datetime) -> Tuple[datetime, datetime]:
    window_sec = 30
    if alert_type in {"INJECTION_INDICATOR", "PRIVILEGE_PROBING"}:
        window_sec = 10
    start = floor_to_window(event_ts, window_sec)
    end = start + timedelta(seconds=window_sec)
    return start, end


def threshold_profile_hash(thr: RuleThresholds) -> str:
    canonical = (
        f"bf={thr.brute_force_30s}|"
        f"st={thr.stuffing_30s}|"
        f"sue={thr.stuffing_unique_emails_30s}|"
        f"s404={thr.scan_30s_404}|"
        f"sup={thr.scan_30s_unique_paths}"
    )
    return hashlib.sha256(canonical.encode("utf-8")).hexdigest()


def http_json(method: str, url: str, body: Optional[Dict[str, Any]] = None, headers: Optional[Dict[str, str]] = None):
    data = None
    if body is not None:
        data = json.dumps(body, separators=(",", ":")).encode("utf-8")
    req = urllib.request.Request(url=url, method=method, data=data)
    merged = headers or {}
    for k, v in merged.items():
        req.add_header(k, v)
    with urllib.request.urlopen(req, timeout=20) as resp:
        text = resp.read().decode("utf-8")
        if not text:
            return None
        return json.loads(text)


def consumer_create(rest_url: str, group_id: str, instance_id: str, offset_reset: str) -> str:
    url = f"{rest_url.rstrip('/')}/consumers/{urllib.parse.quote(group_id)}"
    payload = {
        "name": instance_id,
        "format": "json",
        "auto.offset.reset": offset_reset,
    }
    data = http_json(
        "POST",
        url,
        payload,
        headers={
            "Content-Type": "application/vnd.kafka.v2+json",
            "Accept": "application/vnd.kafka.v2+json",
        },
    )
    if not isinstance(data, dict) or "base_uri" not in data:
        raise RuntimeError("Failed to create consumer instance")
    return str(data["base_uri"])


def consumer_subscribe(base_uri: str, topic: str) -> None:
    http_json(
        "POST",
        f"{base_uri}/subscription",
        {"topics": [topic]},
        headers={
            "Content-Type": "application/vnd.kafka.v2+json",
            "Accept": "application/vnd.kafka.v2+json",
        },
    )


def consumer_poll(base_uri: str, timeout_ms: int) -> List[Dict[str, Any]]:
    url = f"{base_uri}/records?timeout={timeout_ms}&max_bytes=1048576"
    data = http_json(
        "GET",
        url,
        None,
        headers={"Accept": "application/vnd.kafka.json.v2+json"},
    )
    if data is None:
        return []
    if isinstance(data, list):
        return [d for d in data if isinstance(d, dict)]
    return []


def consumer_delete(base_uri: str) -> None:
    try:
        http_json(
            "DELETE",
            base_uri,
            None,
            headers={"Accept": "application/vnd.kafka.v2+json"},
        )
    except Exception:
        pass


def connect_db(dsn: str):
    try:
        import psycopg  # type: ignore

        return "psycopg3", psycopg.connect(dsn)
    except Exception:
        import psycopg2  # type: ignore

        return "psycopg2", psycopg2.connect(dsn)


def file_sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        while True:
            b = f.read(8192)
            if not b:
                break
            h.update(b)
    return h.hexdigest()


def fetch_active_deployment(conn: Any, env_name: str) -> Optional[Dict[str, Any]]:
    sql = """
    SELECT d.id, d.lock_enabled, d.expected_artifact_sha256, m.id, m.model_key, m.artifact_path, m.artifact_sha256
    FROM ml_model_deployments d
    JOIN ml_models m ON m.id = d.model_id
    WHERE d.environment = %s AND d.is_active = true
    ORDER BY d.deployed_at DESC
    LIMIT 1
    """
    with conn.cursor() as cur:
        cur.execute(sql, (env_name,))
        row = cur.fetchone()
    if row is None:
        return None
    return {
        "deployment_id": int(row[0]),
        "lock_enabled": bool(row[1]),
        "expected_artifact_sha256": str(row[2] or ""),
        "model_id": int(row[3]),
        "model_key": str(row[4]),
        "artifact_path": str(row[5]),
        "artifact_sha256": str(row[6]),
    }


def maybe_load_model(path: Path) -> Optional[Dict[str, Any]]:
    if not path.exists():
        return None
    try:
        with path.open("rb") as f:
            obj = pickle.load(f)
        if isinstance(obj, dict) and "weights" in obj and "vectorizer" in obj and "classes" in obj:
            return obj
    except Exception:
        return None
    return None


def maybe_load_anomaly_profile(path: Path) -> Optional[Dict[str, Any]]:
    if not path.exists():
        return None
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return None
    if not isinstance(data, dict):
        return None
    if data.get("profile_type") != "robust_behavioral_baseline":
        return None
    if not isinstance(data.get("feature_stats"), dict):
        return None
    return data


def vectorize_for_model(event: Dict[str, Any], ip_features: Dict[str, Any], vcfg: Dict[str, Any]) -> List[float]:
    dim = int(vcfg["dim"])
    vec = [0.0] * dim
    offset = 0

    for col in vcfg["categorical"]:
        mapping = vcfg["cat_maps"][col]
        value = str(event.get(col, "") or "")
        if value in mapping:
            vec[offset + mapping[value]] = 1.0
        offset += len(mapping)

    numeric_map = {
        "status": as_int(event.get("status")) or 0,
        "latency_ms": as_int(event.get("latency_ms")) or 0,
        "has_sql_keywords": 1 if as_bool(event.get("has_sql_keywords")) else 0,
        "has_script_payload": 1 if as_bool(event.get("has_script_payload")) else 0,
        "path_len": len(str(event.get("path", "") or "")),
        "path_depth": str(event.get("path", "") or "").count("/"),
        "is_admin_path": 1 if str(event.get("path", "") or "") == "/admin" else 0,
        "is_scan_like_path": 1 if str(event.get("path", "") or "").startswith("/scan/") else 0,
        "is_sensitive_probe": 1
        if str(event.get("path", "") or "") in {"/.env", "/phpMyAdmin", "/wp-admin", "/vendor"}
        else 0,
        "failed_1m": ip_features.get("failed_1m", 0),
        "failed_5m": ip_features.get("failed_5m", 0),
        "failed_10m": ip_features.get("failed_10m", 0),
        "unique_email_10m": ip_features.get("unique_email_10m", 0),
        "req_1m": ip_features.get("req_1m", 0),
        "req_5m": ip_features.get("req_5m", 0),
        "notfound_2m": ip_features.get("notfound_2m", 0),
        "unique_paths_2m": ip_features.get("unique_paths_2m", 0),
    }

    for i, col in enumerate(vcfg["numeric"]):
        val = float(numeric_map.get(col, 0))
        mu = float(vcfg["means"][col])
        sd = float(vcfg["stds"][col]) if float(vcfg["stds"][col]) > 0 else 1.0
        vec[offset + i] = (val - mu) / sd
    return vec


def predict_model(model: Dict[str, Any], x: List[float]) -> Tuple[str, float]:
    W = model["weights"]
    b = model["bias"]
    classes = model["classes"]
    decision_thresholds = model.get("decision_thresholds", {})
    logits = []
    for k in range(len(W)):
        s = b[k]
        row = W[k]
        for j in range(len(x)):
            s += row[j] * x[j]
        logits.append(s)

    max_logit = max(logits)
    exps = [pow(2.718281828, v - max_logit) for v in logits]
    total = sum(exps) if exps else 1.0
    probs = [e / total for e in exps]
    idx = max(range(len(probs)), key=lambda i: probs[i])
    label = str(classes[idx])
    score = float(probs[idx])
    if label != "normal" and isinstance(decision_thresholds, dict):
        threshold = float(decision_thresholds.get(label, 0.0) or 0.0)
        if score < threshold:
            return "normal", score
    return label, score


def should_suppress_ml_prediction(pred_label: str, event: Dict[str, Any], alerts: List[Tuple[str, str, float]]) -> bool:
    """Prefer explicit injection evidence over a conflicting scan model label."""
    alert_names = {alert_type for alert_type, _severity, _score in alerts}
    has_injection_payload = as_bool(event.get("has_sql_keywords")) or as_bool(event.get("has_script_payload"))
    if pred_label == "scan" and ("INJECTION_INDICATOR" in alert_names or has_injection_payload):
        return True
    return False


def anomaly_features(event: Dict[str, Any], ip_features: Dict[str, Any]) -> Dict[str, float]:
    path = str(event.get("path", "") or "")
    return {
        "status": float(as_int(event.get("status")) or 0),
        "latency_ms": float(as_int(event.get("latency_ms")) or 0),
        "path_len": float(len(path)),
        "path_depth": float(path.count("/")),
        "failed_1m": float(ip_features.get("failed_1m", 0) or 0),
        "failed_5m": float(ip_features.get("failed_5m", 0) or 0),
        "failed_10m": float(ip_features.get("failed_10m", 0) or 0),
        "unique_email_10m": float(ip_features.get("unique_email_10m", 0) or 0),
        "req_1m": float(ip_features.get("req_1m", 0) or 0),
        "req_5m": float(ip_features.get("req_5m", 0) or 0),
        "notfound_2m": float(ip_features.get("notfound_2m", 0) or 0),
        "unique_paths_2m": float(ip_features.get("unique_paths_2m", 0) or 0),
    }


def evaluate_anomaly(
    event: Dict[str, Any],
    ip_features: Dict[str, Any],
    profile: Optional[Dict[str, Any]],
) -> Optional[Tuple[str, str, float, Dict[str, Any]]]:
    if profile is None:
        return None
    stats = profile.get("feature_stats")
    scoring = profile.get("scoring")
    if not isinstance(stats, dict) or not isinstance(scoring, dict):
        return None

    features = anomaly_features(event, ip_features)
    contributions = []
    for name, value in features.items():
        st = stats.get(name)
        if not isinstance(st, dict):
            continue
        median_value = float(st.get("median", 0.0) or 0.0)
        mad = float(st.get("mad", 1.0) or 1.0)
        scale = max(mad * 1.4826, 1.0)
        robust_z = abs(value - median_value) / scale
        contributions.append((name, value, robust_z))

    if not contributions:
        return None

    contributions.sort(key=lambda x: x[2], reverse=True)
    score = sum(x[2] for x in contributions[:3]) / min(3, len(contributions))
    threshold = float(scoring.get("threshold", 3.5) or 3.5)
    if score < threshold:
        return None

    evidence = {
        "anomaly_score": round(float(score), 6),
        "threshold": threshold,
        "top_contributors": [
            {"feature": name, "value": value, "robust_z": round(float(robust_z), 6)}
            for name, value, robust_z in contributions[:5]
        ],
    }
    severity = "high" if score >= threshold * 1.5 else "medium"
    normalized = min(1.0, score / max(threshold * 2.0, 1.0))
    return ("ANOMALY_BEHAVIOR", severity, normalized, evidence)


class RealtimeState:
    def __init__(self) -> None:
        self.failed_logins: Dict[str, Deque[Tuple[datetime, str]]] = defaultdict(deque)
        self.req_events: Dict[str, Deque[Tuple[datetime, str, Optional[int], str]]] = defaultdict(deque)

    def update(self, event: Dict[str, Any]) -> Dict[str, Any]:
        ip = str(event.get("ip", "") or "")
        ts = parse_ts(event.get("ts"))
        event_type = str(event.get("event", event.get("event_type", "")) or "")
        status = as_int(event.get("status"))
        path = str(event.get("path", "") or "")
        email_hash = str(event.get("email_hash", "") or "")

        if event_type == "auth_login_failed":
            self.failed_logins[ip].append((ts, email_hash))
        self.req_events[ip].append((ts, event_type, status, path))

        self._trim(ip, ts)
        return self._snapshot(ip, ts)

    def _trim(self, ip: str, now_ts: datetime) -> None:
        cutoff_10m = now_ts - timedelta(minutes=10)
        while self.failed_logins[ip] and self.failed_logins[ip][0][0] < cutoff_10m:
            self.failed_logins[ip].popleft()
        cutoff_5m = now_ts - timedelta(minutes=5)
        while self.req_events[ip] and self.req_events[ip][0][0] < cutoff_5m:
            self.req_events[ip].popleft()

    def _snapshot(self, ip: str, now_ts: datetime) -> Dict[str, Any]:
        failed = self.failed_logins[ip]
        reqs = self.req_events[ip]
        return {
            "failed_1m": sum(1 for t, _ in failed if t >= now_ts - timedelta(minutes=1)),
            "failed_5m": sum(1 for t, _ in failed if t >= now_ts - timedelta(minutes=5)),
            "failed_10m": len(failed),
            "unique_email_10m": len({e for _, e in failed if e}),
            "req_1m": sum(1 for t, _, _, _ in reqs if t >= now_ts - timedelta(minutes=1)),
            "req_5m": len(reqs),
            "notfound_2m": sum(
                1 for t, e, s, _ in reqs if t >= now_ts - timedelta(minutes=2) and e == "http_request" and s == 404
            ),
            "unique_paths_2m": len({p for t, _, _, p in reqs if t >= now_ts - timedelta(minutes=2) and p}),
            "failed_30s": sum(1 for t, _ in failed if t >= now_ts - timedelta(seconds=30)),
            "unique_email_30s": len({e for t, e in failed if t >= now_ts - timedelta(seconds=30) and e}),
            "notfound_30s": sum(
                1
                for t, e, s, _ in reqs
                if t >= now_ts - timedelta(seconds=30) and e == "http_request" and s == 404
            ),
            "unique_paths_30s": len({p for t, _, _, p in reqs if t >= now_ts - timedelta(seconds=30) and p}),
        }


class ThreatCorrelationState:
    def __init__(self, cfg: Dict[str, Any]) -> None:
        self.cfg = cfg
        self.window = timedelta(minutes=int(cfg.get("window_minutes", 10) or 10))
        self.ip_events: Dict[str, Deque[Tuple[datetime, str, str, Optional[int], Optional[int]]]] = defaultdict(deque)
        self.ip_alerts: Dict[str, Deque[Tuple[datetime, str]]] = defaultdict(deque)
        self.failed_global: Deque[Tuple[datetime, str, str]] = deque()

    def update(
        self,
        event: Dict[str, Any],
        event_ts: datetime,
        alert_types: List[str],
    ) -> List[Tuple[str, str, float, Dict[str, Any]]]:
        ip = str(event.get("ip", "") or "")
        event_type = str(event.get("event", event.get("event_type", "")) or "")
        path = str(event.get("path", "") or "")
        status = as_int(event.get("status"))
        user_id = as_int(event.get("user_id"))
        email_hash = str(event.get("email_hash", "") or "")

        self.ip_events[ip].append((event_ts, event_type, path, status, user_id))
        for alert_type in alert_types:
            self.ip_alerts[ip].append((event_ts, alert_type))
        if event_type == "auth_login_failed":
            self.failed_global.append((event_ts, ip, email_hash))

        self._trim(ip, event_ts)
        return self._evaluate(ip, event_ts)

    def _trim(self, ip: str, now_ts: datetime) -> None:
        cutoff = now_ts - self.window
        while self.ip_events[ip] and self.ip_events[ip][0][0] < cutoff:
            self.ip_events[ip].popleft()
        while self.ip_alerts[ip] and self.ip_alerts[ip][0][0] < cutoff:
            self.ip_alerts[ip].popleft()

        distributed_cutoff = now_ts - timedelta(seconds=int(self.cfg.get("distributed_window_seconds", 120) or 120))
        while self.failed_global and self.failed_global[0][0] < distributed_cutoff:
            self.failed_global.popleft()

    def _evaluate(self, ip: str, now_ts: datetime) -> List[Tuple[str, str, float, Dict[str, Any]]]:
        findings: List[Tuple[str, str, float, Dict[str, Any]]] = []
        events = list(self.ip_events[ip])
        alerts = list(self.ip_alerts[ip])
        alert_names = {a for _, a in alerts}

        notfound = [e for e in events if e[3] == 404]
        unique_404_paths = {e[2] for e in notfound if e[2]}
        if (
            len(unique_404_paths) >= int(self.cfg.get("low_slow_min_paths", 12) or 12)
            and len(notfound) >= int(self.cfg.get("low_slow_min_404", 6) or 6)
            and "SCAN_BURST" not in alert_names
        ):
            score = min(1.0, len(unique_404_paths) / 30.0)
            findings.append(
                (
                    "LOW_AND_SLOW_SCAN",
                    "medium",
                    score,
                    {
                        "unique_404_paths": len(unique_404_paths),
                        "notfound_events": len(notfound),
                        "window_minutes": int(self.cfg.get("window_minutes", 10) or 10),
                    },
                )
            )

        failed_ips = {x[1] for x in self.failed_global}
        failed_count = len(self.failed_global)
        if failed_count >= int(self.cfg.get("distributed_min_failures", 20) or 20) and len(failed_ips) >= int(
            self.cfg.get("distributed_min_ips", 3) or 3
        ):
            findings.append(
                (
                    "DISTRIBUTED_BRUTE_FORCE",
                    "high",
                    min(1.0, failed_count / 60.0),
                    {
                        "failed_events": failed_count,
                        "unique_ips": len(failed_ips),
                        "window_seconds": int(self.cfg.get("distributed_window_seconds", 120) or 120),
                    },
                )
            )

        chain_stages = set()
        if alert_names.intersection({"SCAN_BURST", "ML_SCAN", "LOW_AND_SLOW_SCAN"}):
            chain_stages.add("reconnaissance")
        if alert_names.intersection({"INJECTION_INDICATOR", "ML_INJECTION"}):
            chain_stages.add("exploitation")
        if alert_names.intersection({"BRUTE_FORCE_IP", "CREDENTIAL_STUFFING", "ML_BRUTEFORCE"}):
            chain_stages.add("credential_access")
        if alert_names.intersection({"PRIVILEGE_PROBING"}):
            chain_stages.add("privilege_probe")
        if len(chain_stages) >= int(self.cfg.get("exploit_chain_min_stages", 3) or 3):
            findings.append(
                (
                    "EXPLOIT_CHAIN_SUSPECTED",
                    "high",
                    min(1.0, len(chain_stages) / 4.0),
                    {
                        "stages": sorted(chain_stages),
                        "alert_types": sorted(alert_names),
                        "window_minutes": int(self.cfg.get("window_minutes", 10) or 10),
                    },
                )
            )

        if self._privilege_escalation_like(events):
            findings.append(
                (
                    "PRIVILEGE_ESCALATION_SUSPECTED",
                    "high",
                    0.85,
                    {
                        "reason": "authenticated activity followed by admin/authorization denial in the correlation window",
                        "window_minutes": int(self.cfg.get("window_minutes", 10) or 10),
                    },
                )
            )

        beacon = self._beacon_candidate(events)
        if beacon is not None:
            findings.append(("C2_BEACON_PATTERN", "medium", beacon["score"], beacon))

        return findings

    def _privilege_escalation_like(self, events: List[Tuple[datetime, str, str, Optional[int], Optional[int]]]) -> bool:
        auth_seen = any(e[1] == "auth_login_success" or e[4] is not None for e in events)
        denied_admin = any(e[1] == "authorization_denied" or (e[2] == "/admin" and e[3] == 403) for e in events)
        return auth_seen and denied_admin

    def _beacon_candidate(self, events: List[Tuple[datetime, str, str, Optional[int], Optional[int]]]) -> Optional[Dict[str, Any]]:
        by_path: Dict[str, List[datetime]] = defaultdict(list)
        for ts, event_type, path, status, _user_id in events:
            if event_type == "http_request" and status and 200 <= status < 500 and path:
                by_path[path].append(ts)

        min_hits = int(self.cfg.get("beacon_min_hits", 6) or 6)
        max_cv = float(self.cfg.get("beacon_max_interval_cv", 0.35) or 0.35)
        for path, times in by_path.items():
            if len(times) < min_hits:
                continue
            times.sort()
            intervals = [(times[i] - times[i - 1]).total_seconds() for i in range(1, len(times))]
            if len(intervals) < 3:
                continue
            avg = sum(intervals) / len(intervals)
            if avg <= 0:
                continue
            var = sum((x - avg) ** 2 for x in intervals) / len(intervals)
            cv = (var ** 0.5) / avg
            if cv <= max_cv:
                return {
                    "path": path,
                    "hits": len(times),
                    "avg_interval_seconds": round(avg, 6),
                    "interval_cv": round(cv, 6),
                    "score": min(1.0, len(times) / 20.0),
                }
        return None


def evaluate_rules(event: Dict[str, Any], snapshot: Dict[str, Any], thr: RuleThresholds) -> List[Tuple[str, str, float]]:
    alerts: List[Tuple[str, str, float]] = []
    event_type = str(event.get("event", event.get("event_type", "")) or "")
    path = str(event.get("path", "") or "")
    status = as_int(event.get("status"))
    has_sql = as_bool(event.get("has_sql_keywords"))
    has_script = as_bool(event.get("has_script_payload"))

    if snapshot["failed_30s"] >= thr.brute_force_30s:
        alerts.append(("BRUTE_FORCE_IP", "high", min(1.0, snapshot["failed_30s"] / 30.0)))
    if snapshot["failed_30s"] >= thr.stuffing_30s and snapshot["unique_email_30s"] >= thr.stuffing_unique_emails_30s:
        alerts.append(("CREDENTIAL_STUFFING", "high", min(1.0, snapshot["unique_email_30s"] / 20.0)))
    if snapshot["notfound_30s"] >= thr.scan_30s_404 or snapshot["unique_paths_30s"] >= thr.scan_30s_unique_paths:
        alerts.append(("SCAN_BURST", "medium", min(1.0, snapshot["unique_paths_30s"] / 40.0)))
    if has_sql or has_script:
        alerts.append(("INJECTION_INDICATOR", "high", 0.95))
    if event_type == "authorization_denied" or (path == "/admin" and status == 403):
        alerts.append(("PRIVILEGE_PROBING", "medium", 0.8))
    return alerts


def choose_response_action(alert_type: str) -> Optional[Tuple[str, str, int]]:
    if alert_type in {"BRUTE_FORCE_IP", "CREDENTIAL_STUFFING", "ML_BRUTEFORCE"}:
        return ("THROTTLE_LOGIN_IP", "ip", 30)
    if alert_type in {"SCAN_BURST", "ML_SCAN", "INJECTION_INDICATOR", "ML_INJECTION"}:
        return ("FORCE_CAPTCHA_IP", "ip", 60)
    if alert_type == "ANOMALY_BEHAVIOR":
        return ("FORCE_CAPTCHA_IP", "ip", 30)
    if alert_type == "PRIVILEGE_PROBING":
        return ("REVOKE_SESSION_USER", "user", 30)
    return None


def read_policy_entries(path: Path) -> Dict[str, Any]:
    if not path.exists():
        return {}
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        if isinstance(data, dict):
            if "entries" in data and isinstance(data["entries"], dict):
                return dict(data["entries"])
            return data
    except Exception:
        return {}
    return {}


def write_policy_entries(path: Path, entries: Dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    payload = {"version": 1, "updated_at": datetime.now(timezone.utc).isoformat(), "entries": entries}
    path.write_text(json.dumps(payload, indent=2), encoding="utf-8")


def apply_auto_action(policy_dir: Path, action_type: str, target_id: str, expires_at: str, reason: str) -> None:
    mapping = {
        "THROTTLE_LOGIN_IP": "throttle_ips.json",
        "FORCE_CAPTCHA_IP": "captcha_ips.json",
        "REVOKE_SESSION_USER": "revoke_user_ids.json",
    }
    if action_type not in mapping:
        return
    path = policy_dir / mapping[action_type]
    entries = read_policy_entries(path)
    entries[target_id] = {"expires_at": expires_at, "reason": reason}
    write_policy_entries(path, entries)


def shadow_fingerprint(rule_id: str, actor: str, evidence_ids: List[str]) -> str:
    """Same algorithm as alert-writer-service's shadow_fingerprint — kept
    consistent so advisory_findings dedup identically regardless of which
    shadow producer wrote the row."""
    material = "|".join([rule_id, actor or "unknown", ",".join(sorted(str(e) for e in evidence_ids))])
    return hashlib.sha256(material.encode("utf-8")).hexdigest()


def shadow_promotion_blocker(confidence: float) -> str:
    reasons = [f"domain_soak_required: no 6h soak PASS for domain={SHADOW_DOMAIN}"]
    if confidence < SHADOW_PROMOTION_CONFIDENCE_THRESHOLD:
        reasons.append(f"low_confidence: {confidence:.2f} < {SHADOW_PROMOTION_CONFIDENCE_THRESHOLD}")
    return "; ".join(reasons)


def insert_advisory_findings(conn: Any, driver: str, rows: List[Tuple[Any, ...]]) -> None:
    """Upsert into advisory_findings — the shadow/advisory path this detector
    uses by default. Never touches security_alerts/security_incidents (see
    the identical boundary in alert-writer-service's write_advisory_finding)."""
    if not rows:
        return
    sql = """
    INSERT INTO advisory_findings (
      finding_id, rule_id, domain, source_topic, alert_type, severity,
      confidence, actor_key, tenant_id, evidence, source_event_ids,
      promotion_candidate, promotion_blocker, status, fingerprint,
      occurrence_count, first_seen_at, last_seen_at, created_at, updated_at
    ) VALUES (
      %s, %s, %s, %s, %s, %s,
      %s, %s, %s, %s::jsonb, %s::jsonb,
      %s, %s, 'new', %s,
      1, %s, %s, now(), now()
    )
    ON CONFLICT (fingerprint) DO UPDATE SET
      occurrence_count = advisory_findings.occurrence_count + 1,
      last_seen_at = excluded.last_seen_at,
      promotion_candidate = CASE
        WHEN advisory_findings.promotion_candidate THEN true
        ELSE excluded.promotion_candidate
      END,
      updated_at = now()
    """
    with conn.cursor() as cur:
        if driver == "psycopg3":
            cur.executemany(sql, rows)
        else:
            from psycopg2.extras import execute_batch  # type: ignore

            execute_batch(cur, sql, rows, page_size=200)
    conn.commit()


def insert_alerts(conn: Any, driver: str, rows: List[Tuple[Any, ...]]) -> None:
    if not rows:
        return
    sql = """
    INSERT INTO security_alerts (
      alert_id, detected_at, alert_type, detector_name, detector_version, severity, ip, request_id, actor_key,
      event_id_ref, window_start, window_end, score, threshold_profile_hash, model_label, evidence, raw_event, created_at, updated_at
    ) VALUES (
      %s, %s, %s, %s, %s, %s, %s, %s, %s,
      %s, %s, %s, %s, %s, %s, %s::jsonb, %s::jsonb, now(), now()
    )
    ON CONFLICT (alert_id) DO NOTHING
    """
    with conn.cursor() as cur:
        if driver == "psycopg3":
            cur.executemany(sql, rows)
        else:
            from psycopg2.extras import execute_batch  # type: ignore

            execute_batch(cur, sql, rows, page_size=200)
    conn.commit()


def insert_responses(conn: Any, driver: str, rows: List[Tuple[Any, ...]]) -> None:
    if not rows:
        return
    sql = """
    INSERT INTO security_responses (
      response_id, alert_ref, created_at_event, mode, action_type, target_type, target_id,
      status, severity, reason, expires_at, evidence, created_at, updated_at
    ) VALUES (
      %s, %s, now(), %s, %s, %s, %s,
      %s, %s, %s, %s, %s::jsonb, now(), now()
    )
    ON CONFLICT (response_id) DO NOTHING
    """
    with conn.cursor() as cur:
        if driver == "psycopg3":
            cur.executemany(sql, rows)
        else:
            from psycopg2.extras import execute_batch  # type: ignore

            execute_batch(cur, sql, rows, page_size=200)
    conn.commit()


def main() -> int:
    args = parse_args()
    project_root = Path(__file__).resolve().parents[1]
    dsn = args.dsn.strip() or build_dsn_from_env(project_root)
    if not dsn:
        print("ERROR: DSN missing. Set --dsn or SECURITY_INGEST_DSN.")
        return 1

    driver, conn = connect_db(dsn)
    conn.autocommit = False
    deployment_meta: Optional[Dict[str, Any]] = None

    model_path = (project_root / args.model).resolve()
    if int(args.use_active_deployment) == 1:
        deployment_meta = fetch_active_deployment(conn, args.deployment_env)
        if deployment_meta is not None:
            model_path = Path(deployment_meta["artifact_path"]).resolve()
            if deployment_meta.get("expected_artifact_sha256"):
                args.model_lock_sha256 = str(deployment_meta["expected_artifact_sha256"])
            if deployment_meta.get("lock_enabled") is True:
                args.require_lock = 1

    if int(args.require_lock) == 1 and not args.model_lock_sha256:
        print("ERROR: deployment lock enabled but model hash lock is empty.")
        conn.close()
        return 1
    if args.model_lock_sha256:
        if not model_path.exists():
            print(f"ERROR: model file not found for lock check: {model_path}")
            conn.close()
            return 1
        actual_hash = file_sha256(model_path)
        if actual_hash.lower() != args.model_lock_sha256.lower():
            print("ERROR: model artifact hash mismatch; deployment lock prevented startup.")
            print(f"Expected: {args.model_lock_sha256}")
            print(f"Actual:   {actual_hash}")
            conn.close()
            return 1

    model = maybe_load_model(model_path)
    if model is None:
        print("WARNING: ML model not loaded, running rules-only mode.")
    anomaly_profile = maybe_load_anomaly_profile((project_root / args.anomaly_profile).resolve()) if args.enable_anomaly else None

    state = RealtimeState()
    thr = load_thresholds((project_root / args.thresholds_file).resolve())
    thr_hash = threshold_profile_hash(thr)
    correlation_cfg = load_correlation_config((project_root / args.correlation_file).resolve())
    correlation_state = ThreatCorrelationState(correlation_cfg)
    allowlist_ips = load_allowlist((project_root / args.allowlist_file).resolve())
    response_policy_dir = (project_root / args.response_policy_dir).resolve()
    app_key = args.app_key or "demo-alert-key"

    base_uri = consumer_create(args.rest_url, args.group_id, args.instance_id, args.offset_reset)
    consumer_subscribe(base_uri, args.topic)

    print(f"REST URL: {args.rest_url}")
    print(f"Consumer group: {args.group_id}")
    print(f"Instance: {args.instance_id}")
    print(f"Topic: {args.topic}")
    print(f"ModelPath: {model_path}")
    print(f"AnomalyProfile: {args.anomaly_profile if anomaly_profile is not None else 'disabled/not-loaded'}")
    print(f"Thresholds: {thr}")
    print(f"DetectionMode: {args.detection_mode}")
    print(f"AllowlistIPs: {len(allowlist_ips)}")
    print(f"ResponseMode: {args.response_mode}")
    print(f"OutputMode: {args.output_mode}")
    if args.output_mode == "shadow":
        print(f"  -> writing advisory_findings only (domain={SHADOW_DOMAIN}); security_alerts/security_responses untouched")
    else:
        print("  -> WARNING: active mode writes directly to security_alerts/security_responses. "
              "Only use this after a domain-specific 6h soak PASS per CLAUDE.md.")
    if deployment_meta is not None:
        print(
            f"Deployment: id={deployment_meta['deployment_id']} model={deployment_meta['model_key']} lock={deployment_meta['lock_enabled']}"
        )
    print("Realtime detector started...")

    pending: List[Tuple[Any, ...]] = []
    pending_responses: List[Tuple[Any, ...]] = []
    pending_findings: List[Tuple[Any, ...]] = []
    empty_polls = 0
    invalid_events = 0
    consumed_events = 0

    try:
        while True:
            try:
                records = consumer_poll(base_uri, args.poll_interval_ms)
            except urllib.error.URLError as exc:
                print(f"poll_error={exc}")
                time.sleep(1.0)
                continue

            if not records:
                empty_polls += 1
                if pending:
                    insert_alerts(conn, driver, pending)
                    pending = []
                if pending_findings:
                    insert_advisory_findings(conn, driver, pending_findings)
                    pending_findings = []
                if args.max_empty_polls > 0 and empty_polls >= args.max_empty_polls:
                    break
                continue

            empty_polls = 0
            for rec in records:
                event = rec.get("value")
                if not isinstance(event, dict):
                    invalid_events += 1
                    continue
                consumed_events += 1

                if "event_type" not in event and "event" in event:
                    event["event_type"] = event["event"]

                ok, _errors = validate_event(event)
                if not ok:
                    invalid_events += 1
                    if invalid_events % 20 == 0:
                        print(f"invalid_events_dropped={invalid_events}")
                    continue

                snapshot = state.update(event)
                alerts = evaluate_rules(event, snapshot, thr)
                anomaly_evidence: Dict[str, Any] = {}
                correlation_evidence: Dict[str, Any] = {}

                if model is not None:
                    x = vectorize_for_model(event, snapshot, model["vectorizer"])
                    pred_label, pred_score = predict_model(model, x)
                    if pred_label != "normal" and not should_suppress_ml_prediction(pred_label, event, alerts):
                        alerts.append((f"ML_{pred_label.upper()}", "medium", pred_score))

                anomaly = evaluate_anomaly(event, snapshot, anomaly_profile)
                if anomaly is not None and not alerts:
                    alert_type, severity, score, evidence_detail = anomaly
                    alerts.append((alert_type, severity, score))
                    anomaly_evidence[alert_type] = evidence_detail

                if args.detection_mode == "advanced" and bool(correlation_cfg.get("enabled", True)):
                    event_ts_for_corr = parse_ts(str(event.get("ts", "")))
                    for alert_type, severity, score, evidence_detail in correlation_state.update(
                        event, event_ts_for_corr, [a[0] for a in alerts]
                    ):
                        alerts.append((alert_type, severity, score))
                        correlation_evidence[alert_type] = evidence_detail

                for alert_type, severity, score in alerts:
                    event_ts = parse_ts(str(event.get("ts", "")))
                    ts = event_ts.isoformat()
                    request_id = str(event.get("request_id", "") or None)
                    ip = str(event.get("ip", "") or None)
                    actor_key = ip or (str(event.get("user_id")) if event.get("user_id") is not None else "")
                    w_start, w_end = window_for_alert(alert_type, event_ts)
                    schema_ver = int(event.get("schema_version", 1) or 1)
                    unique_text = (
                        f"{schema_ver}|{DETECTOR_VERSION}|{alert_type}|{actor_key}|"
                        f"{w_start.isoformat()}|{w_end.isoformat()}|{thr_hash}"
                    )
                    alert_id = hmac_hex(app_key, unique_text)
                    evidence = {
                        "window_features": snapshot,
                        "event_type": event.get("event_type"),
                        "path": event.get("path"),
                        "status": event.get("status"),
                        "alert_window_start": w_start.isoformat(),
                        "alert_window_end": w_end.isoformat(),
                    }
                    if alert_type in anomaly_evidence:
                        evidence["anomaly"] = anomaly_evidence[alert_type]
                    if args.detection_mode == "advanced":
                        evidence["detection_mode"] = "advanced"
                        evidence["mitre_attack"] = mitre_for_alert(alert_type)
                    if alert_type in correlation_evidence:
                        evidence["correlation"] = correlation_evidence[alert_type]
                    model_label = alert_type.removeprefix("ML_").lower() if alert_type.startswith("ML_") else None

                    if args.output_mode == "shadow":
                        # ENT-DETECT-ML-NOT-LIVE: advisory-only path. No
                        # security_alerts row, no security_responses row, no
                        # promotion — an analyst reviews advisory_findings and
                        # a domain-specific 6h soak PASS is required before
                        # this domain could ever move to --output-mode=active.
                        evidence_ids = [request_id] if request_id and request_id != "None" else []
                        fp = shadow_fingerprint(alert_type, actor_key or "unknown", evidence_ids)
                        finding_id = "adv-" + fp[:36]
                        pending_findings.append(
                            (
                                finding_id,
                                alert_type,  # rule_id
                                SHADOW_DOMAIN,
                                args.topic,  # source_topic
                                alert_type,
                                severity,
                                float(score),
                                actor_key or None,
                                None,  # tenant_id — this detector has no tenant context
                                json.dumps(evidence, separators=(",", ":")),
                                json.dumps(evidence_ids, separators=(",", ":")),
                                score >= SHADOW_PROMOTION_CONFIDENCE_THRESHOLD,
                                shadow_promotion_blocker(score),
                                fp,
                                ts,
                                ts,
                            )
                        )
                        continue

                    pending.append(
                        (
                            alert_id,
                            ts,
                            alert_type,
                            DETECTOR_NAME,
                            DETECTOR_VERSION,
                            severity,
                            ip,
                            request_id,
                            actor_key or None,
                            None,
                            w_start.isoformat(),
                            w_end.isoformat(),
                            float(score),
                            thr_hash,
                            model_label,
                            json.dumps(evidence, separators=(",", ":")),
                            json.dumps(event, separators=(",", ":")),
                        )
                    )

                    if args.response_mode != "off":
                        action = choose_response_action(alert_type)
                        if action is not None:
                            action_type, target_type, ttl_minutes = action
                            target_id = ""
                            if target_type == "ip":
                                target_id = ip or ""
                            else:
                                uid = event.get("user_id")
                                target_id = str(uid) if uid is not None else ""
                            if target_id:
                                expires_at = (datetime.now(timezone.utc) + timedelta(minutes=ttl_minutes)).isoformat()
                                status = "recommended"
                                reason = f"from_alert:{alert_type}"
                                if target_type == "ip" and target_id in allowlist_ips:
                                    status = "suppressed"
                                    reason = "allowlist_ip"
                                elif args.response_mode == "auto":
                                    apply_auto_action(response_policy_dir, action_type, target_id, expires_at, reason)
                                    status = "executed"

                                response_id = hmac_hex(app_key, f"{alert_id}|{action_type}|{target_type}|{target_id}")
                                pending_responses.append(
                                    (
                                        response_id,
                                        None,
                                        args.response_mode,
                                        action_type,
                                        target_type,
                                        target_id,
                                        status,
                                        severity,
                                        reason,
                                        expires_at if status in {"recommended", "executed"} else None,
                                        json.dumps(
                                            {
                                                "alert_type": alert_type,
                                                "request_id": request_id,
                                                "model_label": model_label,
                                                "score": float(score),
                                            },
                                            separators=(",", ":"),
                                        ),
                                    )
                                )

            if len(pending) >= 30:
                insert_alerts(conn, driver, pending)
                print(f"alerts_inserted_batch={len(pending)}")
                pending = []
            if len(pending_responses) >= 30:
                insert_responses(conn, driver, pending_responses)
                print(f"responses_inserted_batch={len(pending_responses)}")
                pending_responses = []
            if len(pending_findings) >= 30:
                insert_advisory_findings(conn, driver, pending_findings)
                print(f"advisory_findings_inserted_batch={len(pending_findings)}")
                pending_findings = []

    except KeyboardInterrupt:
        print("Stopping detector...")
    finally:
        if pending:
            insert_alerts(conn, driver, pending)
        if pending_responses:
            insert_responses(conn, driver, pending_responses)
        if pending_findings:
            insert_advisory_findings(conn, driver, pending_findings)
        print(f"consumed_events={consumed_events}")
        print(f"invalid_events_dropped={invalid_events}")
        consumer_delete(base_uri)
        conn.close()

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
