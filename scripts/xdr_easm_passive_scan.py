"""EASM Passive Posture Scanner — xdr_easm_passive_scan.py

Advisory-only. No active exploitation, no brute-forcing, no autonomous remediation.
All network calls are injectable via _fn parameters for testability.

Usage:
    python scripts/xdr_easm_passive_scan.py --url https://example.com \\
        --asset-id 1 --tenant-id tenant-001 --output /tmp/report.json
"""
from __future__ import annotations

import argparse
import ipaddress
import json
import socket
import ssl
import sys
import urllib.request
import urllib.error
import urllib.parse
from datetime import datetime, timezone
from pathlib import Path

# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------

REQUEST_TIMEOUT_SECONDS = 5
SCAN_TIMEOUT_SECONDS = 30
MAX_REDIRECTS = 5
MAX_BODY_BYTES = 1_048_576
MAX_ROBOTS_BYTES = 65_536
MAX_SITEMAP_BYTES = 65_536

PASSIVE_CHECKS = [
    "dns",
    "tls",
    "http",
    "security_headers",
    "cookies",
    "robots_txt",
    "sitemap_xml",
]

SECURITY_HEADERS = [
    "Strict-Transport-Security",
    "Content-Security-Policy",
    "X-Frame-Options",
    "X-Content-Type-Options",
    "Referrer-Policy",
    "Permissions-Policy",
]

COOKIE_FLAGS = ["Secure", "HttpOnly", "SameSite"]

INTERNAL_TLDS = {".local", ".internal", ".lan", ".corp", ".test", ".example", ".invalid"}


# ---------------------------------------------------------------------------
# URL + IP helpers
# ---------------------------------------------------------------------------


def validate_url(url: str) -> dict:
    """Validate and normalise a URL. Returns dict with 'valid' key."""
    if not url or not url.strip():
        return {"valid": False, "error": "URL must not be empty."}

    parsed = urllib.parse.urlparse(url)

    if not parsed.hostname:
        return {"valid": False, "error": "URL could not be parsed or has no hostname."}

    scheme = parsed.scheme.lower()
    if scheme not in ("http", "https"):
        return {
            "valid": False,
            "error": f"Unsupported scheme '{scheme}'. Only http and https are allowed.",
        }

    hostname = parsed.hostname.lower()

    if is_internal_hostname(hostname):
        return {
            "valid": False,
            "error": f"Host '{hostname}' is a private or internal address and cannot be scanned.",
        }

    # Check if hostname is an IP
    try:
        ip = ipaddress.ip_address(hostname)
        if is_private_ip(str(ip)):
            return {
                "valid": False,
                "error": f"IP address '{hostname}' is private/reserved and cannot be scanned.",
            }
    except ValueError:
        pass

    # Reconstruct normalised URL
    normalised = scheme + "://" + hostname
    if parsed.port:
        normalised += ":" + str(parsed.port)
    if parsed.path:
        normalised += parsed.path
    if parsed.query:
        normalised += "?" + parsed.query

    return {
        "valid": True,
        "hostname": hostname,
        "scheme": scheme,
        "normalized_url": normalised,
    }


def is_private_ip(ip: str) -> bool:
    """Return True if the IP address is private or reserved."""
    try:
        addr = ipaddress.ip_address(ip)
        return addr.is_private or addr.is_loopback or addr.is_reserved or addr.is_link_local
    except ValueError:
        return False


def is_internal_hostname(hostname: str) -> bool:
    """Return True if the hostname looks internal (localhost, .local, .internal, etc.)."""
    lower = hostname.lower().strip()
    if lower in ("localhost", "localhost.localdomain"):
        return True
    for tld in INTERNAL_TLDS:
        if lower.endswith(tld):
            return True
    return False


# ---------------------------------------------------------------------------
# Check: DNS
# ---------------------------------------------------------------------------


def _default_resolve(hostname: str):
    """Default DNS resolver using socket.getaddrinfo."""
    return socket.getaddrinfo(hostname, None)


def check_dns(hostname: str, _resolve_fn=None) -> dict:
    """Resolve hostname. Returns check result dict with findings."""
    resolve = _resolve_fn or _default_resolve
    findings = []

    try:
        result = resolve(hostname)
        ip = result[0][4][0] if result else None

        if ip and is_private_ip(ip):
            findings.append({
                "key": "dns_resolves_to_private_ip",
                "category": "dns",
                "severity": "medium",
                "confidence": "high",
                "title": "Hostname resolves to private IP",
                "description": f"Hostname {hostname} resolved to private IP {ip}.",
                "evidence": {"ip": ip},
                "recommendation": "Verify DNS configuration.",
            })

        return {"check": "dns", "resolved": True, "ip": ip, "error": None, "findings": findings}

    except (socket.gaierror, OSError) as exc:
        findings.append({
            "key": "dns_resolution_failed",
            "category": "dns",
            "severity": "medium",
            "confidence": "high",
            "title": "DNS resolution failed",
            "description": f"Could not resolve hostname {hostname}: {exc}",
            "evidence": {"hostname": hostname, "error": str(exc)},
            "recommendation": "Verify the hostname exists and DNS is properly configured.",
        })
        return {"check": "dns", "resolved": False, "ip": None, "error": str(exc), "findings": findings}


# ---------------------------------------------------------------------------
# Check: TLS
# ---------------------------------------------------------------------------


def _default_tls_connect(hostname: str):
    """Default TLS connect using ssl + socket."""
    ctx = ssl.create_default_context()
    conn = ctx.wrap_socket(socket.create_connection((hostname, 443), timeout=REQUEST_TIMEOUT_SECONDS), server_hostname=hostname)
    cert = conn.getpeercert()
    conn.close()
    return cert


def check_tls(hostname: str, _connect_fn=None) -> dict:
    """Check TLS certificate presence and expiry."""
    connect = _connect_fn or _default_tls_connect
    findings = []

    try:
        cert = connect(hostname)
        not_after_str = cert.get("notAfter", "")
        # e.g. "Jun 25 12:00:00 2025 GMT"
        try:
            import time as _time
            not_after_ts = _time.mktime(_time.strptime(not_after_str, "%b %d %H:%M:%S %Y %Z"))
            days_left = int((not_after_ts - _time.time()) / 86400)
        except Exception:
            days_left = None

        issuer = dict(x[0] for x in cert.get("issuer", []))
        issuer_cn = issuer.get("commonName", "unknown")

        if days_left is not None and days_left <= 14:
            findings.append({
                "key": "cert_expiry_critical",
                "category": "tls",
                "severity": "high",
                "confidence": "high",
                "title": "Certificate expiring critically soon",
                "description": f"Certificate expires in {days_left} days.",
                "evidence": {"days_until_expiry": days_left},
                "recommendation": "Renew the TLS certificate immediately.",
            })
        elif days_left is not None and days_left <= 30:
            findings.append({
                "key": "cert_expiry_warning",
                "category": "tls",
                "severity": "medium",
                "confidence": "high",
                "title": "Certificate expiry warning",
                "description": f"Certificate expires in {days_left} days.",
                "evidence": {"days_until_expiry": days_left},
                "recommendation": "Renew the TLS certificate soon.",
            })
        elif days_left is not None and days_left <= 90:
            findings.append({
                "key": "cert_expiry_soon",
                "category": "tls",
                "severity": "low",
                "confidence": "high",
                "title": "Certificate expiry notice",
                "description": f"Certificate expires in {days_left} days.",
                "evidence": {"days_until_expiry": days_left},
                "recommendation": "Plan TLS certificate renewal.",
            })

        return {
            "check": "tls",
            "present": True,
            "days_until_expiry": days_left,
            "issuer": issuer_cn,
            "findings": findings,
        }

    except (ssl.SSLError, OSError, ConnectionRefusedError) as exc:
        findings.append({
            "key": "tls_not_available",
            "category": "tls",
            "severity": "high",
            "confidence": "high",
            "title": "TLS not available",
            "description": f"Could not establish TLS connection to {hostname}: {exc}",
            "evidence": {"error": str(exc)},
            "recommendation": "Ensure HTTPS/TLS is properly configured.",
        })
        return {"check": "tls", "present": False, "days_until_expiry": None, "issuer": None, "findings": findings}


# ---------------------------------------------------------------------------
# Check: HTTP
# ---------------------------------------------------------------------------


def _default_http_get(url: str) -> dict:
    """Perform HTTP GET with redirect tracking."""
    opener = urllib.request.build_opener(urllib.request.HTTPRedirectHandler())
    redirects = [0]
    original_http_error = None

    class CountingRedirectHandler(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, hdrs, newurl):
            redirects[0] += 1
            return super().redirect_request(req, fp, code, msg, hdrs, newurl)

    opener2 = urllib.request.build_opener(CountingRedirectHandler())
    req = urllib.request.Request(url, headers={"User-Agent": "xdr-easm-passive/1.0"})

    resp = opener2.open(req, timeout=REQUEST_TIMEOUT_SECONDS)
    body = resp.read(MAX_BODY_BYTES)
    headers = dict(resp.headers)
    final_url = resp.geturl()
    status_code = resp.status

    return {
        "status_code": status_code,
        "final_url": final_url,
        "headers": headers,
        "redirect_count": redirects[0],
        "body_length": len(body),
    }


def check_http(url: str, _get_fn=None) -> dict:
    """Perform HTTP check: reachability, redirects, headers."""
    get_fn = _get_fn or _default_http_get
    findings = []

    try:
        result = get_fn(url)

        if result.get("redirect_count", 0) > MAX_REDIRECTS:
            findings.append({
                "key": "excessive_redirects",
                "category": "redirect",
                "severity": "low",
                "confidence": "high",
                "title": "Excessive redirects",
                "description": f"Site has {result['redirect_count']} redirects (max {MAX_REDIRECTS}).",
                "evidence": {"redirect_count": result["redirect_count"]},
                "recommendation": "Review and simplify redirect chain.",
            })

        return {
            "check": "http",
            "status_code": result["status_code"],
            "final_url": result["final_url"],
            "headers": result["headers"],
            "redirect_count": result["redirect_count"],
            "findings": findings,
        }

    except (urllib.error.URLError, OSError, Exception) as exc:
        findings.append({
            "key": "site_unreachable",
            "category": "availability",
            "severity": "low",
            "confidence": "medium",
            "title": "Site unreachable",
            "description": f"Could not reach {url}: {exc}",
            "evidence": {"error": str(exc)},
            "recommendation": "Verify the site is online and accessible.",
        })
        return {
            "check": "http",
            "status_code": None,
            "final_url": url,
            "headers": {},
            "redirect_count": 0,
            "findings": findings,
        }


# ---------------------------------------------------------------------------
# Check: Security Headers (pure logic — no network call)
# ---------------------------------------------------------------------------


def check_security_headers(headers: dict) -> dict:
    """Check for missing security headers. headers is a case-insensitive-like dict."""
    findings = []

    # Normalise header keys to lowercase for comparison
    lower_headers = {k.lower(): v for k, v in headers.items()}

    checks_map = [
        ("strict-transport-security", "missing_hsts", "medium", "Missing HSTS header",
         "Strict-Transport-Security header is absent. Browsers may connect over HTTP."),
        ("content-security-policy", "missing_csp", "medium", "Missing CSP header",
         "Content-Security-Policy header is absent. XSS attacks may be easier."),
        ("x-frame-options", "missing_x_frame_options", "low", "Missing X-Frame-Options header",
         "X-Frame-Options header is absent. Clickjacking may be possible."),
        ("x-content-type-options", "missing_x_content_type_options", "low", "Missing X-Content-Type-Options header",
         "X-Content-Type-Options header is absent. MIME-type sniffing may occur."),
        ("referrer-policy", "missing_referrer_policy", "low", "Missing Referrer-Policy header",
         "Referrer-Policy header is absent. Full URLs may be leaked to third parties."),
        ("permissions-policy", "missing_permissions_policy", "info", "Missing Permissions-Policy header",
         "Permissions-Policy header is absent. Browser feature access is unrestricted."),
    ]

    for header_lower, key, severity, title, description in checks_map:
        if header_lower not in lower_headers:
            findings.append({
                "key": key,
                "category": "security_header",
                "severity": severity,
                "confidence": "high",
                "title": title,
                "description": description,
                "evidence": None,
                "recommendation": f"Add the {header_lower.replace('-', ' ').title()} header to HTTP responses.",
            })

    return {"check": "security_headers", "findings": findings}


# ---------------------------------------------------------------------------
# Check: Cookies (pure logic — no network call)
# ---------------------------------------------------------------------------


def check_cookies(headers: dict) -> dict:
    """Check Set-Cookie headers for missing security flags."""
    findings = []

    lower_headers = {k.lower(): v for k, v in headers.items()}
    set_cookie = lower_headers.get("set-cookie", "")

    if not set_cookie:
        return {"check": "cookies", "findings": []}

    cookie_lower = set_cookie.lower()

    if "secure" not in cookie_lower:
        findings.append({
            "key": "cookie_missing_secure",
            "category": "cookie",
            "severity": "medium",
            "confidence": "medium",
            "title": "Cookie missing Secure flag",
            "description": "A cookie was found without the Secure attribute. It may be sent over HTTP.",
            "evidence": None,
            "recommendation": "Add the Secure flag to all cookies.",
        })

    if "httponly" not in cookie_lower:
        findings.append({
            "key": "cookie_missing_httponly",
            "category": "cookie",
            "severity": "medium",
            "confidence": "medium",
            "title": "Cookie missing HttpOnly flag",
            "description": "A cookie was found without the HttpOnly attribute. It is accessible via JavaScript.",
            "evidence": None,
            "recommendation": "Add the HttpOnly flag to session cookies.",
        })

    if "samesite" not in cookie_lower:
        findings.append({
            "key": "cookie_missing_samesite",
            "category": "cookie",
            "severity": "low",
            "confidence": "medium",
            "title": "Cookie missing SameSite attribute",
            "description": "A cookie was found without the SameSite attribute. CSRF risk may be elevated.",
            "evidence": None,
            "recommendation": "Add SameSite=Lax or SameSite=Strict to cookies.",
        })

    return {"check": "cookies", "findings": findings}


# ---------------------------------------------------------------------------
# Check: robots.txt
# ---------------------------------------------------------------------------


def _default_robots_get(url: str) -> tuple[int, str]:
    """Fetch robots.txt. Returns (status_code, body_text)."""
    req = urllib.request.Request(url, headers={"User-Agent": "xdr-easm-passive/1.0"})
    try:
        resp = urllib.request.urlopen(req, timeout=REQUEST_TIMEOUT_SECONDS)
        body = resp.read(MAX_ROBOTS_BYTES + 1)
        return resp.status, body.decode("utf-8", errors="replace")
    except urllib.error.HTTPError as e:
        return e.code, ""
    except Exception:
        return 0, ""


def check_robots_txt(base_url: str, _get_fn=None) -> dict:
    """Fetch and analyse robots.txt."""
    get_fn = _get_fn or _default_robots_get
    findings = []

    parsed = urllib.parse.urlparse(base_url)
    robots_url = f"{parsed.scheme}://{parsed.hostname}/robots.txt"

    status, body = get_fn(robots_url)

    if status == 0 or status >= 400:
        return {"check": "robots_txt", "present": False, "findings": findings}

    truncated = False
    if len(body) > MAX_ROBOTS_BYTES:
        body = body[:MAX_ROBOTS_BYTES]
        truncated = True
        findings.append({
            "key": "robots_txt_oversized",
            "category": "exposure",
            "severity": "info",
            "confidence": "high",
            "title": "robots.txt exceeds size limit",
            "description": f"robots.txt is larger than {MAX_ROBOTS_BYTES} bytes; content was truncated.",
            "evidence": None,
            "recommendation": "Review robots.txt for unnecessary content.",
        })

    admin_hints = ["admin", "backup", "config", "install", "setup", ".env", "phpinfo"]
    for line in body.splitlines():
        line_lower = line.strip().lower()
        if line_lower.startswith("disallow:"):
            path_hint = line_lower.replace("disallow:", "").strip()
            for hint in admin_hints:
                if hint in path_hint:
                    findings.append({
                        "key": "robots_admin_path_hint",
                        "category": "exposure",
                        "severity": "low",
                        "confidence": "medium",
                        "title": "Sensitive path hint in robots.txt",
                        "description": f"robots.txt Disallow contains a potentially sensitive path: {path_hint}",
                        "evidence": {"path": path_hint},
                        "recommendation": "Review whether this path should be exposed in robots.txt.",
                    })
                    break

    return {"check": "robots_txt", "present": True, "truncated": truncated, "findings": findings}


# ---------------------------------------------------------------------------
# Check: sitemap.xml
# ---------------------------------------------------------------------------


def _default_sitemap_get(url: str) -> tuple[int, int]:
    """Fetch sitemap.xml. Returns (status_code, content_length)."""
    req = urllib.request.Request(url, headers={"User-Agent": "xdr-easm-passive/1.0"})
    try:
        resp = urllib.request.urlopen(req, timeout=REQUEST_TIMEOUT_SECONDS)
        body = resp.read(MAX_SITEMAP_BYTES + 1)
        return resp.status, len(body)
    except urllib.error.HTTPError as e:
        return e.code, 0
    except Exception:
        return 0, 0


def check_sitemap_xml(base_url: str, _get_fn=None) -> dict:
    """Check sitemap.xml existence and size."""
    get_fn = _get_fn or _default_sitemap_get
    findings = []

    parsed = urllib.parse.urlparse(base_url)
    sitemap_url = f"{parsed.scheme}://{parsed.hostname}/sitemap.xml"

    status, size = get_fn(sitemap_url)

    present = 200 <= status < 300

    if present and size > MAX_SITEMAP_BYTES:
        findings.append({
            "key": "sitemap_oversized",
            "category": "exposure",
            "severity": "info",
            "confidence": "high",
            "title": "sitemap.xml exceeds size limit",
            "description": f"sitemap.xml is larger than {MAX_SITEMAP_BYTES} bytes.",
            "evidence": {"size": size},
            "recommendation": "Review sitemap.xml size and content.",
        })

    return {"check": "sitemap_xml", "present": present, "size": size, "findings": findings}


# ---------------------------------------------------------------------------
# Orchestrator
# ---------------------------------------------------------------------------


def run_passive_scan(
    url: str,
    tenant_id: str,
    asset_id: str,
    _get_fn=None,
    _resolve_fn=None,
    _connect_fn=None,
    _robots_fn=None,
    _sitemap_fn=None,
) -> dict:
    """
    Run all passive checks against the given URL.
    Returns {'checks': [...], 'all_findings': [...], 'started_at': ..., 'finished_at': ..., 'error': None}.
    """
    started_at = datetime.now(tz=timezone.utc).isoformat()

    v = validate_url(url)
    if not v["valid"]:
        return {
            "checks": [],
            "all_findings": [],
            "started_at": started_at,
            "finished_at": datetime.now(tz=timezone.utc).isoformat(),
            "error": v["error"],
        }

    hostname = v["hostname"]
    checks = []
    all_findings = []

    # DNS
    dns_result = check_dns(hostname, _resolve_fn=_resolve_fn)
    checks.append(dns_result)
    all_findings.extend(dns_result.get("findings", []))

    # TLS
    tls_result = check_tls(hostname, _connect_fn=_connect_fn)
    checks.append(tls_result)
    all_findings.extend(tls_result.get("findings", []))

    # HTTP
    http_result = check_http(url, _get_fn=_get_fn)
    checks.append(http_result)
    all_findings.extend(http_result.get("findings", []))

    # Security headers — pure logic from HTTP result headers
    headers = http_result.get("headers", {})
    sec_headers_result = check_security_headers(headers)
    checks.append(sec_headers_result)
    all_findings.extend(sec_headers_result.get("findings", []))

    # Cookies — pure logic from HTTP result headers
    cookies_result = check_cookies(headers)
    checks.append(cookies_result)
    all_findings.extend(cookies_result.get("findings", []))

    # robots.txt
    robots_result = check_robots_txt(url, _get_fn=_robots_fn)
    checks.append(robots_result)
    all_findings.extend(robots_result.get("findings", []))

    # sitemap.xml
    sitemap_result = check_sitemap_xml(url, _get_fn=_sitemap_fn)
    checks.append(sitemap_result)
    all_findings.extend(sitemap_result.get("findings", []))

    return {
        "checks": checks,
        "all_findings": all_findings,
        "started_at": started_at,
        "finished_at": datetime.now(tz=timezone.utc).isoformat(),
        "error": None,
    }


# ---------------------------------------------------------------------------
# Report builder
# ---------------------------------------------------------------------------


def build_report(
    asset_url: str,
    tenant_id: str,
    asset_id: str,
    scan_policy: str,
    started_at: str,
    ended_at: str,
    checks: list,
    all_findings: list,
) -> dict:
    """Build a JSON-serialisable report dict."""
    parsed = urllib.parse.urlparse(asset_url)
    hostname = parsed.hostname or asset_url

    total = len(all_findings)
    by_severity: dict[str, int] = {"info": 0, "low": 0, "medium": 0, "high": 0}
    by_category: dict[str, int] = {}

    for f in all_findings:
        sev = f.get("severity", "info")
        cat = f.get("category", "exposure")
        by_severity[sev] = by_severity.get(sev, 0) + 1
        by_category[cat] = by_category.get(cat, 0) + 1

    if by_severity.get("high", 0) > 0 or by_severity.get("medium", 0) > 0:
        overall_status = "FINDINGS"
    elif total > 0:
        overall_status = "WARN"
    else:
        overall_status = "PASS"

    return {
        "asset": {
            "url": asset_url,
            "hostname": hostname,
            "tenant_id": tenant_id,
            "asset_id": asset_id,
        },
        "scan_policy": scan_policy,
        "started_at": started_at,
        "finished_at": ended_at,
        "checks": checks,
        "findings": all_findings,
        "summary": {
            "total": total,
            "by_severity": by_severity,
            "by_category": by_category,
        },
        "overall_status": overall_status,
    }


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------


def main(argv=None):
    parser = argparse.ArgumentParser(description="EASM Passive Posture Scanner (advisory-only)")
    parser.add_argument("--url", required=True, help="Target URL to scan")
    parser.add_argument("--tenant-id", default="default", help="Tenant ID")
    parser.add_argument("--asset-id", default="0", help="Asset ID")
    parser.add_argument("--output", help="Path to write JSON report")
    parser.add_argument("--dry-run", action="store_true", help="Validate without scanning")
    parser.add_argument("--profile", default="local", choices=["local", "staging", "production"])

    args = parser.parse_args(argv)

    v = validate_url(args.url)
    if not v["valid"]:
        print(f"[EASM] ERROR: {v['error']}", file=sys.stderr)
        sys.exit(1)

    if args.dry_run:
        print(f"[EASM] DRY-RUN: would scan {v['normalized_url']} (tenant={args.tenant_id}, policy=passive)")
        sys.exit(0)

    print(f"[EASM] Scanning {v['normalized_url']} (tenant={args.tenant_id}, policy=passive)")

    result = run_passive_scan(
        v["normalized_url"],
        tenant_id=args.tenant_id,
        asset_id=args.asset_id,
    )

    report = build_report(
        asset_url=v["normalized_url"],
        tenant_id=args.tenant_id,
        asset_id=args.asset_id,
        scan_policy="passive",
        started_at=result["started_at"],
        ended_at=result["finished_at"],
        checks=result["checks"],
        all_findings=result["all_findings"],
    )

    print(f"[EASM] Completed. Findings: {report['summary']['total']} | Status: {report['overall_status']}")

    if args.output:
        Path(args.output).write_text(json.dumps(report, indent=2), encoding="utf-8")
        print(f"[EASM] Report written to {args.output}")
    else:
        print(json.dumps(report, indent=2))

    sys.exit(0)


if __name__ == "__main__":
    main()
