#!/usr/bin/env python3
"""
Standalone Attack Lab.

Generates real HTTP traffic against a local target application. It does not
insert dummy alerts or write directly to detector tables.
"""

from __future__ import annotations

import argparse
import html
import json
import random
import re
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from http.cookiejar import CookieJar
from pathlib import Path
from typing import Dict, Iterable, List, Optional


LOCAL_HOSTS = {"127.0.0.1", "localhost", "::1"}


@dataclass
class RequestResult:
    method: str
    url: str
    status: int
    elapsed_ms: int
    ok: bool
    error: str = ""


@dataclass
class ScenarioResult:
    scenario: str
    target: str
    requests: List[RequestResult]
    started_at: float
    ended_at: float
    alert_report: str = ""
    report_file: str = ""

    @property
    def total(self) -> int:
        return len(self.requests)

    @property
    def failed(self) -> int:
        return sum(1 for r in self.requests if not r.ok)

    @property
    def duration_ms(self) -> int:
        return int((self.ended_at - self.started_at) * 1000)

    def to_dict(self) -> Dict[str, object]:
        return {
            "scenario": self.scenario,
            "target": self.target,
            "total_requests": self.total,
            "failed_requests": self.failed,
            "duration_ms": self.duration_ms,
            "requests": [r.__dict__ for r in self.requests],
            "alert_report": self.alert_report,
            "report_file": self.report_file,
        }


@dataclass
class CampaignResult:
    campaign_id: str
    campaign: str
    target: str
    started_at: float
    ended_at: float
    steps: List[Dict[str, object]]
    state_file: str
    alert_report: str = ""
    report_file: str = ""

    @property
    def total(self) -> int:
        return sum(int(s.get("total_requests", 0) or 0) for s in self.steps)

    @property
    def failed(self) -> int:
        return sum(int(s.get("failed_requests", 0) or 0) for s in self.steps)

    @property
    def duration_ms(self) -> int:
        return int((self.ended_at - self.started_at) * 1000)

    def to_dict(self) -> Dict[str, object]:
        return {
            "campaign_id": self.campaign_id,
            "campaign": self.campaign,
            "target": self.target,
            "total_requests": self.total,
            "failed_requests": self.failed,
            "duration_ms": self.duration_ms,
            "state_file": self.state_file,
            "steps": self.steps,
            "alert_report": self.alert_report,
            "report_file": self.report_file,
        }


class HttpClient:
    def __init__(self, base_url: str, source_ip: str, timeout: float = 5.0, spoof_ip: bool = True) -> None:
        self.base_url = base_url.rstrip("/")
        self.source_ip = source_ip
        self.timeout = timeout
        self.spoof_ip = spoof_ip
        self.cookies = CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))

    def request(
        self,
        method: str,
        path: str,
        params: Optional[Dict[str, str]] = None,
        form: Optional[Dict[str, str]] = None,
        extra_headers: Optional[Dict[str, str]] = None,
    ) -> RequestResult:
        query = ""
        if params:
            query = "?" + urllib.parse.urlencode(params)
        url = self.base_url + path + query
        data = None
        headers = {
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
            "(KHTML, like Gecko) Chrome/125.0 Safari/537.36",
            "Accept": "text/html,application/json,*/*",
        }
        if self.spoof_ip:
            headers["X-Forwarded-For"] = self.source_ip
        if extra_headers:
            headers.update(extra_headers)
        if form is not None:
            data = urllib.parse.urlencode(form).encode("utf-8")
            headers["Content-Type"] = "application/x-www-form-urlencoded"

        started = time.perf_counter()
        try:
            req = urllib.request.Request(url, data=data, headers=headers, method=method.upper())
            with self.opener.open(req, timeout=self.timeout) as resp:
                resp.read()
                status = int(resp.status)
            ok = True
            error = ""
        except urllib.error.HTTPError as exc:
            exc.read()
            status = int(exc.code)
            ok = True
            error = ""
        except Exception as exc:
            status = 0
            ok = False
            error = str(exc)

        elapsed_ms = int((time.perf_counter() - started) * 1000)
        return RequestResult(method.upper(), url, status, elapsed_ms, ok, error)

    def get_text(self, path: str) -> str:
        req = urllib.request.Request(
            self.base_url + path,
            headers={
                "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
                "(KHTML, like Gecko) Chrome/125.0 Safari/537.36",
            },
            method="GET",
        )
        if self.spoof_ip:
            req.add_header("X-Forwarded-For", self.source_ip)
        with self.opener.open(req, timeout=self.timeout) as resp:
            return resp.read().decode("utf-8", errors="replace")


def require_local_target(base_url: str, allow_non_local: bool) -> None:
    parsed = urllib.parse.urlparse(base_url)
    host = parsed.hostname or ""
    if allow_non_local:
        return
    if host not in LOCAL_HOSTS:
        raise ValueError("Attack Lab only targets localhost by default. Use --allow-non-local only in an authorized lab.")


def sleep_ms(value: int) -> None:
    if value > 0:
        time.sleep(value / 1000.0)


def csrf_token(client: HttpClient) -> str:
    body = client.get_text("/login")
    match = re.search(r'name="_token"\s+value="([^"]+)"', body)
    if not match:
        raise RuntimeError("CSRF token not found on /login. Is the Laravel app running?")
    return html.unescape(match.group(1))


def scenario_normal(client: HttpClient, count: int, sleep: int) -> List[RequestResult]:
    paths = [
        ("/", None),
        ("/login", None),
        ("/search", {"q": "library"}),
        ("/search", {"q": "dashboard"}),
        ("/search", {"q": "book catalog"}),
    ]
    out = []
    for idx in range(max(1, count)):
        path, params = paths[idx % len(paths)]
        out.append(client.request("GET", path, params=params))
        sleep_ms(sleep)
    return out


def scenario_bruteforce(client: HttpClient, count: int, sleep: int) -> List[RequestResult]:
    token = csrf_token(client)
    out = []
    for idx in range(max(1, count)):
        out.append(
            client.request(
                "POST",
                "/login",
                form={
                    "_token": token,
                    "email": f"attacker{idx}@example.test",
                    "password": "wrong-password",
                },
            )
        )
        sleep_ms(sleep)
    return out


def scenario_scan(client: HttpClient, count: int, sleep: int) -> List[RequestResult]:
    suspicious_paths = [
        "/.env",
        "/wp-admin",
        "/phpMyAdmin",
        "/vendor",
        "/server-status",
        "/backup.zip",
        "/admin.php",
        "/config.php",
        "/.git/config",
        "/api/.env",
        "/storage/logs/laravel.log",
        "/scan/path-{n}",
    ]
    out = []
    for idx in range(max(1, count)):
        template = suspicious_paths[idx % len(suspicious_paths)]
        out.append(client.request("GET", template.format(n=idx)))
        sleep_ms(sleep)
    return out


def scenario_injection(client: HttpClient, count: int, sleep: int) -> List[RequestResult]:
    payloads = [
        "' OR 1=1--",
        "<script>alert(1)</script>",
        "1 UNION SELECT email,password FROM users",
        "admin' AND SLEEP(1)--",
        "javascript:alert(1)",
    ]
    out = []
    for idx in range(max(1, count)):
        out.append(client.request("GET", "/search", params={"q": payloads[idx % len(payloads)]}))
        sleep_ms(sleep)
    return out


def scenario_privilege(client: HttpClient, count: int, sleep: int) -> List[RequestResult]:
    out = []
    for _ in range(max(1, count)):
        out.append(client.request("GET", "/admin"))
        sleep_ms(sleep)
    return out


def scenario_anomaly(client: HttpClient, count: int, sleep: int) -> List[RequestResult]:
    # Many valid-looking search requests from one IP. This is not a classic
    # payload attack, so it is useful for behavior/anomaly scoring.
    words = ["catalog", "loan", "journal", "member", "archive", "report", "isbn", "faculty"]
    out = []
    for idx in range(max(1, count)):
        query = f"{random.choice(words)}-{idx}-{random.randint(1000, 9999)}"
        out.append(client.request("GET", "/search", params={"q": query}))
        sleep_ms(sleep)
    return out


def tool_root() -> Path:
    return Path(__file__).resolve().parent


def read_lines(path: Path) -> List[str]:
    if not path.exists():
        raise FileNotFoundError(f"payload file not found: {path}")
    out = []
    for line in path.read_text(encoding="utf-8").splitlines():
        text = line.strip()
        if text and not text.startswith("#"):
            out.append(text)
    return out


def resolve_tool_path(value: str) -> Path:
    path = Path(value)
    if path.is_absolute():
        return path
    return tool_root() / path


def load_profile(path: str) -> Dict[str, object]:
    profile_path = resolve_tool_path(path)
    data = json.loads(profile_path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError("profile must be a JSON object")
    if not isinstance(data.get("steps"), list):
        raise ValueError("profile must contain steps array")
    data["_profile_path"] = str(profile_path)
    return data


def list_profiles() -> List[Path]:
    profiles_dir = tool_root() / "profiles"
    if not profiles_dir.exists():
        return []
    return sorted(profiles_dir.glob("*.json"))


def list_campaigns() -> List[Path]:
    campaigns_dir = tool_root() / "campaigns"
    if not campaigns_dir.exists():
        return []
    return sorted(campaigns_dir.glob("*.json"))


def load_campaign(path: str) -> Dict[str, object]:
    campaign_path = resolve_tool_path(path)
    data = json.loads(campaign_path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError("campaign must be a JSON object")
    if not isinstance(data.get("steps"), list):
        raise ValueError("campaign must contain steps array")
    data["_campaign_path"] = str(campaign_path)
    return data


def step_count(step: Dict[str, object], fallback: int) -> int:
    return max(1, int(step.get("count", fallback) or fallback))


def run_profile_steps(client: HttpClient, profile: Dict[str, object], default_count: int, default_sleep: int) -> List[RequestResult]:
    requests: List[RequestResult] = []
    csrf_cache = ""
    steps = profile.get("steps", [])
    if not isinstance(steps, list):
        raise ValueError("profile steps must be array")

    for raw_step in steps:
        if not isinstance(raw_step, dict):
            continue
        step = dict(raw_step)
        kind = str(step.get("type", "request"))
        count = step_count(step, default_count)
        sleep = max(0, int(step.get("sleep_ms", default_sleep) or 0))

        if kind == "normal":
            requests.extend(scenario_normal(client, count, sleep))
            continue
        if kind == "bruteforce":
            if not csrf_cache:
                csrf_cache = csrf_token(client)
            for idx in range(count):
                email_template = str(step.get("email_template", "attacker{n}@example.test"))
                requests.append(
                    client.request(
                        "POST",
                        str(step.get("path", "/login")),
                        form={
                            "_token": csrf_cache,
                            "email": email_template.format(n=idx),
                            "password": str(step.get("password", "wrong-password")),
                        },
                    )
                )
                sleep_ms(sleep)
            continue
        if kind == "scan":
            payload_file = str(step.get("payload_file", "payloads/scan-paths.txt"))
            paths = read_lines(resolve_tool_path(payload_file))
            for idx in range(count):
                requests.append(client.request("GET", paths[idx % len(paths)].format(n=idx)))
                sleep_ms(sleep)
            continue
        if kind == "injection":
            payloads: List[str] = []
            for item in step.get("payload_files", []) or []:
                payloads.extend(read_lines(resolve_tool_path(str(item))))
            payloads.extend(str(x) for x in (step.get("payloads", []) or []))
            if not payloads:
                payloads = ["' OR 1=1--", "<script>alert(1)</script>"]
            param = str(step.get("param", "q"))
            path = str(step.get("path", "/search"))
            for idx in range(count):
                requests.append(client.request("GET", path, params={param: payloads[idx % len(payloads)]}))
                sleep_ms(sleep)
            continue
        if kind == "anomaly":
            requests.extend(scenario_anomaly(client, count, sleep))
            continue

        method = str(step.get("method", "GET"))
        path = str(step.get("path", "/"))
        params = step.get("params")
        form = step.get("form")
        requests.extend(
            client.request(
                method,
                path.format(n=idx),
                params={str(k): str(v).format(n=idx) for k, v in params.items()} if isinstance(params, dict) else None,
                form={str(k): str(v).format(n=idx) for k, v in form.items()} if isinstance(form, dict) else None,
            )
            for idx in range(count)
        )
        sleep_ms(sleep)

    return requests


def crawl_target(client: HttpClient, start_path: str = "/", limit: int = 25) -> List[str]:
    seen = set()
    queue = [start_path]
    out = []
    while queue and len(out) < limit:
        path = queue.pop(0)
        if path in seen:
            continue
        seen.add(path)
        out.append(path)
        try:
            body = client.get_text(path)
        except Exception:
            continue
        for href in re.findall(r'href=["\']([^"\']+)["\']', body, flags=re.IGNORECASE):
            parsed = urllib.parse.urlparse(href)
            if parsed.scheme or parsed.netloc:
                continue
            next_path = parsed.path or "/"
            if next_path.startswith("/") and next_path not in seen:
                queue.append(next_path)
    return out


def write_report(result: ScenarioResult, report_dir: str) -> str:
    if not report_dir:
        return ""
    out_dir = Path(report_dir)
    if not out_dir.is_absolute():
        out_dir = tool_root() / out_dir
    out_dir.mkdir(parents=True, exist_ok=True)
    stamp = time.strftime("%Y%m%d-%H%M%S")
    safe_name = re.sub(r"[^a-zA-Z0-9_.-]+", "-", result.scenario).strip("-") or "attack-lab"
    json_path = out_dir / f"{stamp}-{safe_name}.json"
    html_path = out_dir / f"{stamp}-{safe_name}.html"
    json_path.write_text(json.dumps(result.to_dict(), indent=2), encoding="utf-8")
    rows = "\n".join(
        f"<tr><td>{html.escape(r.method)}</td><td>{r.status}</td><td>{r.elapsed_ms} ms</td><td>{html.escape(r.url)}</td><td>{html.escape(r.error)}</td></tr>"
        for r in result.requests
    )
    html_doc = f"""<!doctype html>
<html><head><meta charset="utf-8"><title>Attack Lab Report</title>
<style>body{{font-family:Arial,sans-serif;margin:32px}}table{{border-collapse:collapse;width:100%}}td,th{{border:1px solid #ddd;padding:6px}}pre{{background:#111;color:#eee;padding:12px;white-space:pre-wrap}}</style>
</head><body>
<h1>Attack Lab Report</h1>
<p><b>Scenario:</b> {html.escape(result.scenario)}</p>
<p><b>Target:</b> {html.escape(result.target)}</p>
<p><b>Requests:</b> {result.total} | <b>Failed:</b> {result.failed} | <b>Duration:</b> {result.duration_ms} ms</p>
<h2>Requests</h2><table><thead><tr><th>Method</th><th>Status</th><th>Time</th><th>URL</th><th>Error</th></tr></thead><tbody>{rows}</tbody></table>
<h2>Detector Alert Report</h2><pre>{html.escape(result.alert_report)}</pre>
</body></html>"""
    html_path.write_text(html_doc, encoding="utf-8")
    return str(html_path)


def write_campaign_report(result: CampaignResult, report_dir: str) -> str:
    if not report_dir:
        return ""
    out_dir = Path(report_dir)
    if not out_dir.is_absolute():
        out_dir = tool_root() / out_dir
    out_dir.mkdir(parents=True, exist_ok=True)
    stamp = time.strftime("%Y%m%d-%H%M%S")
    safe_name = re.sub(r"[^a-zA-Z0-9_.-]+", "-", result.campaign).strip("-") or "campaign"
    json_path = out_dir / f"{stamp}-{safe_name}-campaign.json"
    html_path = out_dir / f"{stamp}-{safe_name}-campaign.html"
    json_path.write_text(json.dumps(result.to_dict(), indent=2), encoding="utf-8")
    rows = "\n".join(
        f"<tr><td>{html.escape(str(s.get('id', '')))}</td><td>{html.escape(str(s.get('type', '')))}</td><td>{html.escape(str(s.get('status', '')))}</td><td>{s.get('total_requests', 0)}</td><td>{s.get('failed_requests', 0)}</td><td>{html.escape(str(s.get('reason', '')))}</td></tr>"
        for s in result.steps
    )
    html_doc = f"""<!doctype html>
<html><head><meta charset="utf-8"><title>Attack Lab Campaign Report</title>
<style>body{{font-family:Arial,sans-serif;margin:32px}}table{{border-collapse:collapse;width:100%}}td,th{{border:1px solid #ddd;padding:6px}}pre{{background:#111;color:#eee;padding:12px;white-space:pre-wrap}}</style>
</head><body>
<h1>Attack Lab Campaign Report</h1>
<p><b>Campaign:</b> {html.escape(result.campaign)}</p>
<p><b>Campaign ID:</b> {html.escape(result.campaign_id)}</p>
<p><b>Target:</b> {html.escape(result.target)}</p>
<p><b>Requests:</b> {result.total} | <b>Failed:</b> {result.failed} | <b>Duration:</b> {result.duration_ms} ms</p>
<p><b>State:</b> {html.escape(result.state_file)}</p>
<h2>Steps</h2><table><thead><tr><th>ID</th><th>Type</th><th>Status</th><th>Requests</th><th>Failed</th><th>Reason</th></tr></thead><tbody>{rows}</tbody></table>
<h2>Detector Alert Report</h2><pre>{html.escape(result.alert_report)}</pre>
</body></html>"""
    html_path.write_text(html_doc, encoding="utf-8")
    return str(html_path)


def state_dir() -> Path:
    path = tool_root() / "state"
    path.mkdir(parents=True, exist_ok=True)
    return path


def save_campaign_state(campaign_id: str, state: Dict[str, object]) -> str:
    path = state_dir() / f"{campaign_id}.json"
    path.write_text(json.dumps(state, indent=2), encoding="utf-8")
    return str(path)


def campaign_condition_passes(condition: Dict[str, object], state: Dict[str, object]) -> Tuple[bool, str]:
    if not condition:
        return True, ""
    previous_failed = int(state.get("previous_failed", 0) or 0)
    total_requests = int(state.get("total_requests", 0) or 0)
    if "previous_failed_lte" in condition and previous_failed > int(condition["previous_failed_lte"]):
        return False, f"previous_failed>{condition['previous_failed_lte']}"
    if "min_total_requests" in condition and total_requests < int(condition["min_total_requests"]):
        return False, f"total_requests<{condition['min_total_requests']}"
    return True, ""


def run_campaign(
    campaign_path: str,
    base_url: str,
    source_ip: str,
    sleep: int,
    detector_root: str = "",
    detector_mode: str = "none",
    detection_mode: str = "current",
    allow_non_local: bool = False,
    spoof_ip: bool = True,
    report_dir: str = "",
) -> CampaignResult:
    campaign = load_campaign(campaign_path)
    defaults = campaign.get("defaults", {})
    if isinstance(defaults, dict):
        base_url = str(defaults.get("base_url", base_url) or base_url)
        source_ip = str(defaults.get("source_ip", source_ip) or source_ip)
        sleep = int(defaults.get("sleep_ms", sleep) or sleep)
        spoof_ip = bool(defaults.get("spoof_ip", spoof_ip))
    require_local_target(base_url, allow_non_local)

    campaign_id = f"{time.strftime('%Y%m%d-%H%M%S')}-{re.sub(r'[^a-zA-Z0-9_.-]+', '-', str(campaign.get('name', 'campaign')))}"
    state: Dict[str, object] = {
        "campaign_id": campaign_id,
        "campaign": campaign.get("name", Path(campaign_path).stem),
        "target": base_url,
        "started_at": time.time(),
        "total_requests": 0,
        "failed_requests": 0,
        "previous_failed": 0,
        "completed_steps": [],
        "skipped_steps": [],
    }
    save_campaign_state(campaign_id, state)

    started = time.time()
    step_summaries: List[Dict[str, object]] = []
    stop_on_failure = bool(campaign.get("stop_on_step_failure", False))
    for idx, raw_step in enumerate(campaign.get("steps", [])):
        if not isinstance(raw_step, dict):
            continue
        step = dict(raw_step)
        step_id = str(step.get("id", f"step-{idx + 1}"))
        step_type = str(step.get("type", "profile"))
        if step.get("enabled", True) is False:
            step_summaries.append({"id": step_id, "type": step_type, "status": "skipped", "reason": "disabled"})
            continue
        ok, reason = campaign_condition_passes(step.get("when", {}) if isinstance(step.get("when"), dict) else {}, state)
        if not ok:
            state["skipped_steps"] = list(state.get("skipped_steps", [])) + [step_id]
            step_summaries.append({"id": step_id, "type": step_type, "status": "skipped", "reason": reason})
            save_campaign_state(campaign_id, state)
            continue

        if step_type == "profile":
            profile_path = str(step.get("profile", ""))
            result = run_scenario(
                "full",
                base_url,
                int(step.get("count", 60) or 60),
                source_ip,
                int(step.get("sleep_ms", sleep) or sleep),
                allow_non_local=allow_non_local,
                spoof_ip=spoof_ip,
                profile=profile_path,
                report_dir="",
            )
        elif step_type == "crawl":
            result = run_scenario(
                "full",
                base_url,
                int(step.get("count", 25) or 25),
                source_ip,
                int(step.get("sleep_ms", sleep) or sleep),
                allow_non_local=allow_non_local,
                spoof_ip=spoof_ip,
                crawl=True,
                report_dir="",
            )
        else:
            mini_profile = {"name": step_id, "steps": [step]}
            tmp_path = state_dir() / f"{campaign_id}-{step_id}.json"
            tmp_path.write_text(json.dumps(mini_profile), encoding="utf-8")
            result = run_scenario(
                "full",
                base_url,
                int(step.get("count", 1) or 1),
                source_ip,
                int(step.get("sleep_ms", sleep) or sleep),
                allow_non_local=allow_non_local,
                spoof_ip=spoof_ip,
                profile=str(tmp_path),
                report_dir="",
            )

        state["previous_failed"] = result.failed
        state["total_requests"] = int(state.get("total_requests", 0) or 0) + result.total
        state["failed_requests"] = int(state.get("failed_requests", 0) or 0) + result.failed
        state["completed_steps"] = list(state.get("completed_steps", [])) + [step_id]
        step_summaries.append(
            {
                "id": step_id,
                "type": step_type,
                "status": "completed" if result.failed == 0 else "completed_with_failures",
                "scenario": result.scenario,
                "total_requests": result.total,
                "failed_requests": result.failed,
                "duration_ms": result.duration_ms,
            }
        )
        save_campaign_state(campaign_id, state)
        if stop_on_failure and result.failed > 0:
            break

    pipeline_report = run_detector_pipeline(detector_root, detector_mode, detection_mode)
    coverage_report = run_coverage_matrix(detector_root, str(campaign.get("coverage_expectations", "")))
    if detector_root:
        time.sleep(2)
    alert_report = run_alert_report(detector_root, 15)
    if pipeline_report:
        alert_report = f"{pipeline_report}\n\n=== Alert Report ===\n{alert_report}".strip()
    if coverage_report:
        alert_report = f"{alert_report}\n\n=== Coverage Matrix ===\n{coverage_report}".strip()
    ended = time.time()
    state["ended_at"] = ended
    state_file = save_campaign_state(campaign_id, state)
    result = CampaignResult(campaign_id, str(campaign.get("name", Path(campaign_path).stem)), base_url, started, ended, step_summaries, state_file, alert_report)
    result.report_file = write_campaign_report(result, report_dir)
    return result


def run_alert_report(detector_root: str, minutes: int) -> str:
    if not detector_root:
        return ""
    root = Path(detector_root).resolve()
    if not (root / "artisan").exists():
        return f"Detector root does not contain artisan: {root}"
    try:
        proc = subprocess.run(
            ["php", "artisan", "security:alerts-report", f"--minutes={minutes}"],
            cwd=str(root),
            text=True,
            capture_output=True,
            timeout=30,
        )
    except Exception as exc:
        return f"Unable to run alert report: {exc}"
    return (proc.stdout or proc.stderr).strip()


def run_detector_command(detector_root: str, args: List[str], timeout: int = 60) -> str:
    if not detector_root:
        return ""
    root = Path(detector_root).resolve()
    if not root.exists():
        return f"Detector root not found: {root}"
    try:
        proc = subprocess.run(
            args,
            cwd=str(root),
            text=True,
            capture_output=True,
            timeout=timeout,
        )
    except Exception as exc:
        return f"Unable to run {' '.join(args)}: {exc}"
    text = "\n".join(x for x in [proc.stdout.strip(), proc.stderr.strip()] if x)
    return text or f"{' '.join(args)} completed with exit code {proc.returncode}"


def run_detector_pipeline(detector_root: str, mode: str, detection_mode: str) -> str:
    if not detector_root or mode == "none":
        return ""

    outputs = []
    if mode in {"ingest", "replay"}:
        outputs.append("=== Ingest JSONL ===")
        outputs.append(run_detector_command(detector_root, ["php", "artisan", "security:ingest"], timeout=60))

    if mode == "replay":
        outputs.append("=== Replay Detector ===")
        outputs.append(
            run_detector_command(
                detector_root,
                [
                    sys.executable,
                    "scripts/replay_detector_from_db.py",
                    "--use-active-deployment=0",
                    "--require-lock=0",
                    "--response-mode=recommend",
                    f"--detection-mode={detection_mode}",
                ],
                timeout=90,
            )
        )

    return "\n".join(outputs).strip()


def run_coverage_matrix(detector_root: str, expectations: str) -> str:
    if not detector_root or not expectations:
        return ""
    root = Path(detector_root).resolve()
    expectation_path = Path(expectations)
    if not expectation_path.is_absolute():
        expectation_path = root / expectations
    output_path = root / "tools" / "attack-lab" / "reports" / f"coverage-{int(time.time())}.json"
    return run_detector_command(
        detector_root,
        [
            sys.executable,
            "scripts/detector_coverage_matrix.py",
            "--expectations",
            str(expectation_path),
            "--format",
            "json",
            "--output",
            str(output_path),
        ],
        timeout=60,
    )


def run_scenario(
    scenario: str,
    base_url: str,
    count: int,
    source_ip: str,
    sleep: int,
    detector_root: str = "",
    report_minutes: int = 15,
    allow_non_local: bool = False,
    detector_mode: str = "none",
    spoof_ip: bool = True,
    profile: str = "",
    crawl: bool = False,
    report_dir: str = "",
    detection_mode: str = "current",
) -> ScenarioResult:
    profile_data: Optional[Dict[str, object]] = None
    if profile:
        profile_data = load_profile(profile)
        defaults = profile_data.get("defaults", {})
        if isinstance(defaults, dict):
            base_url = str(defaults.get("base_url", base_url) or base_url)
            count = int(defaults.get("count", count) or count)
            sleep = int(defaults.get("sleep_ms", sleep) or sleep)
            source_ip = str(defaults.get("source_ip", source_ip) or source_ip)
            spoof_ip = bool(defaults.get("spoof_ip", spoof_ip))

    require_local_target(base_url, allow_non_local)
    client = HttpClient(base_url, source_ip, spoof_ip=spoof_ip)
    started = time.time()

    if profile_data is not None:
        scenario_name = str(profile_data.get("name") or Path(profile).stem)
        requests = run_profile_steps(client, profile_data, count, sleep)
    elif crawl:
        paths = crawl_target(client, "/", min(max(count, 1), 100))
        requests = []
        for path in paths:
            requests.append(client.request("GET", path))
            sleep_ms(sleep)
        scenario_name = "crawl"
    elif scenario == "normal":
        requests = scenario_normal(client, count, sleep)
        scenario_name = scenario
    elif scenario == "bruteforce":
        requests = scenario_bruteforce(client, count, sleep)
        scenario_name = scenario
    elif scenario == "scan":
        requests = scenario_scan(client, count, sleep)
        scenario_name = scenario
    elif scenario == "injection":
        requests = scenario_injection(client, count, sleep)
        scenario_name = scenario
    elif scenario == "privilege":
        requests = scenario_privilege(client, count, sleep)
        scenario_name = scenario
    elif scenario == "anomaly":
        requests = scenario_anomaly(client, count, sleep)
        scenario_name = scenario
    elif scenario == "full":
        requests = []
        requests.extend(scenario_normal(client, max(5, count // 6), sleep))
        requests.extend(scenario_bruteforce(client, max(15, count // 3), sleep))
        requests.extend(scenario_scan(client, max(25, count // 3), sleep))
        requests.extend(scenario_injection(client, max(5, count // 8), sleep))
        requests.extend(scenario_privilege(client, max(3, count // 12), sleep))
        requests.extend(scenario_anomaly(client, max(20, count // 3), sleep))
        scenario_name = scenario
    else:
        raise ValueError(f"Unknown scenario: {scenario}")

    pipeline_report = run_detector_pipeline(detector_root, detector_mode, detection_mode)
    coverage_report = ""
    if profile_data is not None:
        coverage_report = run_coverage_matrix(detector_root, str(profile_data.get("coverage_expectations", "")))

    # Give the detector pipeline a short moment if the caller asks for report.
    if detector_root:
        time.sleep(2)
    ended = time.time()
    report = run_alert_report(detector_root, report_minutes)
    if pipeline_report:
        report = f"{pipeline_report}\n\n=== Alert Report ===\n{report}".strip()
    if coverage_report:
        report = f"{report}\n\n=== Coverage Matrix ===\n{coverage_report}".strip()
    result = ScenarioResult(scenario_name, base_url, requests, started, ended, report)
    result.report_file = write_report(result, report_dir)
    return result


def print_result(result: ScenarioResult, as_json: bool) -> None:
    if as_json:
        print(json.dumps(result.to_dict(), indent=2))
        return

    print("=== Attack Lab Result ===")
    print(f"Scenario: {result.scenario}")
    print(f"Target: {result.target}")
    print(f"Requests: {result.total}")
    print(f"Failed: {result.failed}")
    print(f"Duration: {result.duration_ms} ms")
    print("")
    print("Latest request samples:")
    for item in result.requests[-10:]:
        status = item.status if item.status else "ERR"
        print(f"- {item.method} {item.url} -> {status} ({item.elapsed_ms} ms)")
    if result.alert_report:
        print("")
        print("=== Detector Alert Report ===")
        print(result.alert_report)


def print_campaign_result(result: CampaignResult, as_json: bool) -> None:
    if as_json:
        print(json.dumps(result.to_dict(), indent=2))
        return
    print("=== Attack Lab Campaign Result ===")
    print(f"Campaign: {result.campaign}")
    print(f"Campaign ID: {result.campaign_id}")
    print(f"Target: {result.target}")
    print(f"Requests: {result.total}")
    print(f"Failed: {result.failed}")
    print(f"Duration: {result.duration_ms} ms")
    print(f"State: {result.state_file}")
    print(f"Report: {result.report_file or '-'}")
    print("")
    print("Steps:")
    for step in result.steps:
        print(f"- {step.get('id')} [{step.get('type')}] {step.get('status')} requests={step.get('total_requests', 0)} failed={step.get('failed_requests', 0)}")
    if result.alert_report:
        print("")
        print("=== Detector Alert Report ===")
        print(result.alert_report)


def parse_args(argv: Optional[Iterable[str]] = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Standalone Attack Lab traffic generator.")
    parser.add_argument(
        "scenario",
        choices=["normal", "bruteforce", "scan", "injection", "privilege", "anomaly", "full"],
        nargs="?",
        default="full",
    )
    parser.add_argument("--profile", default="", help="Run a JSON profile from tools/attack-lab/profiles or an absolute path.")
    parser.add_argument("--campaign", default="", help="Run a stateful campaign JSON from tools/attack-lab/campaigns or an absolute path.")
    parser.add_argument("--list-profiles", action="store_true")
    parser.add_argument("--list-campaigns", action="store_true")
    parser.add_argument("--crawl", action="store_true", help="Lightweight same-origin crawler mode.")
    parser.add_argument("--base-url", default="http://127.0.0.1:8000")
    parser.add_argument("--count", type=int, default=60)
    parser.add_argument("--ip", default="203.0.113.50")
    parser.add_argument(
        "--real-source-ip",
        action="store_true",
        help="Do not send X-Forwarded-For. The app will see the real socket source IP.",
    )
    parser.add_argument("--sleep-ms", type=int, default=20)
    parser.add_argument("--detector-root", default="")
    parser.add_argument("--detector-mode", choices=["none", "ingest", "replay"], default="none")
    parser.add_argument("--detection-mode", choices=["current", "advanced"], default="current")
    parser.add_argument("--report-minutes", type=int, default=15)
    parser.add_argument("--allow-non-local", action="store_true")
    parser.add_argument("--json", action="store_true")
    parser.add_argument("--report-dir", default="reports")
    return parser.parse_args(list(argv) if argv is not None else None)


def main(argv: Optional[Iterable[str]] = None) -> int:
    args = parse_args(argv)
    if args.list_profiles:
        for path in list_profiles():
            print(path)
        return 0
    if args.list_campaigns:
        for path in list_campaigns():
            print(path)
        return 0
    try:
        if args.campaign:
            campaign_result = run_campaign(
                campaign_path=args.campaign,
                base_url=args.base_url,
                source_ip=args.ip,
                sleep=max(0, args.sleep_ms),
                detector_root=args.detector_root,
                detector_mode=args.detector_mode,
                detection_mode=args.detection_mode,
                allow_non_local=bool(args.allow_non_local),
                spoof_ip=not bool(args.real_source_ip),
                report_dir=args.report_dir,
            )
            print_campaign_result(campaign_result, bool(args.json))
            return 0
        result = run_scenario(
            scenario=args.scenario,
            base_url=args.base_url,
            count=max(1, args.count),
            source_ip=args.ip,
            sleep=max(0, args.sleep_ms),
            detector_root=args.detector_root,
            report_minutes=max(1, args.report_minutes),
            allow_non_local=bool(args.allow_non_local),
            detector_mode=args.detector_mode,
            spoof_ip=not bool(args.real_source_ip),
            profile=args.profile,
            crawl=bool(args.crawl),
            report_dir=args.report_dir,
            detection_mode=args.detection_mode,
        )
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1
    print_result(result, bool(args.json))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
