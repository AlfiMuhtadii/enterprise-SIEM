#!/usr/bin/env python3
"""
Generate lightweight SVG demo screenshots for reviewer onboarding docs.
"""

from __future__ import annotations

from pathlib import Path


TEMPLATE = """<svg xmlns="http://www.w3.org/2000/svg" width="1280" height="720" viewBox="0 0 1280 720">
  <rect width="1280" height="720" fill="#0f172a"/>
  <rect x="36" y="36" width="1208" height="648" rx="28" fill="#111827" stroke="#334155"/>
  <text x="72" y="92" fill="#e5e7eb" font-family="Verdana" font-size="34" font-weight="700">{title}</text>
  <text x="72" y="130" fill="#94a3b8" font-family="Verdana" font-size="18">{subtitle}</text>
  {body}
</svg>
"""


def card(x: int, y: int, w: int, h: int, title: str, value: str, color: str) -> str:
    return f"""
  <rect x="{x}" y="{y}" width="{w}" height="{h}" rx="18" fill="#1f2937" stroke="#334155"/>
  <text x="{x+24}" y="{y+38}" fill="#94a3b8" font-family="Verdana" font-size="16">{title}</text>
  <text x="{x+24}" y="{y+88}" fill="{color}" font-family="Verdana" font-size="36" font-weight="700">{value}</text>
"""


def write(name: str, title: str, subtitle: str, body: str) -> None:
    out = Path("demo/screenshots") / name
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(TEMPLATE.format(title=title, subtitle=subtitle, body=body), encoding="utf-8")
    print(f"wrote={out}")


def main() -> int:
    write(
        "dashboard.svg",
        "SOC Dashboard",
        "Incident trend, severity distribution, MITRE overview, and recent alerts.",
        card(72, 170, 250, 130, "Open Incidents", "2", "#f97316")
        + card(350, 170, 250, 130, "Critical Alerts", "1", "#ef4444")
        + card(628, 170, 250, 130, "MTTR Target", "4h", "#22c55e")
        + card(906, 170, 250, 130, "Telemetry Lag", "12s", "#38bdf8")
        + '<polyline points="90,520 220,470 350,490 480,390 610,430 740,310 870,360 1000,280 1140,330" fill="none" stroke="#38bdf8" stroke-width="6"/>'
        + '<text x="72" y="610" fill="#cbd5e1" font-family="Verdana" font-size="20">Demo incidents: credential attack, C2 beacon, and false-positive review.</text>',
    )
    write(
        "incident-detail.svg",
        "Incident Detail",
        "Evidence chain, related alerts, MITRE mapping, notes, and workflow history.",
        '<rect x="72" y="170" width="520" height="410" rx="18" fill="#1f2937" stroke="#334155"/>'
        '<text x="100" y="220" fill="#f8fafc" font-family="Verdana" font-size="24">DEMO-INC-001</text>'
        '<text x="100" y="260" fill="#f97316" font-family="Verdana" font-size="18">Status: investigating | Severity: critical</text>'
        '<text x="100" y="315" fill="#cbd5e1" font-family="Verdana" font-size="18">Evidence: BRUTE_FORCE_IP -> SCAN_BURST -> INJECTION_INDICATOR</text>'
        '<text x="100" y="365" fill="#cbd5e1" font-family="Verdana" font-size="18">MITRE: T1110, T1595, T1190</text>'
        '<rect x="650" y="170" width="510" height="410" rx="18" fill="#1f2937" stroke="#334155"/>'
        '<circle cx="720" cy="250" r="16" fill="#ef4444"/><line x1="720" y1="266" x2="720" y2="470" stroke="#64748b" stroke-width="4"/>'
        '<circle cx="720" cy="360" r="16" fill="#f97316"/><circle cx="720" cy="470" r="16" fill="#22c55e"/>'
        '<text x="760" y="258" fill="#e5e7eb" font-family="Verdana" font-size="18">Alert generated</text>'
        '<text x="760" y="368" fill="#e5e7eb" font-family="Verdana" font-size="18">Analyst assigned</text>'
        '<text x="760" y="478" fill="#e5e7eb" font-family="Verdana" font-size="18">Investigation note added</text>',
    )
    write(
        "exports-audit.svg",
        "Exports and Audit Trail",
        "JSONL, SIEM, STIX-like exports with audited sensitive actions.",
        card(72, 170, 300, 130, "Export Formats", "3", "#38bdf8")
        + card(408, 170, 300, 130, "Audit Events", "4", "#a78bfa")
        + card(744, 170, 300, 130, "RBAC Roles", "3", "#22c55e")
        + '<rect x="72" y="350" width="1080" height="220" rx="18" fill="#1f2937" stroke="#334155"/>'
        + '<text x="100" y="405" fill="#e5e7eb" font-family="Verdana" font-size="20">soc-admin@example.com export.download jsonl</text>'
        + '<text x="100" y="455" fill="#e5e7eb" font-family="Verdana" font-size="20">soc-analyst@example.com incident.status DEMO-INC-001</text>'
        + '<text x="100" y="505" fill="#e5e7eb" font-family="Verdana" font-size="20">soc-admin@example.com incident.false_positive DEMO-INC-003</text>',
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

