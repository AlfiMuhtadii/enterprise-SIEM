#!/usr/bin/env python3
"""
Near-real-time detector using Redpanda topic partition polling over HTTP Proxy.

This avoids the unstable consumer-group polling path and tracks offsets locally.
"""

from __future__ import annotations

import argparse
import json
import os
import time
import urllib.error
import urllib.request
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

from realtime_detector_consumer import (
    DETECTOR_NAME,
    DETECTOR_VERSION,
    RealtimeState,
    apply_auto_action,
    as_int,
    build_dsn_from_env,
    choose_response_action,
    connect_db,
    file_sha256,
    fetch_active_deployment,
    hmac_hex,
    insert_alerts,
    insert_responses,
    load_allowlist,
    load_thresholds,
    maybe_load_model,
    parse_ts,
    predict_model,
    should_suppress_ml_prediction,
    threshold_profile_hash,
    validate_event,
    vectorize_for_model,
    window_for_alert,
    evaluate_rules,
)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Realtime detector via topic partition polling.")
    parser.add_argument("--rest-url", default=os.getenv("KAFKA_REST_URL", "http://127.0.0.1:8082"))
    parser.add_argument("--topic", default=os.getenv("KAFKA_TOPIC", "security_events"))
    parser.add_argument("--producer-state-file", default="storage/app/redpanda_topic_offsets.json")
    parser.add_argument("--consumer-state-file", default="storage/app/redpanda_topic_consumer_state.json")
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--model", default="storage/app/ai_detector_model.pkl")
    parser.add_argument("--thresholds-file", default="storage/app/detector_thresholds.json")
    parser.add_argument("--allowlist-file", default="storage/app/detector_allowlist.json")
    parser.add_argument("--response-mode", choices=["off", "recommend", "auto"], default=os.getenv("RESPONSE_MODE", "recommend"))
    parser.add_argument("--response-policy-dir", default=os.getenv("RESPONSE_POLICY_DIR", "storage/app/response"))
    parser.add_argument("--use-active-deployment", type=int, choices=[0, 1], default=0)
    parser.add_argument("--deployment-env", default=os.getenv("ML_DEPLOYMENT_ENV", "local"))
    parser.add_argument("--model-lock-sha256", default=os.getenv("ML_ALLOWED_ARTIFACT_SHA256", ""))
    parser.add_argument("--require-lock", type=int, choices=[0, 1], default=0)
    parser.add_argument("--app-key", default=os.getenv("APP_KEY", "demo-alert-key"))
    parser.add_argument("--poll-interval-ms", type=int, default=800)
    parser.add_argument("--max-bytes", type=int, default=1048576)
    parser.add_argument("--max-empty-polls", type=int, default=0, help="0 means run forever")
    return parser.parse_args()


def read_json(path: Path, default: Dict[str, Any]) -> Dict[str, Any]:
    if not path.exists():
        return default
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        return data if isinstance(data, dict) else default
    except Exception:
        return default


def write_json(path: Path, payload: Dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2), encoding="utf-8")


def topic_poll(rest_url: str, topic: str, partition: int, offset: int, timeout_ms: int, max_bytes: int) -> List[Dict[str, Any]]:
    url = (
        f"{rest_url.rstrip('/')}/topics/{topic}/partitions/{partition}/records"
        f"?offset={int(offset)}&timeout={int(timeout_ms)}&max_bytes={int(max_bytes)}"
    )
    req = urllib.request.Request(url, headers={"Accept": "application/vnd.kafka.json.v2+json"}, method="GET")
    try:
        with urllib.request.urlopen(req, timeout=max(10, int(timeout_ms / 1000) + 5)) as resp:
            text = resp.read().decode("utf-8")
            if not text:
                return []
            data = json.loads(text)
            if isinstance(data, list):
                return [item for item in data if isinstance(item, dict)]
            if isinstance(data, dict):
                records = data.get("records")
                if isinstance(records, list):
                    return [item for item in records if isinstance(item, dict)]
            return []
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        if exc.code == 400 and "offset_out_of_range" in body:
            return [{"__offset_out_of_range__": True, "partition": partition}]
        raise


def ensure_partition_offsets(producer_state: Dict[str, Any], consumer_state: Dict[str, Any]) -> Dict[str, int]:
    offsets = consumer_state.setdefault("offsets", {})
    producer_parts = producer_state.get("partitions", {})
    if not isinstance(producer_parts, dict):
        return {}
    for pkey, meta in producer_parts.items():
        if not isinstance(meta, dict):
            continue
        if pkey not in offsets:
            offsets[pkey] = int(meta.get("start_offset", 0))
    return {str(k): int(v) for k, v in offsets.items()}


def main() -> int:
    args = parse_args()
    project_root = Path(__file__).resolve().parents[1]
    dsn = args.dsn.strip() or build_dsn_from_env(project_root)
    if not dsn:
        print("ERROR: DSN missing. Set --dsn or SECURITY_INGEST_DSN.")
        return 1

    producer_state_path = (project_root / args.producer_state_file).resolve()
    consumer_state_path = (project_root / args.consumer_state_file).resolve()

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

    state = RealtimeState()
    thr = load_thresholds((project_root / args.thresholds_file).resolve())
    thr_hash = threshold_profile_hash(thr)
    allowlist_ips = load_allowlist((project_root / args.allowlist_file).resolve())
    response_policy_dir = (project_root / args.response_policy_dir).resolve()
    app_key = args.app_key or "demo-alert-key"

    print(f"REST URL: {args.rest_url}")
    print(f"Topic: {args.topic}")
    print(f"ProducerState: {producer_state_path}")
    print(f"ConsumerState: {consumer_state_path}")
    print(f"ModelPath: {model_path}")
    print(f"Thresholds: {thr}")
    print(f"AllowlistIPs: {len(allowlist_ips)}")
    print(f"ResponseMode: {args.response_mode}")
    if deployment_meta is not None:
        print(
            f"Deployment: id={deployment_meta['deployment_id']} model={deployment_meta['model_key']} lock={deployment_meta['lock_enabled']}"
        )
    print("Realtime topic detector started...")

    pending: List[Tuple[Any, ...]] = []
    pending_responses: List[Tuple[Any, ...]] = []
    empty_polls = 0
    invalid_events = 0
    consumed_events = 0

    try:
        while True:
            producer_state = read_json(producer_state_path, {"topic": args.topic, "partitions": {}})
            consumer_state = read_json(consumer_state_path, {"topic": args.topic, "offsets": {}})
            offsets = ensure_partition_offsets(producer_state, consumer_state)
            if not offsets:
                empty_polls += 1
                if args.max_empty_polls > 0 and empty_polls >= args.max_empty_polls:
                    break
                time.sleep(max(args.poll_interval_ms, 50) / 1000.0)
                continue

            polled_any = False
            for pkey in sorted(offsets.keys(), key=lambda x: int(x)):
                partition = int(pkey)
                current_offset = int(offsets[pkey])
                try:
                    records = topic_poll(
                        args.rest_url,
                        args.topic,
                        partition,
                        current_offset,
                        args.poll_interval_ms,
                        args.max_bytes,
                    )
                except urllib.error.URLError as exc:
                    print(f"poll_error partition={partition} error={exc}")
                    time.sleep(1.0)
                    continue

                if records and records[0].get("__offset_out_of_range__") is True:
                    producer_meta = producer_state.get("partitions", {}).get(pkey, {})
                    reset_offset = int(producer_meta.get("start_offset", current_offset))
                    consumer_state.setdefault("offsets", {})[pkey] = reset_offset
                    write_json(consumer_state_path, consumer_state)
                    print(f"offset_reset partition={partition} from={current_offset} to={reset_offset}")
                    continue

                if not records:
                    continue

                polled_any = True
                max_seen_offset = current_offset - 1
                for rec in records:
                    offset_val = as_int(rec.get("offset"))
                    if offset_val is not None:
                        max_seen_offset = max(max_seen_offset, offset_val)
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
                        continue

                    snapshot = state.update(event)
                    alerts = evaluate_rules(event, snapshot, thr)

                    if model is not None:
                        x = vectorize_for_model(event, snapshot, model["vectorizer"])
                        pred_label, pred_score = predict_model(model, x)
                        if pred_label != "normal" and not should_suppress_ml_prediction(pred_label, event, alerts):
                            alerts.append((f"ML_{pred_label.upper()}", "medium", pred_score))

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
                            "partition": partition,
                            "record_offset": offset_val,
                            "alert_window_start": w_start.isoformat(),
                            "alert_window_end": w_end.isoformat(),
                        }
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

                consumer_state.setdefault("offsets", {})[pkey] = max_seen_offset + 1

            write_json(consumer_state_path, consumer_state)

            if len(pending) >= 30:
                insert_alerts(conn, driver, pending)
                print(f"alerts_inserted_batch={len(pending)}")
                pending = []
            if len(pending_responses) >= 30:
                insert_responses(conn, driver, pending_responses)
                print(f"responses_inserted_batch={len(pending_responses)}")
                pending_responses = []

            if not polled_any:
                empty_polls += 1
                if pending:
                    insert_alerts(conn, driver, pending)
                    pending = []
                if pending_responses:
                    insert_responses(conn, driver, pending_responses)
                    pending_responses = []
                if args.max_empty_polls > 0 and empty_polls >= args.max_empty_polls:
                    break
            else:
                empty_polls = 0

    except KeyboardInterrupt:
        print("Stopping detector...")
    finally:
        if pending:
            insert_alerts(conn, driver, pending)
        if pending_responses:
            insert_responses(conn, driver, pending_responses)
        print(f"consumed_events={consumed_events}")
        print(f"invalid_events_dropped={invalid_events}")
        conn.close()

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
