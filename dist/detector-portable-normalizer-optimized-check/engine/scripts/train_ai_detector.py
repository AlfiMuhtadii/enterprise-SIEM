#!/usr/bin/env python3
"""
Phase 5: Supervised AI detector (dependency-free).

Implements multiclass logistic regression in pure Python so it can run
in restricted/offline environments.
"""

from __future__ import annotations

import argparse
import csv
import json
import math
import pickle
import random
from collections import Counter, defaultdict, deque
from dataclasses import dataclass
from datetime import datetime, timedelta
from pathlib import Path
from statistics import mean, median
from typing import Deque, Dict, List, Optional, Sequence, Tuple


CLASSES = ["normal", "bruteforce", "scan", "injection"]
ATTACK_CLASSES = {"bruteforce", "scan", "injection"}


@dataclass
class WindowState:
    failed_logins: Deque[Tuple[datetime, str]]
    req_events: Deque[Tuple[datetime, str, Optional[int]]]
    paths: Deque[Tuple[datetime, str]]


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Train/evaluate dependency-free AI detector.")
    parser.add_argument("--input", default="storage/app/security_dataset.csv")
    parser.add_argument("--model", choices=["logreg", "rf"], default="logreg")
    parser.add_argument("--split-mode", choices=["time", "run"], default="run")
    parser.add_argument("--train-ratio", type=float, default=0.7)
    parser.add_argument("--output-model", default="storage/app/ai_detector_model.pkl")
    parser.add_argument("--output-report", default="storage/app/ai_detector_report.json")
    parser.add_argument("--output-predictions", default="storage/app/ai_detector_predictions.csv")
    parser.add_argument("--output-feature-importance", default="storage/app/ai_detector_feature_importance.json")
    parser.add_argument("--output-model-card", default="storage/app/ai_detector_model_card.json")
    parser.add_argument("--epochs", type=int, default=120)
    parser.add_argument("--lr", type=float, default=0.08)
    parser.add_argument("--l2", type=float, default=0.0001)
    parser.add_argument("--random-seed", type=int, default=42)
    parser.add_argument("--attack-threshold-floor", type=float, default=0.5)
    parser.add_argument("--target-attack-precision", type=float, default=0.9)
    parser.add_argument("--min-threshold-class-samples", type=int, default=8)
    return parser.parse_args()


def parse_ts(value: str) -> datetime:
    return datetime.fromisoformat(value.replace("Z", "+00:00"))


def as_int(value: object) -> Optional[int]:
    if value is None:
        return None
    text = str(value).strip()
    if text == "" or text.lower() == "nan":
        return None
    try:
        return int(text)
    except ValueError:
        return None


def as_bool(value: object) -> bool:
    if value is None:
        return False
    text = str(value).strip().lower()
    return text in {"1", "true", "t", "yes", "y"}


def trim_queue_time(q: Deque[Tuple[datetime, object]], now_ts: datetime, window: timedelta) -> None:
    cutoff = now_ts - window
    while q and q[0][0] < cutoff:
        q.popleft()


def load_csv(path: Path) -> List[Dict[str, str]]:
    with path.open("r", encoding="utf-8", newline="") as f:
        reader = csv.DictReader(f)
        return list(reader)


def build_features(rows: List[Dict[str, str]]) -> List[Dict[str, object]]:
    parsed_rows = []
    for row in rows:
        ts_raw = row.get("ts", "")
        if not ts_raw:
            continue
        row = dict(row)
        row["ts_dt"] = parse_ts(ts_raw)
        parsed_rows.append(row)
    parsed_rows.sort(key=lambda r: r["ts_dt"])

    states: Dict[str, WindowState] = defaultdict(
        lambda: WindowState(failed_logins=deque(), req_events=deque(), paths=deque())
    )
    out: List[Dict[str, object]] = []

    for idx, row in enumerate(parsed_rows):
        ts = row["ts_dt"]  # type: ignore[assignment]
        ip = str(row.get("ip", "") or "")
        event_type = str(row.get("event_type", "") or "")
        method = str(row.get("method", "") or "")
        path = str(row.get("path", "") or "")
        status = as_int(row.get("status"))
        email_hash = str(row.get("email_hash", "") or "")

        st = states[ip]
        if event_type == "auth_login_failed":
            st.failed_logins.append((ts, email_hash))
        st.req_events.append((ts, event_type, status))
        st.paths.append((ts, path))

        trim_queue_time(st.failed_logins, ts, timedelta(minutes=10))
        trim_queue_time(st.req_events, ts, timedelta(minutes=5))
        trim_queue_time(st.paths, ts, timedelta(minutes=2))

        failed_1m = sum(1 for t, _ in st.failed_logins if t >= ts - timedelta(minutes=1))
        failed_5m = sum(1 for t, _ in st.failed_logins if t >= ts - timedelta(minutes=5))
        failed_10m = len(st.failed_logins)
        unique_email_10m = len({e for _, e in st.failed_logins if e})
        req_1m = sum(1 for t, _, _ in st.req_events if t >= ts - timedelta(minutes=1))
        req_5m = len(st.req_events)
        notfound_2m = sum(
            1 for t, e, s in st.req_events if t >= ts - timedelta(minutes=2) and e == "http_request" and s == 404
        )
        unique_paths_2m = len({p for t, p in st.paths if t >= ts - timedelta(minutes=2) and p})

        label = str(row.get("label", "normal") or "normal").lower()
        if label not in CLASSES:
            label = "normal"

        out.append(
            {
                "row_id": idx,
                "ts": row["ts"],
                "ts_dt": ts,
                "ip": ip,
                "request_id": str(row.get("request_id", "") or ""),
                "attack_run_id": str(row.get("attack_run_id", "") or ""),
                "event_type": event_type,
                "method": method,
                "path": path,
                "status": status or 0,
                "latency_ms": as_int(row.get("latency_ms")) or 0,
                "has_sql_keywords": 1 if as_bool(row.get("has_sql_keywords")) else 0,
                "has_script_payload": 1 if as_bool(row.get("has_script_payload")) else 0,
                "path_len": len(path),
                "path_depth": path.count("/"),
                "is_admin_path": 1 if path == "/admin" else 0,
                "is_scan_like_path": 1 if path.startswith("/scan/") else 0,
                "is_sensitive_probe": 1 if path in {"/.env", "/phpMyAdmin", "/wp-admin", "/vendor"} else 0,
                "failed_1m": failed_1m,
                "failed_5m": failed_5m,
                "failed_10m": failed_10m,
                "unique_email_10m": unique_email_10m,
                "req_1m": req_1m,
                "req_5m": req_5m,
                "notfound_2m": notfound_2m,
                "unique_paths_2m": unique_paths_2m,
                "label": label,
            }
        )

    return out


def split_indices(rows: List[Dict[str, object]], split_mode: str, train_ratio: float) -> Tuple[List[int], List[int]]:
    train_ratio = min(max(train_ratio, 0.1), 0.95)
    n = len(rows)
    if n == 0:
        return [], []

    if split_mode == "time":
        split_idx = int(n * train_ratio)
        train_idx = list(range(split_idx))
        test_idx = list(range(split_idx, n))
        return ensure_label_coverage(rows, train_idx, test_idx)

    grouped: Dict[str, List[int]] = defaultdict(list)
    normal_chunk_size = 25
    normal_counter = 0

    for i, row in enumerate(rows):
        run_id = str(row.get("attack_run_id", "") or "").strip()
        if run_id and run_id.lower() not in {"none", "nan"}:
            key = f"run:{run_id}"
        else:
            key = f"normal_chunk:{normal_counter // normal_chunk_size}"
            normal_counter += 1
        grouped[key].append(i)

    group_items = []
    for key, idxs in grouped.items():
        first_ts = rows[idxs[0]]["ts_dt"]  # type: ignore[index]
        labels = Counter(str(rows[i].get("label", "normal")) for i in idxs)
        majority_label = labels.most_common(1)[0][0] if labels else "normal"
        group_items.append((key, first_ts, idxs, majority_label))
    group_items.sort(key=lambda x: x[1])

    # Split by attack run to avoid leakage, but stratify by the group's majority
    # label so rare attack classes are present in both train and test when possible.
    by_label: Dict[str, List[Tuple[str, object, List[int], str]]] = defaultdict(list)
    for item in group_items:
        by_label[item[3]].append(item)

    train_idx = []
    test_idx = []
    for _label, items in sorted(by_label.items()):
        items.sort(key=lambda x: x[1])
        if len(items) == 1:
            idxs = list(items[0][2])
            split_idx = min(max(int(len(idxs) * train_ratio), 1), max(len(idxs) - 1, 1))
            train_idx.extend(idxs[:split_idx])
            test_idx.extend(idxs[split_idx:])
            continue
        split_group = min(max(int(round(len(items) * train_ratio)), 1), len(items) - 1)
        train_idx.extend(i for _, _, idxs, _ in items[:split_group] for i in idxs)
        test_idx.extend(i for _, _, idxs, _ in items[split_group:] for i in idxs)
    return ensure_label_coverage(rows, train_idx, test_idx)


def ensure_label_coverage(
    rows: List[Dict[str, object]], train_idx: List[int], test_idx: List[int]
) -> Tuple[List[int], List[int]]:
    train_set = set(train_idx)
    test_set = set(test_idx)

    train_labels = Counter(str(rows[i].get("label", "normal")) for i in train_idx)
    test_labels = Counter(str(rows[i].get("label", "normal")) for i in test_idx)

    # Ensure train has at least one sample per class when available in full data.
    for cls in CLASSES:
        full_has = any(str(r.get("label", "normal")) == cls for r in rows)
        if not full_has:
            continue
        if train_labels.get(cls, 0) > 0:
            continue
        candidate = next((i for i in test_idx if str(rows[i].get("label", "normal")) == cls), None)
        if candidate is not None:
            train_set.add(candidate)
            test_set.discard(candidate)
            train_labels[cls] += 1
            test_labels[cls] -= 1

    # Keep test meaningful: for classes with >1 samples in full data, prefer >=1 in test.
    for cls in CLASSES:
        full_count = sum(1 for r in rows if str(r.get("label", "normal")) == cls)
        if full_count <= 1:
            continue
        if test_labels.get(cls, 0) > 0:
            continue
        candidate = next((i for i in train_idx if str(rows[i].get("label", "normal")) == cls and i in train_set), None)
        if candidate is not None:
            test_set.add(candidate)
            train_set.discard(candidate)
            test_labels[cls] += 1
            train_labels[cls] -= 1

    train_out = sorted(train_set)
    test_out = sorted(test_set)
    return train_out, test_out


def build_vectorizer(train_rows: Sequence[Dict[str, object]]) -> Dict[str, object]:
    categorical = ["event_type", "method"]
    numeric = [
        "status",
        "latency_ms",
        "has_sql_keywords",
        "has_script_payload",
        "path_len",
        "path_depth",
        "is_admin_path",
        "is_scan_like_path",
        "is_sensitive_probe",
        "failed_1m",
        "failed_5m",
        "failed_10m",
        "unique_email_10m",
        "req_1m",
        "req_5m",
        "notfound_2m",
        "unique_paths_2m",
    ]

    cat_maps: Dict[str, Dict[str, int]] = {}
    offset = 0
    for col in categorical:
        values = sorted({str(r.get(col, "") or "") for r in train_rows})
        mapping = {v: i for i, v in enumerate(values)}
        cat_maps[col] = mapping
        offset += len(values)

    means: Dict[str, float] = {}
    stds: Dict[str, float] = {}
    for col in numeric:
        vals = [float(r.get(col, 0) or 0) for r in train_rows]
        mu = sum(vals) / max(len(vals), 1)
        var = sum((v - mu) ** 2 for v in vals) / max(len(vals), 1)
        sd = math.sqrt(var) if var > 0 else 1.0
        means[col] = mu
        stds[col] = sd
    num_offset = offset
    dim = offset + len(numeric)

    return {
        "categorical": categorical,
        "numeric": numeric,
        "cat_maps": cat_maps,
        "means": means,
        "stds": stds,
        "num_offset": num_offset,
        "dim": dim,
    }


def vectorize_row(row: Dict[str, object], vcfg: Dict[str, object]) -> List[float]:
    dim = int(vcfg["dim"])
    vec = [0.0] * dim
    offset = 0

    categorical = vcfg["categorical"]  # type: ignore[assignment]
    cat_maps = vcfg["cat_maps"]  # type: ignore[assignment]
    for col in categorical:
        mapping = cat_maps[col]
        value = str(row.get(col, "") or "")
        if value in mapping:
            vec[offset + mapping[value]] = 1.0
        offset += len(mapping)

    numeric = vcfg["numeric"]  # type: ignore[assignment]
    means = vcfg["means"]  # type: ignore[assignment]
    stds = vcfg["stds"]  # type: ignore[assignment]
    for i, col in enumerate(numeric):
        val = float(row.get(col, 0) or 0)
        vec[offset + i] = (val - means[col]) / (stds[col] if stds[col] > 0 else 1.0)
    return vec


def softmax(logits: List[float]) -> List[float]:
    m = max(logits)
    exps = [math.exp(v - m) for v in logits]
    s = sum(exps)
    if s == 0:
        return [1.0 / len(logits)] * len(logits)
    return [e / s for e in exps]


def train_logreg(
    X: List[List[float]],
    y_idx: List[int],
    epochs: int,
    lr: float,
    l2: float,
    class_weights: List[float],
    seed: int,
) -> Tuple[List[List[float]], List[float]]:
    random.seed(seed)
    n = len(X)
    d = len(X[0]) if X else 0
    c = len(CLASSES)
    W = [[0.0 for _ in range(d)] for _ in range(c)]
    b = [0.0 for _ in range(c)]

    indices = list(range(n))
    for _epoch in range(epochs):
        random.shuffle(indices)
        for idx in indices:
            x = X[idx]
            t = y_idx[idx]
            logits = [sum(W[k][j] * x[j] for j in range(d)) + b[k] for k in range(c)]
            probs = softmax(logits)

            for k in range(c):
                target = 1.0 if k == t else 0.0
                err = (probs[k] - target) * class_weights[t]
                for j in range(d):
                    grad = err * x[j] + l2 * W[k][j]
                    W[k][j] -= lr * grad
                b[k] -= lr * err
    return W, b


def predict_logreg(X: List[List[float]], W: List[List[float]], b: List[float]) -> List[int]:
    preds: List[int] = []
    c = len(W)
    d = len(W[0]) if W else 0
    for x in X:
        logits = [sum(W[k][j] * x[j] for j in range(d)) + b[k] for k in range(c)]
        preds.append(max(range(c), key=lambda k: logits[k]))
    return preds


def predict_logreg_proba(X: List[List[float]], W: List[List[float]], b: List[float]) -> List[List[float]]:
    out: List[List[float]] = []
    c = len(W)
    d = len(W[0]) if W else 0
    for x in X:
        logits = [sum(W[k][j] * x[j] for j in range(d)) + b[k] for k in range(c)]
        out.append(softmax(logits))
    return out


def predict_with_thresholds(probs: List[List[float]], thresholds: Dict[str, float]) -> Tuple[List[int], List[float]]:
    pred_idx: List[int] = []
    pred_score: List[float] = []
    normal_idx = CLASSES.index("normal")
    for row in probs:
        idx = max(range(len(row)), key=lambda k: row[k])
        score = float(row[idx])
        label = CLASSES[idx]
        if label != "normal" and score < float(thresholds.get(label, 0.0)):
            idx = normal_idx
        pred_idx.append(idx)
        pred_score.append(score)
    return pred_idx, pred_score


def feature_names(vcfg: Dict[str, object]) -> List[str]:
    names: List[str] = []
    cat_maps = vcfg["cat_maps"]  # type: ignore[assignment]
    for col in vcfg["categorical"]:  # type: ignore[index]
        mapping = cat_maps[col]
        for value, _idx in sorted(mapping.items(), key=lambda item: item[1]):
            names.append(f"{col}={value}")
    names.extend(str(n) for n in vcfg["numeric"])  # type: ignore[index]
    return names


def feature_importance_report(W: List[List[float]], vcfg: Dict[str, object], top_n: int = 12) -> Dict[str, object]:
    names = feature_names(vcfg)
    overall = []
    for j, name in enumerate(names):
        vals = [abs(W[k][j]) for k in range(len(W)) if j < len(W[k])]
        score = sum(vals) / len(vals) if vals else 0.0
        overall.append({"feature": name, "mean_abs_weight": round(score, 6)})
    overall.sort(key=lambda x: float(x["mean_abs_weight"]), reverse=True)

    by_class: Dict[str, object] = {}
    for cls_idx, cls in enumerate(CLASSES):
        weights = W[cls_idx] if cls_idx < len(W) else []
        scored = [(names[j], weights[j]) for j in range(min(len(names), len(weights)))]
        top_positive = sorted(scored, key=lambda x: x[1], reverse=True)[:top_n]
        top_negative = sorted(scored, key=lambda x: x[1])[:top_n]
        by_class[cls] = {
            "top_positive": [{"feature": n, "weight": round(float(w), 6)} for n, w in top_positive],
            "top_negative": [{"feature": n, "weight": round(float(w), 6)} for n, w in top_negative],
        }

    return {
        "top_overall": overall[:top_n],
        "by_class": by_class,
    }


def learn_attack_thresholds(
    y_true: List[str],
    probs: List[List[float]],
    floor: float,
    target_precision: float,
    min_class_samples: int,
) -> Dict[str, float]:
    thresholds: Dict[str, float] = {"normal": 0.0}
    floor = min(max(floor, 0.0), 0.99)
    target_precision = min(max(target_precision, 0.0), 1.0)

    for cls in CLASSES:
        if cls == "normal":
            continue
        if sum(1 for y in y_true if y == cls) < min_class_samples:
            thresholds[cls] = round(float(floor), 6)
            continue
        cls_idx = CLASSES.index(cls)
        candidates = sorted({round(float(p[cls_idx]), 6) for p in probs}, reverse=True)
        selected = floor
        for threshold in candidates:
            if threshold < floor:
                break
            predicted_positive = [i for i, p in enumerate(probs) if p[cls_idx] >= threshold]
            if not predicted_positive:
                continue
            tp = sum(1 for i in predicted_positive if y_true[i] == cls)
            precision = tp / len(predicted_positive)
            if precision >= target_precision:
                selected = threshold
            else:
                break
        thresholds[cls] = round(float(selected), 6)
    return thresholds


def confidence_analysis(y_true: List[str], y_pred: List[str], scores: List[float], bins: int = 10) -> Dict[str, object]:
    if not y_true:
        return {"bins": [], "overall": {"avg_confidence": 0.0, "accuracy": 0.0}}

    bins = max(2, bins)
    bucket_rows = []
    for bidx in range(bins):
        lo = bidx / bins
        hi = (bidx + 1) / bins
        idxs = [
            i
            for i, score in enumerate(scores)
            if (score >= lo and (score < hi or (bidx == bins - 1 and score <= hi)))
        ]
        if not idxs:
            bucket_rows.append({"range": [round(lo, 2), round(hi, 2)], "samples": 0})
            continue
        acc = sum(1 for i in idxs if y_true[i] == y_pred[i]) / len(idxs)
        avg_conf = sum(scores[i] for i in idxs) / len(idxs)
        bucket_rows.append(
            {
                "range": [round(lo, 2), round(hi, 2)],
                "samples": len(idxs),
                "accuracy": round(acc, 6),
                "avg_confidence": round(avg_conf, 6),
            }
        )

    overall_acc = sum(1 for t, p in zip(y_true, y_pred) if t == p) / len(y_true)
    overall_conf = sum(scores) / len(scores) if scores else 0.0
    return {
        "overall": {
            "avg_confidence": round(overall_conf, 6),
            "accuracy": round(overall_acc, 6),
        },
        "bins": bucket_rows,
    }


def confusion_matrix(y_true: List[str], y_pred: List[str]) -> Dict[str, Dict[str, int]]:
    cm = {t: {p: 0 for p in CLASSES} for t in CLASSES}
    for t, p in zip(y_true, y_pred):
        if t not in cm:
            t = "normal"
        if p not in cm[t]:
            p = "normal"
        cm[t][p] += 1
    return cm


def metrics_from_confusion(cm: Dict[str, Dict[str, int]]) -> Dict[str, object]:
    total = sum(cm[t][p] for t in CLASSES for p in CLASSES)
    correct = sum(cm[c][c] for c in CLASSES)
    accuracy = correct / total if total else 0.0

    per_class: Dict[str, Dict[str, float]] = {}
    f1_vals = []
    weighted_sum = 0.0
    for cls in CLASSES:
        tp = cm[cls][cls]
        fp = sum(cm[t][cls] for t in CLASSES if t != cls)
        fn = sum(cm[cls][p] for p in CLASSES if p != cls)
        support = sum(cm[cls][p] for p in CLASSES)
        precision = tp / (tp + fp) if (tp + fp) else 0.0
        recall = tp / (tp + fn) if (tp + fn) else 0.0
        f1 = (2 * precision * recall / (precision + recall)) if (precision + recall) else 0.0
        per_class[cls] = {
            "precision": round(precision, 6),
            "recall": round(recall, 6),
            "f1": round(f1, 6),
            "support": support,
        }
        f1_vals.append(f1)
        weighted_sum += f1 * support

    macro_f1 = sum(f1_vals) / len(CLASSES) if CLASSES else 0.0
    weighted_f1 = weighted_sum / total if total else 0.0

    return {
        "accuracy": round(accuracy, 6),
        "per_class": per_class,
        "macro_avg_f1": round(macro_f1, 6),
        "weighted_avg_f1": round(weighted_f1, 6),
    }


def detection_latency_analysis(test_rows: List[Dict[str, object]], y_pred: List[str]) -> Dict[str, object]:
    enriched = [dict(r, pred=p) for r, p in zip(test_rows, y_pred)]
    run_groups: Dict[str, List[Dict[str, object]]] = defaultdict(list)
    for row in enriched:
        if str(row.get("label", "")) in ATTACK_CLASSES:
            run_id = str(row.get("attack_run_id", "") or "").strip()
            if run_id and run_id.lower() not in {"none", "nan"}:
                run_groups[run_id].append(row)

    lat_any: List[float] = []
    lat_correct: List[float] = []
    by_type_any: Dict[str, List[float]] = defaultdict(list)
    by_type_correct: Dict[str, List[float]] = defaultdict(list)

    for _, group in run_groups.items():
        group.sort(key=lambda r: r["ts_dt"])  # type: ignore[index]
        attack_type = str(group[0].get("label", "normal"))
        start = group[0]["ts_dt"]  # type: ignore[index]
        any_hit = next((r for r in group if r.get("pred") != "normal"), None)
        correct_hit = next((r for r in group if r.get("pred") == attack_type), None)

        if any_hit is not None:
            sec = (any_hit["ts_dt"] - start).total_seconds()  # type: ignore[index]
            lat_any.append(sec)
            by_type_any[attack_type].append(sec)
        if correct_hit is not None:
            sec = (correct_hit["ts_dt"] - start).total_seconds()  # type: ignore[index]
            lat_correct.append(sec)
            by_type_correct[attack_type].append(sec)

    def summarize(vals: List[float]) -> Dict[str, float]:
        if not vals:
            return {"count": 0, "mean": -1.0, "median": -1.0, "p95": -1.0}
        vals_sorted = sorted(vals)
        p95_idx = int(0.95 * (len(vals_sorted) - 1))
        return {
            "count": len(vals),
            "mean": round(float(mean(vals)), 6),
            "median": round(float(median(vals)), 6),
            "p95": round(float(vals_sorted[p95_idx]), 6),
        }

    return {
        "runs_evaluated": len(run_groups),
        "overall_seconds": {
            "first_attack_prediction": summarize(lat_any),
            "first_correct_class_prediction": summarize(lat_correct),
        },
        "by_attack_type": {
            at: {
                "first_attack_prediction": summarize(by_type_any.get(at, [])),
                "first_correct_class_prediction": summarize(by_type_correct.get(at, [])),
            }
            for at in sorted(ATTACK_CLASSES)
        },
    }


def scenario_eval(test_rows: List[Dict[str, object]], y_pred: List[str]) -> Dict[str, object]:
    rows = [dict(r, pred=p) for r, p in zip(test_rows, y_pred)]

    def subset(name: str, filt) -> Dict[str, object]:
        sub = [r for r in rows if filt(r)]
        if not sub:
            return {"name": name, "samples": 0}
        y_true = [str(r["label"]) for r in sub]
        y_hat = [str(r["pred"]) for r in sub]
        cm = confusion_matrix(y_true, y_hat)
        m = metrics_from_confusion(cm)
        attack_true = sum(1 for y in y_true if y in ATTACK_CLASSES)
        attack_detected = sum(1 for t, p in zip(y_true, y_hat) if t in ATTACK_CLASSES and p in ATTACK_CLASSES)
        return {
            "name": name,
            "samples": len(sub),
            "accuracy": m["accuracy"],
            "attack_recall_binary": round((attack_detected / attack_true) if attack_true else 0.0, 6),
        }

    return {
        "low_and_slow": subset(
            "low_and_slow",
            lambda r: str(r["label"]) in ATTACK_CLASSES and int(r["req_1m"]) <= 3 and int(r["failed_1m"]) <= 3,
        ),
        "burst": subset(
            "burst",
            lambda r: str(r["label"]) in ATTACK_CLASSES
            and (int(r["req_1m"]) >= 8 or int(r["failed_1m"]) >= 8 or int(r["notfound_2m"]) >= 8),
        ),
        "scan_generalization_new_paths": subset(
            "scan_generalization_new_paths",
            lambda r: str(r["label"]) == "scan" and int(r["is_scan_like_path"]) == 1,
        ),
    }


def main() -> int:
    args = parse_args()
    if args.model == "rf":
        print("WARNING: 'rf' requested, but dependency-free mode uses logistic regression fallback.")

    input_path = Path(args.input).resolve()
    model_path = Path(args.output_model).resolve()
    report_path = Path(args.output_report).resolve()
    pred_path = Path(args.output_predictions).resolve()
    importance_path = Path(args.output_feature_importance).resolve()
    model_card_path = Path(args.output_model_card).resolve()

    if not input_path.exists():
        print(f"ERROR: dataset not found: {input_path}")
        return 1

    raw_rows = load_csv(input_path)
    rows = build_features(raw_rows)
    train_idx, test_idx = split_indices(rows, args.split_mode, args.train_ratio)
    if not train_idx or not test_idx:
        print("ERROR: split produced empty train/test set.")
        return 1

    train_rows = [rows[i] for i in train_idx]
    test_rows = [rows[i] for i in test_idx]

    vcfg = build_vectorizer(train_rows)
    X_train = [vectorize_row(r, vcfg) for r in train_rows]
    X_test = [vectorize_row(r, vcfg) for r in test_rows]

    y_train = [str(r["label"]) for r in train_rows]
    y_test = [str(r["label"]) for r in test_rows]
    y_train_idx = [CLASSES.index(y) if y in CLASSES else 0 for y in y_train]

    counts = Counter(y_train_idx)
    class_weights = []
    for i in range(len(CLASSES)):
        class_weights.append((len(y_train_idx) / max(counts.get(i, 1), 1)) / len(CLASSES))

    W, b = train_logreg(
        X_train,
        y_train_idx,
        epochs=args.epochs,
        lr=args.lr,
        l2=args.l2,
        class_weights=class_weights,
        seed=args.random_seed,
    )

    train_probs = predict_logreg_proba(X_train, W, b)
    decision_thresholds = learn_attack_thresholds(
        y_train,
        train_probs,
        floor=args.attack_threshold_floor,
        target_precision=args.target_attack_precision,
        min_class_samples=args.min_threshold_class_samples,
    )
    test_probs = predict_logreg_proba(X_test, W, b)
    y_pred_idx, y_pred_score = predict_with_thresholds(test_probs, decision_thresholds)
    y_pred = [CLASSES[i] for i in y_pred_idx]

    cm = confusion_matrix(y_test, y_pred)
    metrics = metrics_from_confusion(cm)
    latency = detection_latency_analysis(test_rows, y_pred)
    scenario = scenario_eval(test_rows, y_pred)
    importance = feature_importance_report(W, vcfg)
    calibration = confidence_analysis(y_test, y_pred, y_pred_score)

    fp = [dict(r, pred=p) for r, p in zip(test_rows, y_pred) if str(r["label"]) == "normal" and p != "normal"]
    fn = [dict(r, pred=p) for r, p in zip(test_rows, y_pred) if str(r["label"]) != "normal" and p == "normal"]

    model_obj = {
        "model_type": "logreg",
        "classes": CLASSES,
        "vectorizer": vcfg,
        "weights": W,
        "bias": b,
        "decision_thresholds": decision_thresholds,
        "feature_importance": importance,
        "metrics": metrics,
        "created_at": datetime.now().astimezone().isoformat(),
        "train_config": {
            "epochs": args.epochs,
            "lr": args.lr,
            "l2": args.l2,
            "split_mode": args.split_mode,
            "train_ratio": args.train_ratio,
            "attack_threshold_floor": args.attack_threshold_floor,
            "target_attack_precision": args.target_attack_precision,
            "min_threshold_class_samples": args.min_threshold_class_samples,
        },
    }
    model_path.parent.mkdir(parents=True, exist_ok=True)
    with model_path.open("wb") as f:
        pickle.dump(model_obj, f)

    pred_path.parent.mkdir(parents=True, exist_ok=True)
    with pred_path.open("w", encoding="utf-8", newline="") as f:
        writer = csv.writer(f)
        writer.writerow(
            [
                "ts",
                "ip",
                "event_type",
                "path",
                "status",
                "label",
                "pred",
                "confidence",
                *[f"proba_{cls}" for cls in CLASSES],
                "attack_run_id",
                "failed_1m",
                "failed_5m",
                "notfound_2m",
                "unique_paths_2m",
            ]
        )
        for r, p, score, probs in zip(test_rows, y_pred, y_pred_score, test_probs):
            writer.writerow(
                [
                    r["ts"],
                    r["ip"],
                    r["event_type"],
                    r["path"],
                    r["status"],
                    r["label"],
                    p,
                    round(score, 8),
                    *[round(float(v), 8) for v in probs],
                    r["attack_run_id"],
                    r["failed_1m"],
                    r["failed_5m"],
                    r["notfound_2m"],
                    r["unique_paths_2m"],
                ]
            )

    importance_path.parent.mkdir(parents=True, exist_ok=True)
    importance_path.write_text(json.dumps(importance, indent=2), encoding="utf-8")

    model_card = {
        "model_name": "application-log-attack-detector",
        "model_type": "multiclass_logistic_regression",
        "generated_at": datetime.now().astimezone().isoformat(),
        "intended_use": "Near-real-time classification of Laravel application security events.",
        "not_intended_for": [
            "Replacing WAF controls without human-reviewed response policy",
            "Network-layer IDS data that does not use the trained application-log schema",
        ],
        "classes": CLASSES,
        "input_schema": {
            "categorical": vcfg["categorical"],
            "numeric": vcfg["numeric"],
        },
        "decision_thresholds": decision_thresholds,
        "metrics": metrics,
        "calibration": calibration,
        "known_limitations": [
            "Performance depends on event schema consistency between training and runtime.",
            "New attack families outside the trained labels require labeling and retraining.",
            "Thresholds are learned from the training split and should be reviewed after production drift.",
        ],
    }
    model_card_path.parent.mkdir(parents=True, exist_ok=True)
    model_card_path.write_text(json.dumps(model_card, indent=2), encoding="utf-8")

    report_obj = {
        "generated_at": datetime.now().astimezone().isoformat(),
        "input": str(input_path),
        "model_artifact": str(model_path),
        "predictions_file": str(pred_path),
        "feature_importance_file": str(importance_path),
        "model_card_file": str(model_card_path),
        "model_type": "logreg",
        "split_mode": args.split_mode,
        "train_ratio": args.train_ratio,
        "decision_thresholds": decision_thresholds,
        "samples": {
            "total": len(rows),
            "train": len(train_rows),
            "test": len(test_rows),
            "train_label_distribution": dict(Counter(y_train)),
            "test_label_distribution": dict(Counter(y_test)),
        },
        "metrics": metrics,
        "confusion_matrix": cm,
        "detection_latency": latency,
        "scenario_eval": scenario,
        "calibration": calibration,
        "feature_importance": importance,
        "error_analysis": {
            "false_positive_count": len(fp),
            "false_negative_count": len(fn),
            "false_positive_examples": [
                {
                    "ts": r["ts"],
                    "ip": r["ip"],
                    "event_type": r["event_type"],
                    "path": r["path"],
                    "status": r["status"],
                    "label": r["label"],
                    "pred": r["pred"],
                }
                for r in fp[:25]
            ],
            "false_negative_examples": [
                {
                    "ts": r["ts"],
                    "ip": r["ip"],
                    "event_type": r["event_type"],
                    "path": r["path"],
                    "status": r["status"],
                    "label": r["label"],
                    "pred": r["pred"],
                    "attack_run_id": r["attack_run_id"],
                }
                for r in fn[:25]
            ],
        },
    }
    report_path.parent.mkdir(parents=True, exist_ok=True)
    report_path.write_text(json.dumps(report_obj, indent=2), encoding="utf-8")

    print(f"Model saved: {model_path}")
    print(f"Report saved: {report_path}")
    print(f"Feature importance saved: {importance_path}")
    print(f"Model card saved: {model_card_path}")
    print(f"Accuracy: {metrics['accuracy']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
