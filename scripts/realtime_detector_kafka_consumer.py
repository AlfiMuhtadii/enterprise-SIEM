#!/usr/bin/env python3
"""
Near-real-time detector consumer using Kafka protocol.

This is the production path for Redpanda. It avoids Pandaproxy consumer offsets.
"""

from __future__ import annotations

import argparse
import json
import os
import time
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
    parser = argparse.ArgumentParser(description="Realtime detector consumer using Kafka protocol.")
    parser.add_argument("--bootstrap-servers", default=os.getenv("KAFKA_BOOTSTRAP_SERVERS", "127.0.0.1:19092"))
    parser.add_argument("--topic", default=os.getenv("KAFKA_TOPIC", "security_events"))
    parser.add_argument("--group-id", default=os.getenv("KAFKA_GROUP_ID", "detector-realtime-v2"))
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
    parser.add_argument("--use-active-deployment", type=int, choices=[0, 1], default=0)
    parser.add_argument("--deployment-env", default=os.getenv("ML_DEPLOYMENT_ENV", "local"))
    parser.add_argument("--model-lock-sha256", default=os.getenv("ML_ALLOWED_ARTIFACT_SHA256", ""))
    parser.add_argument("--require-lock", type=int, choices=[0, 1], default=0)
    parser.add_argument("--app-key", default=os.getenv("APP_KEY", "demo-alert-key"))
    parser.add_argument("--poll-timeout-sec", type=float, default=1.0)
    parser.add_argument("--max-empty-polls", type=int, default=0, help="0 means run forever")
    return parser.parse_args()


def decode_event(value: Optional[bytes]) -> Optional[Dict[str, Any]]:
    if value is None:
        return None
    try:
        payload = json.loads(value.decode("utf-8"))
    except Exception:
        return None
    return payload if isinstance(payload, dict) else None


def main() -> int:
    try:
        from confluent_kafka import Consumer, KafkaException  # type: ignore
    except ImportError:
        print("ERROR: missing dependency confluent-kafka. Install: python -m pip install -r scripts/requirements-ingest.txt")
        return 1

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

    consumer = Consumer(
        {
            "bootstrap.servers": args.bootstrap_servers,
            "group.id": args.group_id,
            "auto.offset.reset": "earliest",
            "enable.auto.commit": False,
            "client.id": "detector-realtime-consumer",
        }
    )
    consumer.subscribe([args.topic])

    print(f"BootstrapServers: {args.bootstrap_servers}", flush=True)
    print(f"ConsumerGroup: {args.group_id}", flush=True)
    print(f"Topic: {args.topic}", flush=True)
    print(f"ModelPath: {model_path}", flush=True)
    print(f"AnomalyProfile: {args.anomaly_profile if anomaly_profile is not None else 'disabled/not-loaded'}", flush=True)
    print(f"Thresholds: {thr}", flush=True)
    print(f"DetectionMode: {args.detection_mode}", flush=True)
    print(f"AllowlistIPs: {len(allowlist_ips)}", flush=True)
    print(f"ResponseMode: {args.response_mode}", flush=True)
    if deployment_meta is not None:
        print(
            f"Deployment: id={deployment_meta['deployment_id']} model={deployment_meta['model_key']} lock={deployment_meta['lock_enabled']}",
            flush=True,
        )
    print("Realtime Kafka detector started...", flush=True)

    pending: List[Tuple[Any, ...]] = []
    pending_responses: List[Tuple[Any, ...]] = []
    empty_polls = 0
    invalid_events = 0
    consumed_events = 0

    try:
        while True:
            msg = consumer.poll(args.poll_timeout_sec)
            if msg is None:
                empty_polls += 1
                if pending:
                    insert_alerts(conn, driver, pending)
                    pending = []
                if pending_responses:
                    insert_responses(conn, driver, pending_responses)
                    pending_responses = []
                if args.max_empty_polls > 0 and empty_polls >= args.max_empty_polls:
                    break
                continue
            if msg.error():
                raise KafkaException(msg.error())

            empty_polls = 0
            event = decode_event(msg.value())
            if event is None:
                invalid_events += 1
                continue
            consumed_events += 1

            if "event_type" not in event and "event" in event:
                event["event_type"] = event["event"]

            ok, _errors = validate_event(event)
            if not ok:
                invalid_events += 1
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
                    "topic": msg.topic(),
                    "partition": msg.partition(),
                    "offset": msg.offset(),
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
                        target_id = ip or "" if target_type == "ip" else str(event.get("user_id") or "")
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
                print(f"alerts_inserted_batch={len(pending)}", flush=True)
                pending = []
            if len(pending_responses) >= 30:
                insert_responses(conn, driver, pending_responses)
                print(f"responses_inserted_batch={len(pending_responses)}", flush=True)
                pending_responses = []
            consumer.commit(asynchronous=False)

    except KeyboardInterrupt:
        print("Stopping detector...", flush=True)
    finally:
        if pending:
            insert_alerts(conn, driver, pending)
        if pending_responses:
            insert_responses(conn, driver, pending_responses)
        print(f"consumed_events={consumed_events}", flush=True)
        print(f"invalid_events_dropped={invalid_events}", flush=True)
        consumer.close()
        conn.close()

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
