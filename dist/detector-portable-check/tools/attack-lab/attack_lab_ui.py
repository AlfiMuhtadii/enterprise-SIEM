#!/usr/bin/env python3
"""
Local web UI for Attack Lab.
"""

from __future__ import annotations

import html
import json
import urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Dict

from attack_lab import list_campaigns, list_profiles, run_campaign, run_scenario


DEFAULT_BASE_URL = "http://127.0.0.1:8000"


def page(body: str) -> bytes:
    return f"""<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Detector Attack Lab</title>
  <style>
    :root {{
      --bg: #101820;
      --panel: #f7efe5;
      --ink: #101820;
      --muted: #64748b;
      --line: #d7c6b5;
      --accent: #ff6b35;
      --accent2: #0f766e;
    }}
    * {{ box-sizing: border-box; }}
    body {{
      margin: 0;
      font-family: Georgia, 'Times New Roman', serif;
      background:
        radial-gradient(circle at 20% 10%, rgba(255,107,53,.22), transparent 28rem),
        linear-gradient(135deg, #101820, #21313f 55%, #102a2a);
      color: var(--panel);
      min-height: 100vh;
    }}
    main {{ max-width: 1120px; margin: 0 auto; padding: 42px 20px; }}
    h1 {{ font-size: clamp(2rem, 5vw, 4.8rem); line-height: .9; margin: 0 0 14px; letter-spacing: -0.06em; }}
    .lead {{ color: #e8d8c8; max-width: 760px; font-size: 1.1rem; }}
    .grid {{ display: grid; grid-template-columns: 1fr 1.1fr; gap: 18px; align-items: start; margin-top: 28px; }}
    .card {{ background: var(--panel); color: var(--ink); border: 1px solid var(--line); border-radius: 22px; padding: 20px; box-shadow: 0 20px 60px rgba(0,0,0,.22); }}
    label {{ display: block; font-size: .84rem; color: var(--muted); margin: 13px 0 6px; }}
    input, select {{ width: 100%; border: 1px solid var(--line); border-radius: 12px; padding: 11px 12px; font: inherit; background: #fffaf3; color: var(--ink); }}
    button {{ border: 0; border-radius: 999px; background: var(--accent); color: white; padding: 12px 16px; font-weight: 700; cursor: pointer; margin-top: 16px; width: 100%; }}
    .quick {{ display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-top: 14px; }}
    .quick button {{ margin: 0; background: var(--accent2); }}
    pre {{ white-space: pre-wrap; background: #091116; color: #d4f7ec; padding: 16px; border-radius: 16px; overflow: auto; max-height: 560px; }}
    table {{ width: 100%; border-collapse: collapse; margin-top: 12px; }}
    td, th {{ border-bottom: 1px solid var(--line); padding: 8px; text-align: left; font-size: .92rem; }}
    .pill {{ display: inline-block; padding: 5px 9px; border-radius: 999px; background: #ffe1d4; color: #9a3412; font-size: .8rem; }}
    @media (max-width: 860px) {{ .grid {{ grid-template-columns: 1fr; }} }}
  </style>
</head>
<body>
  <main>
    <span class="pill">Local-only traffic generator</span>
    <h1>Detector Attack Lab</h1>
    <p class="lead">Menjalankan request HTTP nyata ke aplikasi target. Tools ini tidak mengisi dummy alert dan tidak menulis langsung ke database detector.</p>
    {body}
  </main>
</body>
</html>""".encode("utf-8")


def form_html(values: Dict[str, str]) -> str:
    base_url = html.escape(values.get("base_url", DEFAULT_BASE_URL))
    detector_root = html.escape(values.get("detector_root", ""))
    count = html.escape(values.get("count", "60"))
    ip = html.escape(values.get("ip", "203.0.113.50"))
    sleep_ms = html.escape(values.get("sleep_ms", "20"))
    scenario = values.get("scenario", "full")
    detector_mode = values.get("detector_mode", "replay")
    detection_mode = values.get("detection_mode", "advanced")
    real_source_ip = values.get("real_source_ip", "")
    selected_profile = values.get("profile", "")
    selected_campaign = values.get("campaign", "")
    profile_options = '<option value="">built-in scenario</option>' + "".join(
        f'<option value="{html.escape(str(path))}" {"selected" if selected_profile == str(path) else ""}>{html.escape(path.stem)}</option>'
        for path in list_profiles()
    )
    campaign_options = '<option value="">no campaign</option>' + "".join(
        f'<option value="{html.escape(str(path))}" {"selected" if selected_campaign == str(path) else ""}>{html.escape(path.stem)}</option>'
        for path in list_campaigns()
    )
    options = "".join(
        f'<option value="{s}" {"selected" if scenario == s else ""}>{s}</option>'
        for s in ["normal", "bruteforce", "scan", "injection", "privilege", "anomaly", "full"]
    )
    return f"""
<div class="grid">
  <section class="card">
    <form method="post" action="/run">
      <label>Scenario</label>
      <select name="scenario">{options}</select>
      <label>Profile</label>
      <select name="profile">{profile_options}</select>
      <small>Jika profile dipilih, scenario bawaan diabaikan.</small>
      <label>Campaign</label>
      <select name="campaign">{campaign_options}</select>
      <small>Jika campaign dipilih, profile dan scenario bawaan diabaikan.</small>
      <label>Target Base URL</label>
      <input name="base_url" value="{base_url}">
      <label>Source IP Header</label>
      <input name="ip" value="{ip}">
      <label>
        <input style="width:auto" type="checkbox" name="real_source_ip" value="1" {"checked" if real_source_ip == "1" else ""}>
        Use real socket source IP, do not send X-Forwarded-For
      </label>
      <label>Request Count</label>
      <input name="count" type="number" min="1" max="500" value="{count}">
      <label>Sleep Between Requests (ms)</label>
      <input name="sleep_ms" type="number" min="0" max="5000" value="{sleep_ms}">
      <label>Detector Root Optional, for alert report</label>
      <input name="detector_root" value="{detector_root}" placeholder="D:\\project\\Detector">
      <label>Detector Mode</label>
      <select name="detector_mode">
        <option value="none" {"selected" if detector_mode == "none" else ""}>none - request only</option>
        <option value="ingest" {"selected" if detector_mode == "ingest" else ""}>ingest - import JSONL to DB</option>
        <option value="replay" {"selected" if detector_mode == "replay" else ""}>replay - ingest + run detector</option>
      </select>
      <label>Detection Version</label>
      <select name="detection_mode">
        <option value="current" {"selected" if detection_mode == "current" else ""}>current - existing detector behavior</option>
        <option value="advanced" {"selected" if detection_mode == "advanced" else ""}>advanced - current + threat correlation + MITRE</option>
      </select>
      <button type="submit">Run Scenario</button>
    </form>
    <form class="quick" method="post" action="/run">
      <input type="hidden" name="base_url" value="{base_url}">
      <input type="hidden" name="ip" value="{ip}">
      <input type="hidden" name="real_source_ip" value="{html.escape(real_source_ip)}">
      <input type="hidden" name="count" value="{count}">
      <input type="hidden" name="sleep_ms" value="{sleep_ms}">
      <input type="hidden" name="detector_root" value="{detector_root}">
      <input type="hidden" name="detector_mode" value="{html.escape(detector_mode)}">
      <input type="hidden" name="detection_mode" value="{html.escape(detection_mode)}">
      <input type="hidden" name="profile" value="{html.escape(selected_profile)}">
      <input type="hidden" name="campaign" value="{html.escape(selected_campaign)}">
      {''.join(f'<button name="scenario" value="{s}">{s}</button>' for s in ["bruteforce", "scan", "injection", "anomaly", "full"])}
    </form>
  </section>
"""


def result_html(result) -> str:
    rows = "\n".join(
        "<tr>"
        f"<td>{html.escape(r.method)}</td>"
        f"<td>{html.escape(str(r.status))}</td>"
        f"<td>{html.escape(str(r.elapsed_ms))} ms</td>"
        f"<td>{html.escape(r.url)}</td>"
        "</tr>"
        for r in result.requests[-20:]
    )
    report = html.escape(result.alert_report or "Detector report not requested. Fill Detector Root to show alert summary.")
    return f"""
  <section class="card">
    <h2>Result</h2>
    <p><b>Scenario:</b> {html.escape(result.scenario)}</p>
    <p><b>Total requests:</b> {result.total} | <b>Failed:</b> {result.failed} | <b>Duration:</b> {result.duration_ms} ms</p>
    <p><b>Report:</b> {html.escape(result.report_file or '-')}</p>
    <table>
      <thead><tr><th>Method</th><th>Status</th><th>Time</th><th>URL</th></tr></thead>
      <tbody>{rows}</tbody>
    </table>
    <h3>Alert Report</h3>
    <pre>{report}</pre>
  </section>
</div>
"""


def campaign_result_html(result) -> str:
    rows = "\n".join(
        "<tr>"
        f"<td>{html.escape(str(s.get('id', '')))}</td>"
        f"<td>{html.escape(str(s.get('type', '')))}</td>"
        f"<td>{html.escape(str(s.get('status', '')))}</td>"
        f"<td>{html.escape(str(s.get('total_requests', 0)))}</td>"
        f"<td>{html.escape(str(s.get('failed_requests', 0)))}</td>"
        "</tr>"
        for s in result.steps
    )
    report = html.escape(result.alert_report or "Detector report not requested. Fill Detector Root to show alert summary.")
    return f"""
  <section class="card">
    <h2>Campaign Result</h2>
    <p><b>Campaign:</b> {html.escape(result.campaign)}</p>
    <p><b>Campaign ID:</b> {html.escape(result.campaign_id)}</p>
    <p><b>Total requests:</b> {result.total} | <b>Failed:</b> {result.failed} | <b>Duration:</b> {result.duration_ms} ms</p>
    <p><b>State:</b> {html.escape(result.state_file or '-')}</p>
    <p><b>Report:</b> {html.escape(result.report_file or '-')}</p>
    <table>
      <thead><tr><th>ID</th><th>Type</th><th>Status</th><th>Requests</th><th>Failed</th></tr></thead>
      <tbody>{rows}</tbody>
    </table>
    <h3>Alert Report</h3>
    <pre>{report}</pre>
  </section>
</div>
"""


class Handler(BaseHTTPRequestHandler):
    def do_GET(self) -> None:
        self.respond(page(form_html({}) + '<section class="card"><h2>Status</h2><p>Pilih scenario lalu klik Run Scenario.</p></section></div>'))

    def do_POST(self) -> None:
        length = int(self.headers.get("Content-Length", "0") or "0")
        payload = self.rfile.read(length).decode("utf-8", errors="replace")
        values = {k: v[0] for k, v in urllib.parse.parse_qs(payload).items()}
        try:
            if values.get("campaign", ""):
                result = run_campaign(
                    campaign_path=values.get("campaign", ""),
                    base_url=values.get("base_url", DEFAULT_BASE_URL),
                    source_ip=values.get("ip", "203.0.113.50"),
                    sleep=min(max(int(values.get("sleep_ms", "20")), 0), 5000),
                    detector_root=values.get("detector_root", ""),
                    detector_mode=values.get("detector_mode", "replay"),
                    detection_mode=values.get("detection_mode", "advanced"),
                    allow_non_local=False,
                    spoof_ip=values.get("real_source_ip") != "1",
                    report_dir="reports",
                )
                body = form_html(values) + campaign_result_html(result)
            else:
                result = run_scenario(
                    scenario=values.get("scenario", "full"),
                    base_url=values.get("base_url", DEFAULT_BASE_URL),
                    count=min(max(int(values.get("count", "60")), 1), 500),
                    source_ip=values.get("ip", "203.0.113.50"),
                    sleep=min(max(int(values.get("sleep_ms", "20")), 0), 5000),
                    detector_root=values.get("detector_root", ""),
                    detector_mode=values.get("detector_mode", "replay"),
                    detection_mode=values.get("detection_mode", "advanced"),
                    allow_non_local=False,
                    spoof_ip=values.get("real_source_ip") != "1",
                    profile=values.get("profile", ""),
                    report_dir="reports",
                )
                body = form_html(values) + result_html(result)
        except Exception as exc:
            body = form_html(values) + f'<section class="card"><h2>Error</h2><pre>{html.escape(str(exc))}</pre></section></div>'
        self.respond(page(body))

    def respond(self, body: bytes) -> None:
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, fmt: str, *args) -> None:
        return


def main() -> int:
    server = ThreadingHTTPServer(("127.0.0.1", 8765), Handler)
    print("Attack Lab UI: http://127.0.0.1:8765")
    print("Target default:", DEFAULT_BASE_URL)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nStopping Attack Lab UI...")
    finally:
        server.server_close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
