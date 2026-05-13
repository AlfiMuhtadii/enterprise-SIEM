#!/usr/bin/env python3
"""
Phase 10 evaluation harness (AI + Networking thesis core).

- Split by attack_run to avoid leakage.
- Compare 3 baselines: rules-only, ML-only, hybrid.
- Report: per-class metrics, confusion matrix, ROC-AUC (OvR), detection latency,
  stress scenarios (low-slow/burst/noise/injection-variant), and FP/FN interpretation.
"""

from __future__ import annotations

import argparse
import csv
import json
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Sequence, Tuple

from train_ai_detector import (
    CLASSES,
    ATTACK_CLASSES,
    build_features,
    build_vectorizer,
    load_csv,
    metrics_from_confusion,
    predict_logreg,
    softmax,
    split_indices,
    train_logreg,
    vectorize_row,
)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Phase 10 evaluation harness")
    parser.add_argument("--input", default="storage/app/security_dataset.csv")
    parser.add_argument("--output-dir", default="reports/phase10")
    parser.add_argument("--epochs", type=int, default=120)
    parser.add_argument("--lr", type=float, default=0.08)
    parser.add_argument("--l2", type=float, default=0.0001)
    parser.add_argument("--seed", type=int, default=42)
    return parser.parse_args()


def confusion_matrix(y_true: Sequence[str], y_pred: Sequence[str]) -> Dict[str, Dict[str, int]]:
    cm = {t: {p: 0 for p in CLASSES} for t in CLASSES}
    for t, p in zip(y_true, y_pred):
        if t not in cm:
            t = "normal"
        if p not in cm[t]:
            p = "normal"
        cm[t][p] += 1
    return cm


def rule_predict(row: Dict[str, object]) -> Tuple[str, List[float]]:
    has_sql = int(row.get("has_sql_keywords", 0) or 0) == 1
    has_script = int(row.get("has_script_payload", 0) or 0) == 1
    path = str(row.get("path", "") or "")
    status = int(row.get("status", 0) or 0)
    failed_5m = int(row.get("failed_5m", 0) or 0)
    failed_10m = int(row.get("failed_10m", 0) or 0)
    unique_email_10m = int(row.get("unique_email_10m", 0) or 0)
    notfound_2m = int(row.get("notfound_2m", 0) or 0)
    unique_paths_2m = int(row.get("unique_paths_2m", 0) or 0)

    if has_sql or has_script:
        label = "injection"
    elif failed_5m >= 15 or (failed_10m >= 20 and unique_email_10m >= 10):
        label = "bruteforce"
    elif notfound_2m >= 20 or unique_paths_2m >= 20 or (path == "/admin" and status == 403):
        label = "scan"
    else:
        label = "normal"

    probs = [0.0] * len(CLASSES)
    probs[CLASSES.index(label)] = 1.0
    return label, probs


def ml_predict_with_proba(x: List[float], W: List[List[float]], b: List[float]) -> Tuple[str, List[float]]:
    logits = [sum(W[k][j] * x[j] for j in range(len(x))) + b[k] for k in range(len(CLASSES))]
    probs = softmax(logits)
    pred_idx = max(range(len(probs)), key=lambda i: probs[i])
    return CLASSES[pred_idx], probs


def hybrid_predict(rule_label: str, rule_probs: List[float], ml_label: str, ml_probs: List[float]) -> Tuple[str, List[float]]:
    if rule_label != "normal":
        return rule_label, rule_probs
    return ml_label, ml_probs


def roc_auc_binary(y_true: List[int], y_score: List[float]) -> float:
    pos = sum(y_true)
    neg = len(y_true) - pos
    if pos == 0 or neg == 0:
        return 0.0
    order = sorted(range(len(y_score)), key=lambda i: y_score[i])
    ranks = [0.0] * len(y_score)
    i = 0
    while i < len(order):
        j = i
        while j + 1 < len(order) and y_score[order[j + 1]] == y_score[order[i]]:
            j += 1
        avg_rank = (i + j + 2) / 2.0
        for k in range(i, j + 1):
            ranks[order[k]] = avg_rank
        i = j + 1
    rank_sum_pos = sum(ranks[i] for i, y in enumerate(y_true) if y == 1)
    return float((rank_sum_pos - pos * (pos + 1) / 2.0) / (pos * neg))


def roc_auc_ovr(y_true: List[str], probs: List[List[float]]) -> Dict[str, float]:
    out: Dict[str, float] = {}
    aucs = []
    for cls_idx, cls_name in enumerate(CLASSES):
        y_bin = [1 if y == cls_name else 0 for y in y_true]
        scores = [p[cls_idx] for p in probs]
        auc = roc_auc_binary(y_bin, scores)
        out[cls_name] = round(auc, 6)
        aucs.append(auc)
    out["macro_avg"] = round(sum(aucs) / len(aucs), 6)
    return out


def detection_latency(test_rows: List[Dict[str, object]], preds: List[str]) -> Dict[str, float]:
    groups: Dict[str, List[Tuple[datetime, str, str]]] = defaultdict(list)
    for row, pred in zip(test_rows, preds):
        true = str(row.get("label", "normal"))
        run_id = str(row.get("attack_run_id", "") or "").strip()
        if true in ATTACK_CLASSES and run_id and run_id.lower() not in {"nan", "none"}:
            groups[run_id].append((row["ts_dt"], true, pred))  # type: ignore[index]

    lat_any: List[float] = []
    lat_correct: List[float] = []
    for seq in groups.values():
        seq.sort(key=lambda x: x[0])
        start, attack_type, _ = seq[0]
        first_any = next((t for t, _, p in seq if p != "normal"), None)
        first_correct = next((t for t, true, p in seq if true == p), None)
        if first_any is not None:
            lat_any.append((first_any - start).total_seconds())
        if first_correct is not None:
            lat_correct.append((first_correct - start).total_seconds())

    def mean_or_neg(vals: List[float]) -> float:
        if not vals:
            return -1.0
        return round(sum(vals) / len(vals), 6)

    return {
        "runs": len(groups),
        "mean_first_alert_sec": mean_or_neg(lat_any),
        "mean_first_correct_sec": mean_or_neg(lat_correct),
    }


def eval_subset(name: str, rows: List[Dict[str, object]], true_labels: List[str], pred_labels: List[str]) -> Dict[str, object]:
    if not rows:
        return {"name": name, "samples": 0}
    cm = confusion_matrix(true_labels, pred_labels)
    m = metrics_from_confusion(cm)
    attack_true = sum(1 for y in true_labels if y in ATTACK_CLASSES)
    attack_detect = sum(1 for t, p in zip(true_labels, pred_labels) if t in ATTACK_CLASSES and p in ATTACK_CLASSES)
    return {
        "name": name,
        "samples": len(rows),
        "accuracy": m["accuracy"],
        "attack_recall_binary": round((attack_detect / attack_true) if attack_true else 0.0, 6),
    }


def write_confusion_csv(path: Path, cm: Dict[str, Dict[str, int]]) -> None:
    with path.open("w", encoding="utf-8", newline="") as f:
        w = csv.writer(f)
        w.writerow(["true\\pred"] + CLASSES)
        for t in CLASSES:
            w.writerow([t] + [cm[t][p] for p in CLASSES])


def write_simple_bar_svg(path: Path, title: str, labels: List[str], values: List[float], color: str) -> None:
    width = 760
    height = 320
    margin = 50
    bar_w = (width - 2 * margin) / max(len(values), 1)
    max_v = max(values) if values else 1.0
    max_v = max(max_v, 1e-9)

    bars = []
    texts = []
    for i, (label, val) in enumerate(zip(labels, values)):
        x = margin + i * bar_w + 8
        h = (height - 2 * margin) * (val / max_v)
        y = height - margin - h
        bars.append(f'<rect x="{x:.2f}" y="{y:.2f}" width="{bar_w-16:.2f}" height="{h:.2f}" fill="{color}" rx="4" />')
        texts.append(f'<text x="{x + (bar_w-16)/2:.2f}" y="{height-margin+16}" font-size="12" text-anchor="middle">{label}</text>')
        texts.append(f'<text x="{x + (bar_w-16)/2:.2f}" y="{y-6:.2f}" font-size="11" text-anchor="middle">{val:.3f}</text>')

    svg = f"""<svg xmlns="http://www.w3.org/2000/svg" width="{width}" height="{height}">
<rect width="100%" height="100%" fill="#ffffff"/>
<text x="{margin}" y="26" font-size="18" font-family="Arial" fill="#0f172a">{title}</text>
<line x1="{margin}" y1="{height-margin}" x2="{width-margin}" y2="{height-margin}" stroke="#334155" stroke-width="1"/>
{''.join(bars)}
{''.join(texts)}
</svg>"""
    path.write_text(svg, encoding="utf-8")


def main() -> int:
    args = parse_args()
    input_path = Path(args.input).resolve()
    out_dir = Path(args.output_dir).resolve()
    out_dir.mkdir(parents=True, exist_ok=True)

    if not input_path.exists():
        print(f"ERROR: dataset not found: {input_path}")
        return 1

    rows = build_features(load_csv(input_path))
    train_idx, test_idx = split_indices(rows, "run", 0.7)
    train_rows = [rows[i] for i in train_idx]
    test_rows = [rows[i] for i in test_idx]

    if not train_rows or not test_rows:
        print("ERROR: train/test split empty.")
        return 1

    vcfg = build_vectorizer(train_rows)
    X_train = [vectorize_row(r, vcfg) for r in train_rows]
    X_test = [vectorize_row(r, vcfg) for r in test_rows]
    y_train = [str(r["label"]) for r in train_rows]
    y_test = [str(r["label"]) for r in test_rows]
    y_train_idx = [CLASSES.index(y) if y in CLASSES else 0 for y in y_train]
    counts = Counter(y_train_idx)
    class_weights = [(len(y_train_idx) / max(counts.get(i, 1), 1)) / len(CLASSES) for i in range(len(CLASSES))]

    W, b = train_logreg(X_train, y_train_idx, args.epochs, args.lr, args.l2, class_weights, args.seed)

    rules_pred, rules_prob = [], []
    ml_pred, ml_prob = [], []
    hybrid_pred, hybrid_prob = [], []
    for row, x in zip(test_rows, X_test):
        rp, rpr = rule_predict(row)
        mp, mpr = ml_predict_with_proba(x, W, b)
        hp, hpr = hybrid_predict(rp, rpr, mp, mpr)
        rules_pred.append(rp)
        rules_prob.append(rpr)
        ml_pred.append(mp)
        ml_prob.append(mpr)
        hybrid_pred.append(hp)
        hybrid_prob.append(hpr)

    methods = {
        "rules_only": (rules_pred, rules_prob),
        "ml_only": (ml_pred, ml_prob),
        "hybrid": (hybrid_pred, hybrid_prob),
    }

    metrics_summary_rows = []
    per_class_rows = []
    roc_rows = []
    latency_rows = []
    report = {
        "generated_at": datetime.now().astimezone().isoformat(),
        "input": str(input_path),
        "split": {
            "mode": "attack_run",
            "train_samples": len(train_rows),
            "test_samples": len(test_rows),
            "train_runs": sorted({str(r.get("attack_run_id", "")) for r in train_rows if str(r.get("attack_run_id", "")).strip()}),
            "test_runs": sorted({str(r.get("attack_run_id", "")) for r in test_rows if str(r.get("attack_run_id", "")).strip()}),
        },
        "methods": {},
    }

    for method, (preds, probs) in methods.items():
        cm = confusion_matrix(y_test, preds)
        m = metrics_from_confusion(cm)
        auc = roc_auc_ovr(y_test, probs)
        lat = detection_latency(test_rows, preds)
        report["methods"][method] = {
            "metrics": m,
            "confusion_matrix": cm,
            "roc_auc_ovr": auc,
            "detection_latency": lat,
        }

        metrics_summary_rows.append([method, m["accuracy"], m["macro_avg_f1"], m["weighted_avg_f1"]])
        for cls in CLASSES:
            pc = m["per_class"][cls]
            per_class_rows.append([method, cls, pc["precision"], pc["recall"], pc["f1"], pc["support"]])
        roc_rows.append([method, auc["normal"], auc["bruteforce"], auc["scan"], auc["injection"], auc["macro_avg"]])
        latency_rows.append([method, lat["runs"], lat["mean_first_alert_sec"], lat["mean_first_correct_sec"]])
        write_confusion_csv(out_dir / f"confusion_{method}.csv", cm)

        fp = [r for r, t, p in zip(test_rows, y_test, preds) if t == "normal" and p != "normal"]
        fn = [r for r, t, p in zip(test_rows, y_test, preds) if t != "normal" and p == "normal"]
        with (out_dir / f"fp_fn_{method}.csv").open("w", encoding="utf-8", newline="") as f:
            w = csv.writer(f)
            w.writerow(["kind", "ts", "ip", "event_type", "path", "status", "true_label", "pred_label", "attack_run_id"])
            for r, t, p in zip(test_rows, y_test, preds):
                if t == "normal" and p != "normal":
                    w.writerow(["FP", r["ts"], r["ip"], r["event_type"], r["path"], r["status"], t, p, r["attack_run_id"]])
                elif t != "normal" and p == "normal":
                    w.writerow(["FN", r["ts"], r["ip"], r["event_type"], r["path"], r["status"], t, p, r["attack_run_id"]])
            report["methods"][method]["fp_count"] = len(fp)
            report["methods"][method]["fn_count"] = len(fn)

    # Stress experiments
    train_injection_runs = {
        str(r.get("attack_run_id", "")).strip()
        for r in train_rows
        if str(r.get("label", "normal")) == "injection" and str(r.get("attack_run_id", "")).strip()
    }
    subsets_idx = {
        "low_and_slow": [
            i
            for i, r in enumerate(test_rows)
            if str(r.get("label", "normal")) in ATTACK_CLASSES
            and int(r.get("req_1m", 0) or 0) <= 3
            and int(r.get("failed_1m", 0) or 0) <= 3
            and int(r.get("notfound_2m", 0) or 0) <= 6
        ],
        "burst": [
            i
            for i, r in enumerate(test_rows)
            if str(r.get("label", "normal")) in ATTACK_CLASSES
            and (
                int(r.get("req_1m", 0) or 0) >= 8
                or int(r.get("failed_1m", 0) or 0) >= 8
                or int(r.get("notfound_2m", 0) or 0) >= 12
            )
        ],
        "high_noise_normal": [
            i
            for i, r in enumerate(test_rows)
            if str(r.get("label", "normal")) == "normal" and int(r.get("req_5m", 0) or 0) >= 8
        ],
        "new_injection_variant": [
            i
            for i, r in enumerate(test_rows)
            if str(r.get("label", "normal")) == "injection"
            and str(r.get("attack_run_id", "")).strip()
            and str(r.get("attack_run_id", "")).strip() not in train_injection_runs
        ],
    }

    stress_rows = []
    report["stress_experiments"] = {}
    for subset_name, idxs in subsets_idx.items():
        sub_rows = [test_rows[i] for i in idxs]
        sub_true = [y_test[i] for i in idxs]
        report["stress_experiments"][subset_name] = {}
        for method, (preds, _probs) in methods.items():
            sub_pred = [preds[i] for i in idxs]
            result = eval_subset(subset_name, sub_rows, sub_true, sub_pred)
            report["stress_experiments"][subset_name][method] = result
            stress_rows.append([subset_name, method, result.get("samples", 0), result.get("accuracy", ""), result.get("attack_recall_binary", "")])

    # CSV tables
    with (out_dir / "metrics_summary.csv").open("w", encoding="utf-8", newline="") as f:
        w = csv.writer(f)
        w.writerow(["method", "accuracy", "macro_f1", "weighted_f1"])
        w.writerows(metrics_summary_rows)

    with (out_dir / "roc_auc_ovr.csv").open("w", encoding="utf-8", newline="") as f:
        w = csv.writer(f)
        w.writerow(["method", "auc_normal", "auc_bruteforce", "auc_scan", "auc_injection", "auc_macro"])
        w.writerows(roc_rows)

    with (out_dir / "per_class_metrics.csv").open("w", encoding="utf-8", newline="") as f:
        w = csv.writer(f)
        w.writerow(["method", "class", "precision", "recall", "f1", "support"])
        w.writerows(per_class_rows)

    with (out_dir / "detection_latency.csv").open("w", encoding="utf-8", newline="") as f:
        w = csv.writer(f)
        w.writerow(["method", "runs", "mean_first_alert_sec", "mean_first_correct_sec"])
        w.writerows(latency_rows)

    with (out_dir / "stress_experiments.csv").open("w", encoding="utf-8", newline="") as f:
        w = csv.writer(f)
        w.writerow(["scenario", "method", "samples", "accuracy", "attack_recall_binary"])
        w.writerows(stress_rows)

    # Graphs (SVG)
    write_simple_bar_svg(
        out_dir / "graph_accuracy.svg",
        "Accuracy by Method",
        [r[0] for r in metrics_summary_rows],
        [float(r[1]) for r in metrics_summary_rows],
        "#0ea5e9",
    )
    write_simple_bar_svg(
        out_dir / "graph_macro_f1.svg",
        "Macro F1 by Method",
        [r[0] for r in metrics_summary_rows],
        [float(r[2]) for r in metrics_summary_rows],
        "#22c55e",
    )
    write_simple_bar_svg(
        out_dir / "graph_latency_first_alert.svg",
        "Detection Latency (Mean First Alert, sec)",
        [r[0] for r in latency_rows],
        [float(r[2]) if float(r[2]) >= 0 else 0.0 for r in latency_rows],
        "#f97316",
    )

    # Interpretation draft
    best_method = max(metrics_summary_rows, key=lambda r: float(r[2]))[0]
    interp = [
        "# Phase 10 Interpretation (FP/FN)",
        "",
        f"- Best overall (macro F1): `{best_method}`.",
        "- `rules_only` biasanya kuat untuk burst pattern, tapi rawan miss low-and-slow dan varian payload baru.",
        "- `ml_only` lebih adaptif ke variasi pola, tapi berpotensi FP pada noise traffic tinggi.",
        "- `hybrid` menurunkan FN pada serangan obvious (karena rules override) sambil mempertahankan generalisasi ML.",
        "- Fokus analisis Bab Hasil: bandingkan FP/FN per skenario (`low_and_slow`, `burst`, `high_noise_normal`, `new_injection_variant`).",
    ]
    (out_dir / "interpretation_fp_fn.md").write_text("\n".join(interp), encoding="utf-8")

    (out_dir / "report.json").write_text(json.dumps(report, indent=2), encoding="utf-8")
    print(f"OutputDir: {out_dir}")
    print(f"BestMethod(macro_f1): {best_method}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
