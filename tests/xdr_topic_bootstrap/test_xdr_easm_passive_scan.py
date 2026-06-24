"""Tests for BACKLOG-EASM-030: EASM Passive Posture Monitoring Scanner.

Covers:
- validate_url: valid https, valid http, malformed, no scheme, ftp, empty, private IP
- is_private_ip: loopback, RFC-1918 ranges, public IP false
- is_internal_hostname: localhost, .local, .internal, example.com false
- check_security_headers (pure): each header, all present, case-insensitive headers
- check_cookies (pure): missing Secure/HttpOnly/SameSite, no Set-Cookie
- check_dns: resolves OK, fails, private IP result
- check_tls: cert OK, expiring soon, no TLS
- check_http: 200 OK, unreachable, too many redirects
- check_robots_txt: exists, admin path hint, oversized
- run_passive_scan: private IP URL rejected, normal run, all_findings populated
- build_report: keys present, summary counts, overall_status logic
- main() dry-run: exit 0, no output file
"""
from __future__ import annotations

import json
import socket
import sys
import tempfile
import unittest
from pathlib import Path

_SCRIPTS = Path(__file__).parent.parent.parent / "scripts"
sys.path.insert(0, str(_SCRIPTS))

import xdr_easm_passive_scan as easm  # noqa: E402


# ---------------------------------------------------------------------------
# validate_url
# ---------------------------------------------------------------------------


class TestValidateUrl(unittest.TestCase):
    def test_valid_https_url(self):
        r = easm.validate_url("https://example.com/path")
        self.assertTrue(r["valid"])
        self.assertEqual(r["hostname"], "example.com")
        self.assertEqual(r["scheme"], "https")

    def test_valid_http_url(self):
        r = easm.validate_url("http://example.com")
        self.assertTrue(r["valid"])
        self.assertEqual(r["scheme"], "http")

    def test_malformed_url_rejected(self):
        r = easm.validate_url("not a url ::: !!!")
        self.assertFalse(r["valid"])
        self.assertIn("error", r)

    def test_url_without_scheme_rejected(self):
        r = easm.validate_url("example.com/path")
        self.assertFalse(r["valid"])

    def test_ftp_scheme_rejected(self):
        r = easm.validate_url("ftp://example.com")
        self.assertFalse(r["valid"])
        self.assertIn("ftp", r["error"])

    def test_empty_url_rejected(self):
        r = easm.validate_url("")
        self.assertFalse(r["valid"])
        self.assertIn("error", r)

    def test_private_ip_in_url_rejected(self):
        r = easm.validate_url("https://192.168.1.1/admin")
        self.assertFalse(r["valid"])

    def test_localhost_in_url_rejected(self):
        r = easm.validate_url("https://localhost/")
        self.assertFalse(r["valid"])

    def test_hostname_normalised_to_lowercase(self):
        r = easm.validate_url("https://EXAMPLE.COM/")
        self.assertTrue(r["valid"])
        self.assertEqual(r["hostname"], "example.com")


# ---------------------------------------------------------------------------
# is_private_ip
# ---------------------------------------------------------------------------


class TestIsPrivateIp(unittest.TestCase):
    def test_loopback_127(self):
        self.assertTrue(easm.is_private_ip("127.0.0.1"))

    def test_rfc1918_10_x(self):
        self.assertTrue(easm.is_private_ip("10.0.0.1"))

    def test_rfc1918_192_168_x(self):
        self.assertTrue(easm.is_private_ip("192.168.1.100"))

    def test_rfc1918_172_16_x(self):
        self.assertTrue(easm.is_private_ip("172.16.0.1"))

    def test_rfc1918_172_31_x(self):
        self.assertTrue(easm.is_private_ip("172.31.255.254"))

    def test_public_ip_returns_false(self):
        self.assertFalse(easm.is_private_ip("93.184.216.34"))  # example.com

    def test_invalid_string_returns_false(self):
        self.assertFalse(easm.is_private_ip("not_an_ip"))


# ---------------------------------------------------------------------------
# is_internal_hostname
# ---------------------------------------------------------------------------


class TestIsInternalHostname(unittest.TestCase):
    def test_localhost(self):
        self.assertTrue(easm.is_internal_hostname("localhost"))

    def test_localhost_localdomain(self):
        self.assertTrue(easm.is_internal_hostname("localhost.localdomain"))

    def test_dot_local(self):
        self.assertTrue(easm.is_internal_hostname("mybox.local"))

    def test_dot_internal(self):
        self.assertTrue(easm.is_internal_hostname("svc.internal"))

    def test_dot_lan(self):
        self.assertTrue(easm.is_internal_hostname("router.lan"))

    def test_example_com_returns_false(self):
        self.assertFalse(easm.is_internal_hostname("example.com"))

    def test_sub_domain_external_returns_false(self):
        self.assertFalse(easm.is_internal_hostname("sub.example.co.uk"))


# ---------------------------------------------------------------------------
# check_security_headers (pure)
# ---------------------------------------------------------------------------


class TestCheckSecurityHeaders(unittest.TestCase):
    def test_missing_hsts_produces_medium_finding(self):
        headers = {"Content-Type": "text/html"}
        result = easm.check_security_headers(headers)
        keys = [f["key"] for f in result["findings"]]
        self.assertIn("missing_hsts", keys)
        hsts = next(f for f in result["findings"] if f["key"] == "missing_hsts")
        self.assertEqual(hsts["severity"], "medium")

    def test_missing_csp_produces_medium_finding(self):
        headers = {}
        result = easm.check_security_headers(headers)
        keys = [f["key"] for f in result["findings"]]
        self.assertIn("missing_csp", keys)

    def test_missing_x_frame_options_produces_low_finding(self):
        headers = {}
        result = easm.check_security_headers(headers)
        keys = [f["key"] for f in result["findings"]]
        self.assertIn("missing_x_frame_options", keys)
        xfo = next(f for f in result["findings"] if f["key"] == "missing_x_frame_options")
        self.assertEqual(xfo["severity"], "low")

    def test_all_headers_present_returns_empty_findings(self):
        headers = {
            "Strict-Transport-Security": "max-age=31536000",
            "Content-Security-Policy": "default-src 'self'",
            "X-Frame-Options": "DENY",
            "X-Content-Type-Options": "nosniff",
            "Referrer-Policy": "no-referrer",
            "Permissions-Policy": "camera=()",
        }
        result = easm.check_security_headers(headers)
        self.assertEqual(result["findings"], [])

    def test_check_is_case_insensitive(self):
        # Headers with different capitalisation should still be matched
        headers = {
            "strict-transport-security": "max-age=31536000",
            "content-security-policy": "default-src 'self'",
            "x-frame-options": "DENY",
            "x-content-type-options": "nosniff",
            "referrer-policy": "no-referrer",
            "permissions-policy": "camera=()",
        }
        result = easm.check_security_headers(headers)
        self.assertEqual(result["findings"], [])

    def test_missing_permissions_policy_is_info(self):
        headers = {
            "Strict-Transport-Security": "max-age=31536000",
            "Content-Security-Policy": "default-src 'self'",
            "X-Frame-Options": "DENY",
            "X-Content-Type-Options": "nosniff",
            "Referrer-Policy": "no-referrer",
        }
        result = easm.check_security_headers(headers)
        keys = [f["key"] for f in result["findings"]]
        self.assertIn("missing_permissions_policy", keys)
        pp = next(f for f in result["findings"] if f["key"] == "missing_permissions_policy")
        self.assertEqual(pp["severity"], "info")


# ---------------------------------------------------------------------------
# check_cookies (pure)
# ---------------------------------------------------------------------------


class TestCheckCookies(unittest.TestCase):
    def test_no_set_cookie_header_returns_empty(self):
        result = easm.check_cookies({"Content-Type": "text/html"})
        self.assertEqual(result["findings"], [])

    def test_cookie_missing_secure_flag(self):
        headers = {"set-cookie": "session=abc; HttpOnly; SameSite=Lax"}
        result = easm.check_cookies(headers)
        keys = [f["key"] for f in result["findings"]]
        self.assertIn("cookie_missing_secure", keys)
        f = next(x for x in result["findings"] if x["key"] == "cookie_missing_secure")
        self.assertEqual(f["severity"], "medium")

    def test_cookie_missing_httponly_flag(self):
        headers = {"set-cookie": "session=abc; Secure; SameSite=Lax"}
        result = easm.check_cookies(headers)
        keys = [f["key"] for f in result["findings"]]
        self.assertIn("cookie_missing_httponly", keys)

    def test_cookie_missing_samesite_is_low(self):
        headers = {"set-cookie": "session=abc; Secure; HttpOnly"}
        result = easm.check_cookies(headers)
        keys = [f["key"] for f in result["findings"]]
        self.assertIn("cookie_missing_samesite", keys)
        f = next(x for x in result["findings"] if x["key"] == "cookie_missing_samesite")
        self.assertEqual(f["severity"], "low")

    def test_all_flags_present_returns_empty(self):
        headers = {"set-cookie": "session=abc; Secure; HttpOnly; SameSite=Strict"}
        result = easm.check_cookies(headers)
        self.assertEqual(result["findings"], [])


# ---------------------------------------------------------------------------
# check_dns
# ---------------------------------------------------------------------------


class TestCheckDns(unittest.TestCase):
    def test_resolves_ok_with_fake(self):
        def fake_resolve(hostname):
            return [(socket.AF_INET, socket.SOCK_STREAM, 0, "", ("93.184.216.34", 0))]

        result = easm.check_dns("example.com", _resolve_fn=fake_resolve)
        self.assertTrue(result["resolved"])
        self.assertEqual(result["ip"], "93.184.216.34")
        self.assertEqual(result["findings"], [])

    def test_resolution_failure_produces_finding(self):
        def fake_fail(hostname):
            raise socket.gaierror("NXDOMAIN")

        result = easm.check_dns("nonexistent.example.invalid", _resolve_fn=fake_fail)
        self.assertFalse(result["resolved"])
        keys = [f["key"] for f in result["findings"]]
        self.assertIn("dns_resolution_failed", keys)

    def test_private_ip_in_dns_result_produces_finding(self):
        def fake_private(hostname):
            return [(socket.AF_INET, socket.SOCK_STREAM, 0, "", ("10.0.0.1", 0))]

        result = easm.check_dns("internal.example.com", _resolve_fn=fake_private)
        # Should still be resolved = True but have a finding
        self.assertTrue(result["resolved"])
        keys = [f["key"] for f in result["findings"]]
        self.assertIn("dns_resolves_to_private_ip", keys)


# ---------------------------------------------------------------------------
# check_tls
# ---------------------------------------------------------------------------


class TestCheckTls(unittest.TestCase):
    def _make_cert(self, days_from_now: int) -> dict:
        import time
        ts = time.time() + days_from_now * 86400
        t = time.gmtime(ts)
        not_after = time.strftime("%b %d %H:%M:%S %Y GMT", t)
        return {
            "notAfter": not_after,
            "issuer": [(("commonName", "Test CA"),)],
        }

    def test_cert_ok_returns_no_findings(self):
        def fake_connect(hostname):
            return self._make_cert(180)

        result = easm.check_tls("example.com", _connect_fn=fake_connect)
        self.assertTrue(result["present"])
        self.assertEqual(result["findings"], [])

    def test_cert_expiring_soon_produces_low_finding(self):
        def fake_connect(hostname):
            return self._make_cert(45)

        result = easm.check_tls("example.com", _connect_fn=fake_connect)
        keys = [f["key"] for f in result["findings"]]
        self.assertIn("cert_expiry_soon", keys)
        f = next(x for x in result["findings"] if x["key"] == "cert_expiry_soon")
        self.assertEqual(f["severity"], "low")

    def test_cert_expiry_warning_produces_medium_finding(self):
        def fake_connect(hostname):
            return self._make_cert(20)

        result = easm.check_tls("example.com", _connect_fn=fake_connect)
        keys = [f["key"] for f in result["findings"]]
        self.assertIn("cert_expiry_warning", keys)
        f = next(x for x in result["findings"] if x["key"] == "cert_expiry_warning")
        self.assertEqual(f["severity"], "medium")

    def test_cert_critical_expiry_produces_high_finding(self):
        def fake_connect(hostname):
            return self._make_cert(5)

        result = easm.check_tls("example.com", _connect_fn=fake_connect)
        keys = [f["key"] for f in result["findings"]]
        self.assertIn("cert_expiry_critical", keys)
        f = next(x for x in result["findings"] if x["key"] == "cert_expiry_critical")
        self.assertEqual(f["severity"], "high")

    def test_tls_unavailable_produces_high_finding(self):
        def fake_fail(hostname):
            raise ConnectionRefusedError("Connection refused")

        result = easm.check_tls("example.com", _connect_fn=fake_fail)
        self.assertFalse(result["present"])
        keys = [f["key"] for f in result["findings"]]
        self.assertIn("tls_not_available", keys)
        f = next(x for x in result["findings"] if x["key"] == "tls_not_available")
        self.assertEqual(f["severity"], "high")


# ---------------------------------------------------------------------------
# check_http
# ---------------------------------------------------------------------------


class TestCheckHttp(unittest.TestCase):
    def test_200_ok_returns_no_findings(self):
        def fake_get(url):
            return {
                "status_code": 200,
                "final_url": url,
                "headers": {"Content-Type": "text/html"},
                "redirect_count": 0,
                "body_length": 1024,
            }

        result = easm.check_http("https://example.com", _get_fn=fake_get)
        self.assertEqual(result["status_code"], 200)
        self.assertEqual(result["findings"], [])

    def test_unreachable_produces_site_unreachable_finding(self):
        import urllib.error

        def fake_fail(url):
            raise urllib.error.URLError("Connection refused")

        result = easm.check_http("https://example.com", _get_fn=fake_fail)
        keys = [f["key"] for f in result["findings"]]
        self.assertIn("site_unreachable", keys)

    def test_excessive_redirects_produces_finding(self):
        def fake_redirects(url):
            return {
                "status_code": 200,
                "final_url": url + "/final",
                "headers": {},
                "redirect_count": easm.MAX_REDIRECTS + 1,
                "body_length": 0,
            }

        result = easm.check_http("https://example.com", _get_fn=fake_redirects)
        keys = [f["key"] for f in result["findings"]]
        self.assertIn("excessive_redirects", keys)


# ---------------------------------------------------------------------------
# check_robots_txt
# ---------------------------------------------------------------------------


class TestCheckRobotsTxt(unittest.TestCase):
    def test_normal_robots_txt_present_no_findings(self):
        def fake_get(url):
            return (200, "User-agent: *\nDisallow: /\n")

        result = easm.check_robots_txt("https://example.com", _get_fn=fake_get)
        self.assertTrue(result["present"])
        self.assertEqual(result["findings"], [])

    def test_admin_path_hint_produces_finding(self):
        def fake_get(url):
            return (200, "User-agent: *\nDisallow: /admin\nDisallow: /backup\n")

        result = easm.check_robots_txt("https://example.com", _get_fn=fake_get)
        keys = [f["key"] for f in result["findings"]]
        self.assertIn("robots_admin_path_hint", keys)

    def test_oversized_robots_txt_produces_truncated_finding(self):
        def fake_get(url):
            big_body = "User-agent: *\nDisallow: /\n" + "A" * (easm.MAX_ROBOTS_BYTES + 100)
            return (200, big_body)

        result = easm.check_robots_txt("https://example.com", _get_fn=fake_get)
        keys = [f["key"] for f in result["findings"]]
        self.assertIn("robots_txt_oversized", keys)

    def test_missing_robots_txt_returns_not_present(self):
        def fake_get(url):
            return (404, "")

        result = easm.check_robots_txt("https://example.com", _get_fn=fake_get)
        self.assertFalse(result["present"])


# ---------------------------------------------------------------------------
# run_passive_scan
# ---------------------------------------------------------------------------


class TestRunPassiveScan(unittest.TestCase):
    def _fake_get(self, url):
        return {
            "status_code": 200,
            "final_url": url,
            "headers": {
                "Strict-Transport-Security": "max-age=31536000",
                "Content-Security-Policy": "default-src 'self'",
                "X-Frame-Options": "DENY",
                "X-Content-Type-Options": "nosniff",
                "Referrer-Policy": "no-referrer",
                "Permissions-Policy": "camera=()",
            },
            "redirect_count": 0,
            "body_length": 1024,
        }

    def _fake_resolve(self, hostname):
        return [(socket.AF_INET, socket.SOCK_STREAM, 0, "", ("93.184.216.34", 0))]

    def _fake_connect(self, hostname):
        import time
        ts = time.time() + 365 * 86400
        t = time.gmtime(ts)
        not_after = time.strftime("%b %d %H:%M:%S %Y GMT", t)
        return {"notAfter": not_after, "issuer": [(("commonName", "Test CA"),)]}

    def test_private_ip_url_rejected(self):
        result = easm.run_passive_scan(
            "https://192.168.1.1/",
            tenant_id="t1",
            asset_id="1",
        )
        self.assertIsNotNone(result["error"])
        self.assertEqual(result["checks"], [])

    def test_normal_run_returns_checks(self):
        result = easm.run_passive_scan(
            "https://example.com",
            tenant_id="t1",
            asset_id="1",
            _get_fn=self._fake_get,
            _resolve_fn=self._fake_resolve,
            _connect_fn=self._fake_connect,
        )
        self.assertIsNone(result["error"])
        self.assertGreater(len(result["checks"]), 0)

    def test_all_findings_populated(self):
        result = easm.run_passive_scan(
            "https://example.com",
            tenant_id="t1",
            asset_id="1",
            _get_fn=self._fake_get,
            _resolve_fn=self._fake_resolve,
            _connect_fn=self._fake_connect,
        )
        self.assertIsInstance(result["all_findings"], list)

    def test_started_and_finished_at_present(self):
        result = easm.run_passive_scan(
            "https://example.com",
            tenant_id="t1",
            asset_id="1",
            _get_fn=self._fake_get,
            _resolve_fn=self._fake_resolve,
            _connect_fn=self._fake_connect,
        )
        self.assertIn("started_at", result)
        self.assertIn("finished_at", result)


# ---------------------------------------------------------------------------
# build_report
# ---------------------------------------------------------------------------


class TestBuildReport(unittest.TestCase):
    def _make_report(self, findings=None):
        if findings is None:
            findings = []
        return easm.build_report(
            asset_url="https://example.com",
            tenant_id="t1",
            asset_id="1",
            scan_policy="passive",
            started_at="2026-06-25T00:00:00+00:00",
            ended_at="2026-06-25T00:00:30+00:00",
            checks=[],
            all_findings=findings,
        )

    def test_required_keys_present(self):
        r = self._make_report()
        for key in ("asset", "scan_policy", "started_at", "finished_at", "findings", "summary", "overall_status"):
            self.assertIn(key, r)

    def test_summary_counts_correct(self):
        findings = [
            {"severity": "high", "category": "tls"},
            {"severity": "medium", "category": "security_header"},
            {"severity": "low", "category": "cookie"},
        ]
        r = self._make_report(findings)
        self.assertEqual(r["summary"]["total"], 3)
        self.assertEqual(r["summary"]["by_severity"]["high"], 1)
        self.assertEqual(r["summary"]["by_severity"]["medium"], 1)
        self.assertEqual(r["summary"]["by_severity"]["low"], 1)

    def test_overall_status_pass_when_no_findings(self):
        r = self._make_report([])
        self.assertEqual(r["overall_status"], "PASS")

    def test_overall_status_findings_when_high_or_medium(self):
        r = self._make_report([{"severity": "high", "category": "tls"}])
        self.assertEqual(r["overall_status"], "FINDINGS")

    def test_overall_status_warn_when_only_low_or_info(self):
        r = self._make_report([
            {"severity": "low", "category": "cookie"},
            {"severity": "info", "category": "exposure"},
        ])
        self.assertEqual(r["overall_status"], "WARN")

    def test_asset_block_has_expected_keys(self):
        r = self._make_report()
        for k in ("url", "hostname", "tenant_id", "asset_id"):
            self.assertIn(k, r["asset"])


# ---------------------------------------------------------------------------
# main() dry-run
# ---------------------------------------------------------------------------


class TestMainDryRun(unittest.TestCase):
    def test_dry_run_exits_0(self):
        with self.assertRaises(SystemExit) as cm:
            easm.main(["--url", "https://example.com", "--dry-run"])
        self.assertEqual(cm.exception.code, 0)

    def test_dry_run_does_not_write_output_file(self):
        with tempfile.TemporaryDirectory() as tmpdir:
            out_path = Path(tmpdir) / "report.json"
            try:
                easm.main([
                    "--url", "https://example.com",
                    "--dry-run",
                    "--output", str(out_path),
                ])
            except SystemExit:
                pass
            self.assertFalse(out_path.exists())

    def test_invalid_url_exits_1(self):
        with self.assertRaises(SystemExit) as cm:
            easm.main(["--url", "https://127.0.0.1/"])
        self.assertEqual(cm.exception.code, 1)


if __name__ == "__main__":
    unittest.main()
