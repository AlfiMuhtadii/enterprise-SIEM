#!/usr/bin/env python3
"""
Phase 14 response engine.

Modes:
- recommend (default): publish recommended actions only
- auto: execute safe step-up controls (throttle/captcha/revoke session)
"""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Security response engine")
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--mode", choices=["recommend", "auto"], default="recommend")
    parser.add_argument("--minutes", type=int, default=30)
    parser.add_argument("--allowlist-file", default="storage/app/detector_allowlist.json")
    parser.add_argument("--policy-dir", default="storage/app/response")
    parser.add_argument("--app-key", default=os.getenv("APP_KEY", "demo-alert-key"))
    parser.add_argument("--actor", default="response-engine")
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


def connect_db(dsn: str):
    try:
        import psycopg  # type: ignore

        return "psycopg3", psycopg.connect(dsn)
    except Exception:
        import psycopg2  # type: ignore

        return "psycopg2", psycopg2.connect(dsn)


def hmac_hex(secret: str, text: str) -> str:
    if secret.startswith("base64:"):
        try:
            import base64
            key = base64.b64decode(secret[7:])
            return hmac.new(key, text.encode("utf-8"), hashlib.sha256).hexdigest()
        except Exception:
            pass
    return hmac.new(secret.encode("utf-8"), text.encode("utf-8"), hashlib.sha256).hexdigest()


def load_allowlist(path: Path) -> set[str]:
    if not path.exists():
        return set()
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return set()
    ips = data.get("ips", []) if isinstance(data, dict) else data
    out = set()
    if isinstance(ips, list):
        for v in ips:
            if isinstance(v, str) and v.strip():
                out.add(v.strip())
    return out


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
        pass
    return {}


def write_policy_entries(path: Path, entries: Dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    payload = {"version": 1, "updated_at": datetime.now(timezone.utc).isoformat(), "entries": entries}
    path.write_text(json.dumps(payload, indent=2), encoding="utf-8")


def choose_action(alert_type: str) -> Optional[Tuple[str, str, int]]:
    # action_type, target_type, ttl_minutes
    if alert_type in {"BRUTE_FORCE_IP", "CREDENTIAL_STUFFING", "ML_BRUTEFORCE"}:
        return ("THROTTLE_LOGIN_IP", "ip", 30)
    if alert_type in {"SCAN_BURST", "ML_SCAN", "INJECTION_INDICATOR", "ML_INJECTION"}:
        return ("FORCE_CAPTCHA_IP", "ip", 60)
    if alert_type in {"PRIVILEGE_PROBING"}:
        return ("REVOKE_SESSION_USER", "user", 30)
    return None


def insert_audit(
    conn: Any,
    actor: str,
    action: str,
    target_type: str,
    target_id: Optional[str],
    before_state: Dict[str, Any],
    after_state: Dict[str, Any],
    meta: Dict[str, Any],
) -> None:
    sql = """
    INSERT INTO security_audit_trails (
      occurred_at, actor, action, target_type, target_id, before_state, after_state, meta, created_at, updated_at
    ) VALUES (
      %s, %s, %s, %s, %s, %s::jsonb, %s::jsonb, %s::jsonb, now(), now()
    )
    """
    with conn.cursor() as cur:
        cur.execute(
            sql,
            (
                datetime.now(timezone.utc).isoformat(),
                actor,
                action,
                target_type,
                target_id,
                json.dumps(before_state, separators=(",", ":")),
                json.dumps(after_state, separators=(",", ":")),
                json.dumps(meta, separators=(",", ":")),
            ),
        )


def fetch_alerts(conn: Any, minutes: int) -> List[Dict[str, Any]]:
    sql = """
    SELECT id, alert_id, detected_at, alert_type, severity, ip, request_id, model_label, evidence, raw_event
    FROM security_alerts
    WHERE detected_at >= now() - (%s || ' minutes')::interval
    ORDER BY detected_at ASC
    """
    out: List[Dict[str, Any]] = []
    with conn.cursor() as cur:
        cur.execute(sql, (int(minutes),))
        for r in cur.fetchall():
            raw_event = r[9]
            if isinstance(raw_event, str):
                try:
                    raw_event = json.loads(raw_event)
                except Exception:
                    raw_event = {}
            out.append(
                {
                    "id": int(r[0]),
                    "alert_id": str(r[1]),
                    "detected_at": str(r[2]),
                    "alert_type": str(r[3]),
                    "severity": str(r[4] or "medium"),
                    "ip": str(r[5] or ""),
                    "request_id": str(r[6] or ""),
                    "model_label": str(r[7] or ""),
                    "evidence": r[8] if isinstance(r[8], dict) else {},
                    "raw_event": raw_event if isinstance(raw_event, dict) else {},
                }
            )
    return out


def insert_response(conn: Any, response: Dict[str, Any]) -> None:
    sql = """
    INSERT INTO security_responses (
      response_id, alert_ref, created_at_event, mode, action_type, target_type, target_id,
      status, severity, reason, expires_at, evidence, created_at, updated_at
    ) VALUES (
      %s, %s, now(), %s, %s, %s, %s, %s, %s, %s, %s, %s::jsonb, now(), now()
    )
    ON CONFLICT (response_id) DO NOTHING
    """
    with conn.cursor() as cur:
        cur.execute(
            sql,
            (
                response["response_id"],
                response["alert_ref"],
                response["mode"],
                response["action_type"],
                response["target_type"],
                response["target_id"],
                response["status"],
                response["severity"],
                response["reason"],
                response["expires_at"],
                json.dumps(response["evidence"], separators=(",", ":")),
            ),
        )


def apply_auto_action(policy_dir: Path, action_type: str, target_type: str, target_id: str, expires_at: str, reason: str) -> None:
    file_map = {
        "THROTTLE_LOGIN_IP": "throttle_ips.json",
        "FORCE_CAPTCHA_IP": "captcha_ips.json",
        "REVOKE_SESSION_USER": "revoke_user_ids.json",
    }
    if action_type not in file_map:
        return
    path = policy_dir / file_map[action_type]
    entries = read_policy_entries(path)
    entries[target_id] = {"expires_at": expires_at, "reason": reason}
    write_policy_entries(path, entries)


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    dsn = args.dsn.strip() or build_dsn_from_env(root)
    if not dsn:
        print("ERROR: DSN missing")
        return 1

    allowlist = load_allowlist((root / args.allowlist_file).resolve())
    policy_dir = (root / args.policy_dir).resolve()

    driver, conn = connect_db(dsn)
    conn.autocommit = False
    created = 0
    suppressed = 0
    executed = 0
    recommended = 0

    try:
        alerts = fetch_alerts(conn, args.minutes)
        for alert in alerts:
            action = choose_action(alert["alert_type"])
            if action is None:
                continue

            action_type, target_type, ttl_minutes = action
            target_id = ""
            if target_type == "ip":
                target_id = alert.get("ip", "") or ""
            else:
                uid = alert.get("raw_event", {}).get("user_id")
                target_id = str(uid) if uid is not None else ""
            if not target_id:
                continue

            expires_at = (datetime.now(timezone.utc) + timedelta(minutes=ttl_minutes)).isoformat()
            status = "recommended"
            reason = f"from_alert:{alert['alert_type']}"

            if target_type == "ip" and target_id in allowlist:
                status = "suppressed"
                reason = "allowlist_ip"
                suppressed += 1
            elif args.mode == "auto":
                apply_auto_action(policy_dir, action_type, target_type, target_id, expires_at, reason)
                status = "executed"
                executed += 1
            else:
                recommended += 1

            response_id = hmac_hex(args.app_key, f"{alert['alert_id']}|{action_type}|{target_type}|{target_id}")
            response = {
                "response_id": response_id,
                "alert_ref": alert["id"],
                "mode": args.mode,
                "action_type": action_type,
                "target_type": target_type,
                "target_id": target_id,
                "status": status,
                "severity": alert.get("severity", "medium"),
                "reason": reason,
                "expires_at": expires_at if status in {"executed", "recommended"} else None,
                "evidence": {
                    "alert_type": alert["alert_type"],
                    "request_id": alert.get("request_id"),
                    "model_label": alert.get("model_label"),
                },
            }
            insert_response(conn, response)
            created += 1

            if status in {"executed", "suppressed"}:
                insert_audit(
                    conn=conn,
                    actor=args.actor,
                    action="RESPONSE_ENGINE_ACTION",
                    target_type=target_type,
                    target_id=target_id,
                    before_state={},
                    after_state=response,
                    meta={"mode": args.mode},
                )

        conn.commit()
    finally:
        conn.close()

    print(f"Mode: {args.mode}")
    print(f"ResponsesCreated: {created}")
    print(f"Recommended: {recommended}")
    print(f"Executed: {executed}")
    print(f"Suppressed: {suppressed}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
