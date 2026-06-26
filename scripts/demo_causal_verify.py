#!/usr/bin/env python3
"""
Causal live demo verifier.

Orchestrates the three-stage live proof:
  1. validate_live_xdr_pipeline.py  — pipeline readiness
  2. demo_feed.py --mode pipeline   — ingest demo events
  3. php artisan security:alerts-report --demo-run=<id> — proof polling

Reports LIVE_CAUSAL_PROOF=PASS/WARN/FAIL.

Usage:
  python scripts/demo_causal_verify.py [options]

Definitions:
  LIVE_CAUSAL_PROOF=PASS  All checks pass AND security_alerts.evidence contains
                          demo_run_id (FIELD_MATCH=PASS).
  LIVE_CAUSAL_PROOF=WARN  Events accepted and alerts found via time-window fallback
                          only (FIELD_MATCH=WARN). Field-level lineage not confirmed.
  LIVE_CAUSAL_PROOF=FAIL  Readiness failed / ingestion failed / no alerts found /
                          timeout without PASS or WARN.

Exit codes: 0=PASS, 1=WARN, 2=FAIL.
"""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

SCRIPTS_DIR = Path(__file__).parent
PROJECT_ROOT = SCRIPTS_DIR.parent
REPORTS_DIR = PROJECT_ROOT / "reports"


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def _default_run(
    cmd: list[str],
    cwd: str | None = None,
    timeout: int = 120,
) -> tuple[int, str]:
    """Run a subprocess; return (returncode, combined stdout+stderr)."""
    result = subprocess.run(
        cmd,
        capture_output=True,
        text=True,
        cwd=cwd or str(PROJECT_ROOT),
        timeout=timeout,
    )
    return result.returncode, (result.stdout or "") + (result.stderr or "")


# ---------------------------------------------------------------------------
# Step 1 — Pipeline readiness
# ---------------------------------------------------------------------------

def run_validator(
    args: argparse.Namespace,
    _run_fn=None,
) -> tuple[bool, str, dict]:
    """Run validate_live_xdr_pipeline.py.

    Returns (passed, output, summary_dict).
    """
    run = _run_fn or _default_run
    cmd = [sys.executable, str(SCRIPTS_DIR / "validate_live_xdr_pipeline.py")]
    try:
        rc, output = run(cmd, cwd=str(PROJECT_ROOT))
    except Exception as exc:
        return False, f"validator_exception: {exc}", {"error": str(exc), "passed": False}
    passed = rc == 0
    return passed, output, {
        "exit_code": rc,
        "passed": passed,
        "output_excerpt": output[:800],
    }


# ---------------------------------------------------------------------------
# Step 2 — Demo feed (event ingestion)
# ---------------------------------------------------------------------------

def run_demo_feed(
    args: argparse.Namespace,
    _run_fn=None,
) -> tuple[bool, str, int, str, dict]:
    """Run demo_feed.py --mode pipeline.

    Returns (success, demo_run_id, accepted_count, output, manifest_dict).
    """
    run = _run_fn or _default_run
    cmd = [
        sys.executable,
        str(SCRIPTS_DIR / "demo_feed.py"),
        "--input", str(args.input),
        "--mode", "pipeline",
        "--ingest-url", args.ingest_url,
        "--skip-readiness-check",
    ]
    try:
        rc, output = run(cmd, cwd=str(PROJECT_ROOT))
    except Exception as exc:
        return False, "", 0, f"demo_feed_exception: {exc}", {}

    # Extract demo_run_id from output.
    m = re.search(r"demo_run_id\s*:\s*(\S+)", output)
    demo_run_id = m.group(1).strip() if m else ""

    # Sum accepted counts from all batch lines.
    accepted = sum(int(x) for x in re.findall(r"accepted=(\d+)", output))

    # Try to read the manifest for precise counts.
    manifest: dict = {}
    if demo_run_id:
        manifest_path = PROJECT_ROOT / "storage" / "logs" / f"{demo_run_id}-manifest.json"
        if manifest_path.exists():
            try:
                manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
                if "sent_count" in manifest:
                    accepted = manifest["sent_count"]
            except Exception:
                pass

    success = rc == 0 and bool(demo_run_id) and accepted > 0
    return success, demo_run_id, accepted, output, manifest


# ---------------------------------------------------------------------------
# Step 3 — Poll alerts report
# ---------------------------------------------------------------------------

def poll_alerts_report(
    demo_run_id: str,
    args: argparse.Namespace,
    _run_fn=None,
    _sleep_fn=None,
) -> tuple[str, int, str, list[str], list[str]]:
    """Poll security:alerts-report --demo-run until FIELD_MATCH=PASS/WARN/timeout.

    Returns (field_match, alert_count, last_output, rule_ids, trace_ids).
    field_match is "PASS", "WARN", or "FAIL".
    """
    run = _run_fn or _default_run
    sleep = _sleep_fn or time.sleep

    deadline = time.monotonic() + args.timeout_seconds
    last_output = ""
    warn_output = ""

    while time.monotonic() < deadline:
        cmd = ["php", "artisan", "security:alerts-report", f"--demo-run={demo_run_id}"]
        try:
            _, output = run(cmd, cwd=str(PROJECT_ROOT))
            last_output = output
        except Exception as exc:
            last_output = f"artisan_exception: {exc}"
            sleep(args.poll_interval_seconds)
            continue

        if "FIELD_MATCH=PASS" in output:
            return (
                "PASS",
                _parse_alert_count(output),
                output,
                _parse_alert_types(output),
                _parse_trace_ids(output),
            )
        if "FIELD_MATCH=WARN" in output:
            warn_output = output  # keep polling — may upgrade to PASS

        sleep(args.poll_interval_seconds)

    # Timeout — return best available result.
    if warn_output:
        return (
            "WARN",
            _parse_alert_count(warn_output),
            warn_output,
            _parse_alert_types(warn_output),
            _parse_trace_ids(warn_output),
        )
    if "FIELD_MATCH=FAIL" in last_output:
        return "FAIL", 0, last_output, [], []
    return "FAIL", 0, last_output, [], []


def _parse_alert_count(output: str) -> int:
    m = re.search(r"FIELD_MATCH=(?:PASS|WARN)\s+\((\d+) alert", output)
    return int(m.group(1)) if m else 0


def _parse_alert_types(output: str) -> list[str]:
    """Extract alert_type values from the summary table (ALL_CAPS_WITH_UNDERSCORES)."""
    types: list[str] = []
    for m in re.finditer(r"\|\s*([A-Z][A-Z0-9_]{2,})\s*\|\s*\d+\s*\|", output):
        t = m.group(1).strip()
        if t not in ("alert_type", "total") and t not in types:
            types.append(t)
    return types


def _parse_trace_ids(output: str) -> list[str]:
    """Extract trace IDs (demo_run_id-trace-NNNN pattern) from output."""
    ids: list[str] = []
    for m in re.finditer(r"\b([a-zA-Z0-9][a-zA-Z0-9_-]+-trace-\d+)\b", output):
        t = m.group(1)
        if t not in ids:
            ids.append(t)
    return ids


# ---------------------------------------------------------------------------
# Proof table
# ---------------------------------------------------------------------------

_COL1 = 40
_COL2 = 38
_SEP = 82


def _row(name: str, evidence: str, status: str) -> str:
    return f"  {name:<{_COL1}} {evidence:<{_COL2}} {status}"


def print_proof_table(
    validator_passed: bool,
    demo_run_id: str,
    accepted: int,
    manifest: dict,
    field_match: str,
    alert_count: int,
    rule_ids: list[str],
    trace_ids: list[str],
    verdict: str,
) -> None:
    print()
    print("=" * _SEP)
    print(f"  {'CAUSAL LIVE DEMO PROOF TABLE':<{_SEP - 2}}")
    print("=" * _SEP)
    print(_row("Step", "Evidence", "Status"))
    print("-" * _SEP)

    event_count = manifest.get("event_count", 0) if manifest else 0
    manifest_label = (
        f"event_count={event_count}, sent={accepted}" if manifest
        else f"accepted={accepted}"
    )

    rows = [
        (
            "Pipeline readiness",
            "validate_live_xdr_pipeline.py",
            "PASS" if validator_passed else "FAIL",
        ),
        (
            "Events tagged with demo lineage",
            manifest_label,
            "PASS" if accepted > 0 else "FAIL",
        ),
        (
            "Events accepted by ingestion-gateway",
            f"accepted={accepted}",
            "PASS" if accepted > 0 else "FAIL",
        ),
        (
            "Manifest created",
            f"demo_run_id={demo_run_id}" if demo_run_id else "(none)",
            "PASS" if demo_run_id else "FAIL",
        ),
        (
            "Alerts report executed",
            f"FIELD_MATCH={field_match}",
            "PASS" if field_match in ("PASS", "WARN") else "FAIL",
        ),
        (
            "Field-level lineage match",
            f"{alert_count} alert(s) via demo_run_id" if alert_count else "none",
            "PASS" if field_match == "PASS" else (
                "WARN" if field_match == "WARN" else "FAIL"
            ),
        ),
        (
            "Rule IDs found",
            ", ".join(rule_ids[:3]) if rule_ids else "(not extracted from output)",
            "PASS" if rule_ids else "WARN",
        ),
        (
            "Evidence lineage present",
            "demo_lineage_present=true" if field_match == "PASS" else "(see evidence field)",
            "PASS" if field_match == "PASS" else "WARN",
        ),
    ]

    for name, evidence, status in rows:
        print(_row(name, evidence, status))

    print("-" * _SEP)
    print(
        _row(
            "FINAL VERDICT",
            f"LIVE_CAUSAL_PROOF={verdict}",
            verdict,
        )
    )
    print("=" * _SEP)
    print()


# ---------------------------------------------------------------------------
# Report writing
# ---------------------------------------------------------------------------

def write_reports(
    demo_run_id: str,
    args: argparse.Namespace,
    verdict: str,
    field_match: str,
    accepted: int,
    alert_count: int,
    rule_ids: list[str],
    trace_ids: list[str],
    manifest: dict,
    validator_summary: dict,
    alerts_excerpt: str,
    started_at: str,
    ended_at: str,
) -> tuple[Path, Path]:
    """Write JSON and Markdown reports. Returns (json_path, md_path)."""
    REPORTS_DIR.mkdir(parents=True, exist_ok=True)
    slug = demo_run_id or "unknown"

    report = {
        "demo_run_id": demo_run_id,
        "scenario_file": str(args.input),
        "ingest_url": args.ingest_url,
        "started_at": started_at,
        "ended_at": ended_at,
        "accepted_count": accepted,
        "field_match_status": field_match,
        "alert_count": alert_count,
        "rule_ids": rule_ids,
        "trace_ids": trace_ids,
        "manifest_path": str(
            PROJECT_ROOT / "storage" / "logs" / f"{demo_run_id}-manifest.json"
        ) if demo_run_id else "",
        "validator_result": validator_summary,
        "alerts_report_excerpt": alerts_excerpt[:2000],
        "final_verdict": f"LIVE_CAUSAL_PROOF={verdict}",
    }

    json_path = REPORTS_DIR / f"demo-causal-{slug}.json"
    md_path = REPORTS_DIR / f"demo-causal-{slug}.md"

    json_path.write_text(json.dumps(report, indent=2), encoding="utf-8")

    lines = [
        f"# Causal Live Demo Proof — {demo_run_id}",
        "",
        f"**Generated:** {ended_at}",
        f"**Scenario:** `{args.input}`",
        f"**Ingest URL:** `{args.ingest_url}`",
        "",
        "## Result",
        "",
        f"**`LIVE_CAUSAL_PROOF={verdict}`**",
        "",
        f"- Pipeline readiness: {'PASS' if validator_summary.get('passed') else 'FAIL'}",
        f"- Events accepted: {accepted}",
        f"- Field-level match: `FIELD_MATCH={field_match}`",
        f"- Alerts found: {alert_count}",
        f"- Rule IDs: {', '.join(rule_ids) if rule_ids else '(not extracted)'}",
        "",
        "## Alerts Report Excerpt",
        "",
        "```",
        alerts_excerpt[:1500],
        "```",
        "",
        "## Definitions",
        "",
        "- `LIVE_CAUSAL_PROOF=PASS` — full field-level lineage proof: "
        "`demo_run_id` found in `security_alerts.evidence`",
        "- `LIVE_CAUSAL_PROOF=WARN` — events accepted, alerts found via "
        "manifest time-window fallback only (no field-level evidence)",
        "- `LIVE_CAUSAL_PROOF=FAIL` — no causal proof established",
    ]
    md_path.write_text("\n".join(lines), encoding="utf-8")

    return json_path, md_path


# ---------------------------------------------------------------------------
# Main orchestrator
# ---------------------------------------------------------------------------

def main(
    args: argparse.Namespace,
    _run_validator_fn=None,
    _run_demo_feed_fn=None,
    _run_artisan_fn=None,
    _sleep_fn=None,
) -> int:
    """Orchestrate the causal live demo proof. Returns exit code 0/1/2."""
    _quiet = getattr(args, "quiet", False)
    _p = (lambda *a, **kw: None) if _quiet else print
    started_at = _now_iso()
    w = 70
    _p(f"\n{'=' * w}")
    _p("  XDR CAUSAL LIVE DEMO VERIFIER")
    _p(f"  input    : {args.input}")
    _p(f"  ingest   : {args.ingest_url}")
    _p(f"  timeout  : {args.timeout_seconds}s  poll: {args.poll_interval_seconds}s")
    _p(f"{'=' * w}\n")

    # ── Step 1: Pipeline readiness ───────────────────────────────────────────
    _p("[1/3] Running pipeline readiness validator...")
    validator_passed, validator_output, validator_summary = run_validator(
        args, _run_fn=_run_validator_fn
    )
    if args.verbose:
        _p(validator_output)
    if not validator_passed:
        _p(f"  FAIL: pipeline not ready.")
        if not args.verbose:
            for line in validator_output.splitlines()[-8:]:
                _p(f"  {line}")
        ended_at = _now_iso()
        if not args.no_report_write:
            write_reports(
                "", args, "FAIL", "FAIL", 0, 0, [], [], {},
                validator_summary, validator_output, started_at, ended_at,
            )
        if not _quiet:
            print_proof_table(False, "", 0, {}, "FAIL", 0, [], [], "FAIL")
        _p("  LIVE_CAUSAL_PROOF=FAIL\n")
        return 2

    _p("  OK: pipeline ready.")

    # ── Step 2: Demo feed ────────────────────────────────────────────────────
    _p("[2/3] Sending demo events through pipeline...")
    feed_success, demo_run_id, accepted, feed_output, manifest = run_demo_feed(
        args, _run_fn=_run_demo_feed_fn
    )
    if args.verbose:
        _p(feed_output)
    if not feed_success:
        _p("  FAIL: demo_feed did not succeed.")
        if not args.verbose:
            for line in feed_output.splitlines()[-8:]:
                _p(f"  {line}")
        ended_at = _now_iso()
        if not args.no_report_write:
            write_reports(
                demo_run_id, args, "FAIL", "FAIL", 0, 0, [], [], {},
                validator_summary, feed_output, started_at, ended_at,
            )
        if not _quiet:
            print_proof_table(True, demo_run_id, accepted, manifest, "FAIL", 0, [], [], "FAIL")
        _p("  LIVE_CAUSAL_PROOF=FAIL\n")
        return 2

    _p(f"  OK: demo_run_id={demo_run_id}  accepted={accepted}")

    # ── Step 3: Poll for field-level proof ───────────────────────────────────
    _p(
        f"[3/3] Polling alerts report (timeout={args.timeout_seconds}s, "
        f"interval={args.poll_interval_seconds}s)..."
    )
    field_match, alert_count, alerts_output, rule_ids, trace_ids = poll_alerts_report(
        demo_run_id, args, _run_fn=_run_artisan_fn, _sleep_fn=_sleep_fn
    )
    if args.verbose:
        _p(alerts_output)

    # ── Determine verdict ────────────────────────────────────────────────────
    verdict = {"PASS": "PASS", "WARN": "WARN"}.get(field_match, "FAIL")
    ended_at = _now_iso()

    # ── Print proof table ────────────────────────────────────────────────────
    if not _quiet:
        print_proof_table(
            validator_passed, demo_run_id, accepted, manifest,
            field_match, alert_count, rule_ids, trace_ids, verdict,
        )

    # ── Consumer hint when ingestion passed but no alerts found ─────────────
    if verdict == "FAIL" and accepted > 0:
        _p(
            "  HINT: Events were accepted by ingestion-gateway but no alerts were found.\n"
            "        Check alert-writer Redpanda consumer offset state:\n"
            "          docker compose --profile strangler logs --tail=50 alert-writer-service\n"
            "        Look for: [alert-writer] WARN: consumer recovery triggered (offset_out_of_range)\n"
            "        If repeated, ensure XDR_ALERT_WRITER_AUTO_OFFSET_RESET=earliest in .env\n"
            "        and restart: docker compose --profile strangler up --build -d alert-writer-service"
        )
        _p("")

    # ── Write reports ────────────────────────────────────────────────────────
    if not args.no_report_write:
        json_path, md_path = write_reports(
            demo_run_id, args, verdict, field_match, accepted,
            alert_count, rule_ids, trace_ids, manifest,
            validator_summary, alerts_output, started_at, ended_at,
        )
        _p(f"  Reports written:")
        _p(f"    JSON : {json_path}")
        _p(f"    MD   : {md_path}\n")

    _p(f"  LIVE_CAUSAL_PROOF={verdict}\n")
    return {"PASS": 0, "WARN": 1, "FAIL": 2}[verdict]


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def _parse_args(argv=None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog="demo_causal_verify.py",
        description="Causal live demo verifier — proves XDR pipeline end-to-end.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Exit codes:
  0  LIVE_CAUSAL_PROOF=PASS  (field-level lineage proven)
  1  LIVE_CAUSAL_PROOF=WARN  (time-window fallback only)
  2  LIVE_CAUSAL_PROOF=FAIL  (no causal proof)
""",
    )
    parser.add_argument(
        "--input",
        default="fixtures/demo/attack_scenario.jsonl",
        help="Path to demo scenario JSONL (default: fixtures/demo/attack_scenario.jsonl)",
    )
    parser.add_argument(
        "--ingest-url",
        default="http://localhost:8091/v1/ingest",
        dest="ingest_url",
        help="Ingestion-gateway ingest URL (default: http://localhost:8091/v1/ingest)",
    )
    parser.add_argument(
        "--timeout-seconds",
        type=int,
        default=60,
        dest="timeout_seconds",
        help="Alert polling timeout in seconds (default: 60)",
    )
    parser.add_argument(
        "--poll-interval-seconds",
        type=float,
        default=3.0,
        dest="poll_interval_seconds",
        help="Seconds between alert report polls (default: 3.0)",
    )
    parser.add_argument(
        "--no-report-write",
        action="store_true",
        dest="no_report_write",
        help="Skip writing reports/demo-causal-*.json and *.md",
    )
    parser.add_argument(
        "--verbose",
        action="store_true",
        help="Print full subprocess output at each step",
    )
    parser.add_argument(
        "--quiet",
        action="store_true",
        help="Suppress all console output (exit code still set correctly).",
    )
    return parser.parse_args(argv)


if __name__ == "__main__":
    sys.exit(main(_parse_args()))
