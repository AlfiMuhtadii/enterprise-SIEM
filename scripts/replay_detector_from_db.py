#!/usr/bin/env python3
"""
Deterministic replay detector from Postgres security_events.

Used as a demo fallback when Pandaproxy consumer polling is unavailable.
Reuses the same rules/ML functions as realtime_detector_consumer.
"""

from __future__ import annotations

import argparse
import json
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

from realtime_detector_consumer import (
    DETECTOR_NAME,
    DETECTOR_VERSION,
    RealtimeState,
    apply_auto_action,
    build_dsn_from_env,
    choose_response_action,
    connect_db,
    file_sha256,
    fetch_active_deployment,
    hmac_hex,
    insert_alerts,
    insert_responses,
    load_allowlist,
    load_correlation_config,
    load_thresholds,
    maybe_load_anomaly_profile,
    maybe_load_model,
    mitre_for_alert,
    parse_ts,
    predict_model,
    should_suppress_ml_prediction,
    threshold_profile_hash,
    validate_event,
    vectorize_for_model,
    window_for_alert,
    evaluate_anomaly,
    evaluate_rules,
    ThreatCorrelationState,
)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Replay detector from Postgres security_events")
    parser.add_argument("--dsn", default="")
    parser.add_argument("--model", default="storage/app/ai_detector_model.pkl")
    parser.add_argument("--anomaly-profile", default="storage/app/anomaly_profile.json")
    parser.add_argument("--enable-anomaly", type=int, choices=[0, 1], default=1)
    parser.add_argument("--detection-mode", choices=["current", "advanced"], default="current")
    parser.add_argument("--correlation-file", default="storage/app/detector_correlation.json")
    parser.add_argument("--thresholds-file", default="storage/app/detector_thresholds.json")
    parser.add_argument("--allowlist-file", default="storage/app/detector_allowlist.json")
    parser.add_argument("--response-mode", choices=["off", "recommend", "auto"], default="recommend")
    parser.add_argument("--response-policy-dir", default="storage/app/response")
    parser.add_argument("--app-key", default="demo-alert-key")
    parser.add_argument("--use-active-deployment", type=int, choices=[0, 1], default=0)
    parser.add_argument("--deployment-env", default="local")
    parser.add_argument("--model-lock-sha256", default="")
    parser.add_argument("--require-lock", type=int, choices=[0, 1], default=0)
    return parser.parse_args()


def fetch_events(conn: Any) -> List[Dict[str, Any]]:
    sql = """
    SELECT payload
    FROM security_events
    ORDER BY ts ASC, id ASC
    """
    rows: List[Dict[str, Any]] = []
    with conn.cursor() as cur:
        cur.execute(sql)
        for (payload,) in cur.fetchall():
            event = payload
            if isinstance(event, str):
                try:
                    event = json.loads(event)
                except Exception:
                    event = None
            if isinstance(event, dict):
                rows.append(event)
    return rows


def main() -> int:
    args = parse_args()
    project_root = Path(__file__).resolve().parents[1]
    dsn = args.dsn.strip() or build_dsn_from_env(project_root)
    if not dsn:
        print("ERROR: DSN missing. Set --dsn or configure .env for pgsql.")
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

    thr = load_thresholds((project_root / args.thresholds_file).resolve())
    thr_hash = threshold_profile_hash(thr)
    correlation_cfg = load_correlation_config((project_root / args.correlation_file).resolve())
    correlation_state = ThreatCorrelationState(correlation_cfg)
    allowlist_ips = load_allowlist((project_root / args.allowlist_file).resolve())
    response_policy_dir = (project_root / args.response_policy_dir).resolve()
    app_key = args.app_key or "demo-alert-key"
    state = RealtimeState()

    events = fetch_events(conn)
    pending: List[Tuple[Any, ...]] = []
    pending_responses: List[Tuple[Any, ...]] = []
    consumed_events = 0
    invalid_events = 0

    for event in events:
        if "event_type" not in event and "event" in event:
            event["event_type"] = event["event"]

        ok, _errors = validate_event(event)
        if not ok:
            invalid_events += 1
            continue

        consumed_events += 1
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

    if pending:
        insert_alerts(conn, driver, pending)
    if pending_responses:
        insert_responses(conn, driver, pending_responses)

    print(f"replayed_events={consumed_events}")
    print(f"invalid_events_dropped={invalid_events}")
    print(f"alerts_attempted={len(pending)}")
    print(f"responses_attempted={len(pending_responses)}")
    conn.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
