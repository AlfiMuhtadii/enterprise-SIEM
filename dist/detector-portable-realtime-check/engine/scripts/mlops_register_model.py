#!/usr/bin/env python3
"""
Register model artifact + metadata into ml_models and optionally deploy.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import pickle
import subprocess
from datetime import datetime
from pathlib import Path
from typing import Any, Dict, List, Optional, Sequence, Tuple

from security_audit import insert_audit
from train_ai_detector import build_features, load_csv


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Register model metadata and optional deployment")
    parser.add_argument("--model", default="storage/app/ai_detector_model.pkl")
    parser.add_argument("--report", default="storage/app/ai_detector_report.json")
    parser.add_argument("--dataset", default="storage/app/security_dataset.csv")
    parser.add_argument("--model-key", default="")
    parser.add_argument("--dsn", default=os.getenv("SECURITY_INGEST_DSN", ""))
    parser.add_argument("--deploy", action="store_true")
    parser.add_argument("--env", default="local")
    parser.add_argument("--deployed-by", default="operator")
    parser.add_argument("--top-features", type=int, default=8)
    return parser.parse_args()


def parse_env_file(path: Path) -> Dict[str, str]:
    out: Dict[str, str] = {}
    if not path.exists():
        return out
    for line in path.read_text(encoding="utf-8").splitlines():
        s = line.strip()
        if not s or s.startswith("#") or "=" not in s:
            continue
        k, v = s.split("=", 1)
        out[k.strip()] = v.strip().strip('"').strip("'")
    return out


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


def file_sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        while True:
            chunk = f.read(8192)
            if not chunk:
                break
            h.update(chunk)
    return h.hexdigest()


def get_git_commit(project_root: Path) -> str:
    try:
        out = subprocess.check_output(
            ["git", "rev-parse", "--short", "HEAD"],
            cwd=str(project_root),
            stderr=subprocess.DEVNULL,
        )
        commit = out.decode("utf-8").strip()
        return commit or "unknown"
    except Exception:
        return os.getenv("GIT_COMMIT", "unknown")


def load_model(path: Path) -> Dict[str, Any]:
    with path.open("rb") as f:
        obj = pickle.load(f)
    if not isinstance(obj, dict):
        raise ValueError("model artifact is not a dict")
    if "vectorizer" not in obj:
        raise ValueError("model artifact missing vectorizer")
    return obj


def feature_hash(vcfg: Dict[str, Any]) -> str:
    names: List[str] = []
    for c in vcfg.get("categorical", []):
        names.append(f"cat:{c}")
    for n in vcfg.get("numeric", []):
        names.append(f"num:{n}")
    canonical = "|".join(names)
    return hashlib.sha256(canonical.encode("utf-8")).hexdigest()


def training_range_from_rows(rows: Sequence[Dict[str, Any]]) -> Tuple[Optional[str], Optional[str]]:
    if not rows:
        return None, None
    ts_vals = [str(r.get("ts", "")) for r in rows if str(r.get("ts", "")).strip()]
    if not ts_vals:
        return None, None
    return min(ts_vals), max(ts_vals)


def pick_top_numeric_features(model: Dict[str, Any], top_n: int) -> List[str]:
    vcfg = model["vectorizer"]
    numeric: List[str] = list(vcfg.get("numeric", []))
    W: List[List[float]] = model.get("weights", [])
    if not W or not numeric:
        return numeric[:top_n]
    cat_dim = sum(len(vcfg["cat_maps"][c]) for c in vcfg.get("categorical", []))
    scored: List[Tuple[str, float]] = []
    for i, feat in enumerate(numeric):
        idx = cat_dim + i
        w = [abs(row[idx]) for row in W if idx < len(row)]
        score = sum(w) / len(w) if w else 0.0
        scored.append((feat, score))
    scored.sort(key=lambda x: x[1], reverse=True)
    return [n for n, _ in scored[: max(1, top_n)]]


def quantile_bins(values: List[float], bins: int = 10) -> List[float]:
    if not values:
        return [0.0, 1.0]
    s = sorted(values)
    edges = [s[0]]
    for i in range(1, bins):
        pos = int((i / bins) * (len(s) - 1))
        edges.append(s[pos])
    edges.append(s[-1])
    clean = [edges[0]]
    for x in edges[1:]:
        if x <= clean[-1]:
            x = clean[-1] + 1e-9
        clean.append(x)
    return clean


def histogram_pct(values: List[float], edges: List[float]) -> List[float]:
    if not values:
        n = max(1, len(edges) - 1)
        return [1.0 / n] * n
    counts = [0] * (len(edges) - 1)
    for v in values:
        placed = False
        for i in range(len(edges) - 1):
            left, right = edges[i], edges[i + 1]
            if i == len(edges) - 2:
                if left <= v <= right:
                    counts[i] += 1
                    placed = True
                    break
            if left <= v < right:
                counts[i] += 1
                placed = True
                break
        if not placed:
            if v < edges[0]:
                counts[0] += 1
            else:
                counts[-1] += 1
    total = sum(counts) or 1
    return [c / total for c in counts]


def build_drift_profile(model: Dict[str, Any], rows: List[Dict[str, Any]], top_n: int) -> Dict[str, Any]:
    features = pick_top_numeric_features(model, top_n)
    profile: Dict[str, Any] = {
        "version": 1,
        "generated_at": datetime.now().astimezone().isoformat(),
        "baseline_rows": len(rows),
        "features": {},
    }
    for feat in features:
        vals = [float(r.get(feat, 0) or 0) for r in rows]
        edges = quantile_bins(vals, bins=10)
        pct = histogram_pct(vals, edges)
        profile["features"][feat] = {"edges": edges, "baseline_pct": pct}
    return profile


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    model_path = (root / args.model).resolve()
    report_path = (root / args.report).resolve()
    dataset_path = (root / args.dataset).resolve()

    if not model_path.exists():
        print(f"ERROR: model not found: {model_path}")
        return 1
    if not report_path.exists():
        print(f"ERROR: report not found: {report_path}")
        return 1
    if not dataset_path.exists():
        print(f"ERROR: dataset not found: {dataset_path}")
        return 1

    dsn = args.dsn.strip() or build_dsn_from_env(root)
    if not dsn:
        print("ERROR: DSN missing.")
        return 1

    model = load_model(model_path)
    report = json.loads(report_path.read_text(encoding="utf-8"))
    rows = build_features(load_csv(dataset_path))
    train_start, train_end = training_range_from_rows(rows)

    model_key = args.model_key.strip()
    if not model_key:
        model_key = datetime.now().strftime("model-%Y%m%d-%H%M%S")

    artifact_hash = file_sha256(model_path)
    feat_hash = feature_hash(model["vectorizer"])
    git_commit = get_git_commit(root)
    drift_profile = build_drift_profile(model, rows, args.top_features)

    driver, conn = connect_db(dsn)
    conn.autocommit = False
    try:
        with conn.cursor() as cur:
            insert_sql = """
            INSERT INTO ml_models (
                model_key, model_type, artifact_path, artifact_sha256,
                training_data_start, training_data_end, feature_hash,
                metrics, train_config, drift_profile, git_commit, created_at, updated_at
            ) VALUES (
                %s, %s, %s, %s,
                %s, %s, %s,
                %s::jsonb, %s::jsonb, %s::jsonb, %s, now(), now()
            )
            RETURNING id
            """
            cur.execute(
                insert_sql,
                (
                    model_key,
                    str(model.get("model_type", "logreg")),
                    str(model_path),
                    artifact_hash,
                    train_start,
                    train_end,
                    feat_hash,
                    json.dumps(report.get("metrics", {}), separators=(",", ":")),
                    json.dumps(model.get("train_config", {}), separators=(",", ":")),
                    json.dumps(drift_profile, separators=(",", ":")),
                    git_commit,
                ),
            )
            model_id = int(cur.fetchone()[0])
            insert_audit(
                conn=conn,
                action="MODEL_REGISTERED",
                target_type="ml_model",
                target_id=str(model_id),
                actor=args.deployed_by,
                after_state={
                    "model_key": model_key,
                    "artifact_sha256": artifact_hash,
                    "feature_hash": feat_hash,
                    "git_commit": git_commit,
                },
                meta={"source": "mlops_register_model.py"},
            )

            if args.deploy:
                cur.execute(
                    "SELECT id, model_id, expected_artifact_sha256 FROM ml_model_deployments WHERE environment = %s AND is_active = true ORDER BY deployed_at DESC LIMIT 1",
                    (args.env,),
                )
                prev = cur.fetchone()
                cur.execute(
                    "UPDATE ml_model_deployments SET is_active = false, updated_at = now() WHERE environment = %s AND is_active = true",
                    (args.env,),
                )
                deploy_sql = """
                INSERT INTO ml_model_deployments (
                    model_id, environment, is_active, lock_enabled,
                    expected_artifact_sha256, deployed_at, deployed_by, notes, created_at, updated_at
                ) VALUES (
                    %s, %s, true, true,
                    %s, now(), %s, %s::jsonb, now(), now()
                )
                """
                cur.execute(
                    deploy_sql,
                    (
                        model_id,
                        args.env,
                        artifact_hash,
                        args.deployed_by,
                        json.dumps({"source": "mlops_register_model.py"}, separators=(",", ":")),
                    ),
                )
                insert_audit(
                    conn=conn,
                    action="MODEL_DEPLOYED",
                    target_type="ml_model_deployment",
                    target_id=str(model_id),
                    actor=args.deployed_by,
                    before_state={
                        "prev_deployment_id": int(prev[0]) if prev else None,
                        "prev_model_id": int(prev[1]) if prev else None,
                        "prev_expected_sha256": str(prev[2]) if prev else None,
                    },
                    after_state={
                        "environment": args.env,
                        "model_id": model_id,
                        "expected_artifact_sha256": artifact_hash,
                        "lock_enabled": True,
                    },
                    meta={"source": "mlops_register_model.py"},
                )

        conn.commit()
    finally:
        conn.close()

    print(f"ModelRegistered: id={model_id}, key={model_key}")
    print(f"ArtifactSHA256: {artifact_hash}")
    print(f"FeatureHash: {feat_hash}")
    print(f"GitCommit: {git_commit}")
    if args.deploy:
        print(f"DeploymentActive: env={args.env}, lock_enabled=true")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
