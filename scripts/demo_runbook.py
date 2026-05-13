#!/usr/bin/env python3
"""
Deterministic live-demo runbook.

Commands:
- up
- reset
- run
- verify
- open
- full
"""

from __future__ import annotations

import argparse
import os
import shutil
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple
from urllib.parse import urlparse


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Deterministic demo runbook")
    parser.add_argument("command", choices=["up", "reset", "run", "verify", "open", "full"])
    parser.add_argument("--base-url", default="http://127.0.0.1:8000")
    parser.add_argument("--minutes", type=int, default=15)
    parser.add_argument("--min-events", type=int, default=120)
    parser.add_argument("--min-alerts", type=int, default=10)
    parser.add_argument("--min-responses", type=int, default=5)
    parser.add_argument("--max-alert-age-sec", type=int, default=90)
    parser.add_argument("--max-duplicate-alerts", type=int, default=0)
    parser.add_argument("--max-alerts-per-1k-events", type=float, default=0.0, help="0 disables ratio check")
    parser.add_argument("--auto-serve", type=int, choices=[0, 1], default=1)
    return parser.parse_args()


def run(cmd: List[str], cwd: Path, check: bool = True) -> subprocess.CompletedProcess[str]:
    try:
        return subprocess.run(cmd, cwd=str(cwd), check=check, text=True, capture_output=True)
    except subprocess.CalledProcessError as exc:
        print(f"Command failed: {' '.join(cmd)}")
        if exc.stdout:
            print("STDOUT:")
            print(exc.stdout.strip())
        if exc.stderr:
            print("STDERR:")
            print(exc.stderr.strip())
        raise


def ensure_cmd(name: str) -> None:
    if shutil.which(name) is None:
        raise RuntimeError(f"command not found: {name}")


def parse_env(path: Path) -> Dict[str, str]:
    out: Dict[str, str] = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        s = line.strip()
        if not s or s.startswith("#") or "=" not in s:
            continue
        k, v = s.split("=", 1)
        out[k.strip()] = v.strip().strip('"').strip("'")
    return out


def connect_db(root: Path):
    env = parse_env(root / ".env")
    import psycopg  # type: ignore
    return psycopg.connect(
        host=env.get("DB_HOST", "127.0.0.1"),
        port=env.get("DB_PORT", "5432"),
        dbname=env.get("DB_DATABASE", "detector"),
        user=env.get("DB_USERNAME", "postgres"),
        password=env.get("DB_PASSWORD", "postgres"),
    )


def command_up(root: Path) -> None:
    print("[up] starting infra...")
    run(["docker", "compose", "up", "-d", "redpanda", "redpanda-console", "clickhouse", "grafana"], root)
    print("[up] migrate + seed...")
    run(["php", "artisan", "migrate:fresh", "--seed"], root)
    print("[up] done")


def command_reset(root: Path) -> None:
    print("[reset] truncate tables + offsets...")
    conn = connect_db(root)
    conn.autocommit = False
    try:
        with conn.cursor() as cur:
            cur.execute("TRUNCATE TABLE security_responses, security_alerts, security_events, attack_runs RESTART IDENTITY CASCADE")
        conn.commit()
    finally:
        conn.close()

    for rel in [
        "storage/app/security_ingest_py.offset",
        "storage/app/clickhouse_sync_state.json",
        "storage/app/redpanda_topic_offsets.json",
        "storage/app/redpanda_topic_consumer_state.json",
    ]:
        p = root / rel
        if p.exists():
            p.unlink()
    # Keep demo deterministic: clear previous telemetry file so stream starts from clean slate.
    log_path = root / "storage" / "logs" / "security.jsonl"
    log_path.parent.mkdir(parents=True, exist_ok=True)
    log_path.write_text("", encoding="utf-8")
    print("[reset] done")


def wait_for_url(url: str, timeout: int = 25) -> bool:
    import urllib.request
    start = time.time()
    while time.time() - start < timeout:
        try:
            with urllib.request.urlopen(url, timeout=3) as resp:
                if resp.status < 500:
                    return True
        except Exception:
            time.sleep(1)
    return False


def command_run(root: Path, base_url: str, auto_serve: int) -> None:
    app_proc: Optional[subprocess.Popen[str]] = None
    if not wait_for_url(f"{base_url}/login", timeout=5):
        if int(auto_serve) != 1:
            raise RuntimeError(f"app is not reachable at {base_url}. Start php artisan serve first.")
        u = urlparse(base_url)
        host = u.hostname or "127.0.0.1"
        port = u.port or 8000
        app_log = (root / "storage" / "app" / "demo_app_server.log")
        app_log.parent.mkdir(parents=True, exist_ok=True)
        print(f"[run] app not reachable, auto-starting php artisan serve --host={host} --port={port}")
        app_proc = subprocess.Popen(
            ["php", "artisan", "serve", f"--host={host}", f"--port={port}"],
            cwd=str(root),
            stdout=app_log.open("w", encoding="utf-8"),
            stderr=subprocess.STDOUT,
            text=True,
        )
        if not wait_for_url(f"{base_url}/login", timeout=20):
            if app_proc is not None:
                app_proc.terminate()
            raise RuntimeError(f"failed to auto-start app server at {base_url}")

    logs = root / "storage" / "app"
    logs.mkdir(parents=True, exist_ok=True)
    prod_log = logs / "demo_stream_producer.log"
    det_log = logs / "demo_detector.log"
    sync_log = logs / "demo_clickhouse_sync.log"
    prod_log.write_text("", encoding="utf-8")
    det_log.write_text("", encoding="utf-8")
    sync_log.write_text("", encoding="utf-8")

    print("[run] starting stream producer + detector + clickhouse sync...")
    prod = subprocess.Popen(
        [sys.executable, "-u", "scripts/stream_producer_kafka.py", "--from-start"],
        cwd=str(root),
        stdout=prod_log.open("w", encoding="utf-8"),
        stderr=subprocess.STDOUT,
        text=True,
    )
    det = subprocess.Popen(
        [
            sys.executable,
            "-u",
            "scripts/realtime_detector_kafka_consumer.py",
            f"--group-id=detector-demo-{int(time.time())}",
            "--use-active-deployment=0",
            "--require-lock=0",
            "--response-mode=recommend",
            "--max-empty-polls=0",
        ],
        cwd=str(root),
        stdout=det_log.open("w", encoding="utf-8"),
        stderr=subprocess.STDOUT,
        text=True,
    )
    syncer = subprocess.Popen(
        [sys.executable, "-u", "scripts/clickhouse_sync_daemon.py", "--interval-sec=2"],
        cwd=str(root),
        stdout=sync_log.open("w", encoding="utf-8"),
        stderr=subprocess.STDOUT,
        text=True,
    )

    try:
        time.sleep(2)
        print("[run] bruteforce...")
        run(
            [
                "php",
                "artisan",
                "sim:bruteforce",
                f"--base-url={base_url}",
                "--attempts=50",
                "--ip=203.0.113.10",
                "--vary-ip=0",
                "--sleep-ms=10",
                "--tag=demo",
            ],
            root,
        )
        print("[run] scan...")
        run(
            [
                "php",
                "artisan",
                "sim:scan",
                f"--base-url={base_url}",
                "--count=60",
                "--ip=198.51.100.77",
                "--include-sensitive=1",
                "--sleep-ms=10",
                "--tag=demo",
            ],
            root,
        )
        print("[run] injection...")
        run(
            [
                "php",
                "artisan",
                "sim:injection",
                f"--base-url={base_url}",
                "--ip=192.0.2.55",
                "--repeats=2",
                "--sleep-ms=20",
                "--tag=demo",
            ],
            root,
        )
        print("[run] waiting pipeline settle...")
        time.sleep(8)
        print("[run] ingest JSONL -> Postgres security_events...")
        ingest = run([sys.executable, "scripts/ingest_security_events.py", "--from-start"], root)
        if ingest.stdout.strip():
            print(ingest.stdout.strip())
        conn = connect_db(root)
        try:
            with conn.cursor() as cur:
                cur.execute("select count(*) from security_alerts")
                alert_count = int(cur.fetchone()[0])
        finally:
            conn.close()
        if alert_count == 0:
            raise RuntimeError("streaming detector produced zero alerts. Check storage/app/demo_detector.log")
        print("[run] syncing Postgres -> ClickHouse for Grafana...")
        sync = run([sys.executable, "scripts/sync_postgres_to_clickhouse.py", "--full-refresh"], root)
        if sync.stdout.strip():
            print(sync.stdout.strip())
    finally:
        det.terminate()
        prod.terminate()
        syncer.terminate()
        try:
            det.wait(timeout=5)
        except Exception:
            det.kill()
        try:
            prod.wait(timeout=5)
        except Exception:
            prod.kill()
        try:
            syncer.wait(timeout=5)
        except Exception:
            syncer.kill()
        if app_proc is not None:
            app_proc.terminate()
            try:
                app_proc.wait(timeout=5)
            except Exception:
                app_proc.kill()
    print("[run] done")


def command_verify(
    root: Path,
    minutes: int,
    min_events: int,
    min_alerts: int,
    min_responses: int,
    max_alert_age_sec: int,
    max_duplicate_alerts: int,
    max_alerts_per_1k_events: float,
) -> None:
    conn = connect_db(root)
    conn.autocommit = True
    try:
        with conn.cursor() as cur:
            cur.execute("select count(*) from security_events")
            events = int(cur.fetchone()[0])
            cur.execute("select count(*) from security_alerts")
            alerts = int(cur.fetchone()[0])
            cur.execute("select count(distinct alert_id) from security_alerts")
            uniq_alerts = int(cur.fetchone()[0])
            cur.execute("select count(*) from security_responses")
            responses = int(cur.fetchone()[0])
            cur.execute("select max(detected_at) from security_alerts")
            latest_alert = cur.fetchone()[0]
    finally:
        conn.close()

    now = datetime.now(timezone.utc)
    age_sec = 10**9
    if latest_alert is not None:
        if latest_alert.tzinfo is None:
            latest_alert = latest_alert.replace(tzinfo=timezone.utc)
        age_sec = int((now - latest_alert.astimezone(timezone.utc)).total_seconds())

    dup_alerts = max(0, alerts - uniq_alerts)
    alerts_per_1k = (alerts * 1000.0 / events) if events > 0 else 0.0
    print(
        f"events={events}, alerts={alerts}, unique_alerts={uniq_alerts}, duplicate_alerts={dup_alerts}, "
        f"alerts_per_1k_events={alerts_per_1k:.2f}, responses={responses}, latest_alert_age_sec={age_sec}"
    )
    ok = True
    if events < min_events:
        print(f"FAIL: events<{min_events}")
        ok = False
    if alerts < min_alerts:
        print(f"FAIL: alerts<{min_alerts}")
        ok = False
    if responses < min_responses:
        print(f"FAIL: responses<{min_responses}")
        ok = False
    if age_sec > max_alert_age_sec:
        print(f"FAIL: latest alert too old (>{max_alert_age_sec}s)")
        ok = False
    if dup_alerts > max_duplicate_alerts:
        print(f"FAIL: duplicate alerts>{max_duplicate_alerts} (got {dup_alerts})")
        ok = False
    if max_alerts_per_1k_events > 0 and alerts_per_1k > max_alerts_per_1k_events:
        print(f"FAIL: alerts_per_1k_events>{max_alerts_per_1k_events} (got {alerts_per_1k:.2f})")
        ok = False

    if not ok:
        print("Diagnostics:")
        print("- Ensure you ran: demo_runbook.py run (or full)")
        print("- Ensure redpanda is up: docker compose ps redpanda")
        print("- Check detector log: storage/app/demo_detector.log")
        print("- Check producer log: storage/app/demo_stream_producer.log")
        raise RuntimeError("demo verify failed")
    print("VERIFY PASS")


def command_open(base_url: str) -> None:
    print(f"APP: {base_url}")
    print("GRAFANA: http://127.0.0.1:3000  (admin/admin)")
    print("REDPANDA-CONSOLE: http://127.0.0.1:8080")


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    ensure_cmd("php")
    ensure_cmd("docker")

    if args.command == "up":
        run([sys.executable, "scripts/preflight.py", f"--base-url={args.base_url}", "--skip-app"], root)
        command_up(root)
    elif args.command == "reset":
        command_reset(root)
    elif args.command == "run":
        run([sys.executable, "scripts/preflight.py", f"--base-url={args.base_url}"], root, check=False)
        command_run(root, args.base_url, args.auto_serve)
    elif args.command == "verify":
        command_verify(
            root,
            args.minutes,
            args.min_events,
            args.min_alerts,
            args.min_responses,
            args.max_alert_age_sec,
            args.max_duplicate_alerts,
            args.max_alerts_per_1k_events,
        )
    elif args.command == "open":
        command_open(args.base_url)
    elif args.command == "full":
        run([sys.executable, "scripts/preflight.py", f"--base-url={args.base_url}", "--skip-app"], root, check=False)
        command_up(root)
        command_reset(root)
        command_run(root, args.base_url, args.auto_serve)
        command_verify(
            root,
            args.minutes,
            args.min_events,
            args.min_alerts,
            args.min_responses,
            args.max_alert_age_sec,
            args.max_duplicate_alerts,
            args.max_alerts_per_1k_events,
        )
        command_open(args.base_url)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
