#!/usr/bin/env python3
"""
ENT-SEC-NO-TLS-INTERNAL — validates the docker-entrypoint.sh mTLS wrapper
shipped in each Python service (alert-writer-service, incident-builder-
service, ai-rag-service).

uvicorn is started via a static Dockerfile CMD in these 3 services (no
uvicorn.run() call in main.py to hook into), so the disabled-by-default
mTLS toggle lives in a small POSIX shell entrypoint instead of Go code.
This validator shells out to the REAL script (not a reimplementation) via
its XDR_ENTRYPOINT_TEST_MODE=1 hook, which prints the resolved uvicorn
command line instead of exec'ing it — letting this run without uvicorn/
fastapi actually installed.
"""

import shutil
import subprocess
import sys
import unittest
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent
SERVICES = ["alert-writer-service", "incident-builder-service", "ai-rag-service"]
PORTS = {"alert-writer-service": "8095", "incident-builder-service": "8096", "ai-rag-service": "8094"}


def _find_posix_bash() -> str:
    # On Windows, multiple bash.exe can be on PATH (Git Bash, the WSL
    # launcher stub, a WindowsApps shim) — the WSL stub interprets Windows
    # paths incorrectly, so prefer Git Bash explicitly when present rather
    # than relying on PATH order.
    for candidate in (r"C:\Program Files\Git\usr\bin\bash.exe", r"C:\Program Files\Git\bin\bash.exe"):
        if Path(candidate).is_file():
            return candidate
    found = shutil.which("bash")
    if found:
        return found
    raise RuntimeError("no bash executable found to run docker-entrypoint.sh tests")


BASH = _find_posix_bash()


def run_entrypoint(service: str, port: str = None, env_overrides: dict = None) -> subprocess.CompletedProcess:
    script = BASE_DIR / "services" / service / "docker-entrypoint.sh"
    args = [BASH, script.as_posix()]
    if port is not None:
        args.append(port)

    import os
    env = os.environ.copy()
    env["XDR_ENTRYPOINT_TEST_MODE"] = "1"
    # Start from a clean slate for the mTLS-related vars so a developer's
    # real shell environment can't leak into the test.
    for k in ["XDR_INTERNAL_MTLS_ENABLED", "XDR_INTERNAL_MTLS_SERVER_CERT",
              "XDR_INTERNAL_MTLS_SERVER_KEY", "XDR_INTERNAL_MTLS_CA"]:
        env.pop(k, None)
    if env_overrides:
        env.update(env_overrides)

    return subprocess.run(args, capture_output=True, text=True, env=env)


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------

class TestUvicornMtlsEntrypoint(unittest.TestCase):
    def test_script_exists_and_is_executable_for_every_service(self):
        for service in SERVICES:
            script = BASE_DIR / "services" / service / "docker-entrypoint.sh"
            self.assertTrue(script.is_file(), f"{service} missing docker-entrypoint.sh")

    def test_dockerfile_uses_the_entrypoint_script(self):
        for service in SERVICES:
            dockerfile = (BASE_DIR / "services" / service / "Dockerfile").read_text(encoding="utf-8")
            self.assertIn("docker-entrypoint.sh", dockerfile, f"{service}'s Dockerfile does not reference docker-entrypoint.sh")

    def test_dockerfile_invokes_entrypoint_via_sh_not_direct_exec(self):
        # Regression guard: this repo's git checkout has core.fileMode=false,
        # so a committed script's executable bit is NOT preserved - a bare
        # `CMD ["./docker-entrypoint.sh", ...]` (direct exec) would fail with
        # "permission denied" on a real Linux build. `CMD ["sh",
        # "docker-entrypoint.sh", ...]` sidesteps this since sh reads and
        # interprets the file directly, no execute bit required.
        for service in SERVICES:
            dockerfile = (BASE_DIR / "services" / service / "Dockerfile").read_text(encoding="utf-8")
            self.assertIn('CMD ["sh", "docker-entrypoint.sh"', dockerfile,
                           f"{service}'s Dockerfile must invoke docker-entrypoint.sh via sh, not direct exec")

    def test_disabled_by_default_produces_plain_uvicorn_command(self):
        for service in SERVICES:
            port = PORTS[service]
            result = run_entrypoint(service, port)
            self.assertEqual(result.returncode, 0, result.stderr)
            expected = f"python -m uvicorn main:app --host 0.0.0.0 --port {port}"
            self.assertEqual(result.stdout.strip(), expected)
            self.assertNotIn("--ssl-", result.stdout)

    def test_explicitly_false_is_identical_to_default(self):
        result = run_entrypoint("alert-writer-service", "8095", {"XDR_INTERNAL_MTLS_ENABLED": "false"})
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn("--ssl-", result.stdout)

    def test_enabled_adds_all_four_ssl_flags_with_cert_reqs_required(self):
        for service in SERVICES:
            port = PORTS[service]
            result = run_entrypoint(service, port, {
                "XDR_INTERNAL_MTLS_ENABLED": "true",
                "XDR_INTERNAL_MTLS_SERVER_CERT": "/certs/server.crt",
                "XDR_INTERNAL_MTLS_SERVER_KEY": "/certs/server.key",
                "XDR_INTERNAL_MTLS_CA": "/certs/ca.crt",
            })
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn("--ssl-certfile /certs/server.crt", result.stdout)
            self.assertIn("--ssl-keyfile /certs/server.key", result.stdout)
            self.assertIn("--ssl-ca-certs /certs/ca.crt", result.stdout)
            # --ssl-cert-reqs 2 == ssl.CERT_REQUIRED: this is what makes it
            # mutual TLS (client cert required), not just server-side TLS.
            self.assertIn("--ssl-cert-reqs 2", result.stdout)

    def test_enabled_without_server_cert_fails_closed(self):
        result = run_entrypoint("alert-writer-service", "8095", {
            "XDR_INTERNAL_MTLS_ENABLED": "true",
            "XDR_INTERNAL_MTLS_SERVER_KEY": "/certs/server.key",
            "XDR_INTERNAL_MTLS_CA": "/certs/ca.crt",
        })
        self.assertNotEqual(result.returncode, 0, "must fail closed when a required mTLS var is missing, not silently start plaintext")
        self.assertIn("XDR_INTERNAL_MTLS_SERVER_CERT", result.stderr)

    def test_enabled_without_ca_fails_closed(self):
        result = run_entrypoint("alert-writer-service", "8095", {
            "XDR_INTERNAL_MTLS_ENABLED": "true",
            "XDR_INTERNAL_MTLS_SERVER_CERT": "/certs/server.crt",
            "XDR_INTERNAL_MTLS_SERVER_KEY": "/certs/server.key",
        })
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("XDR_INTERNAL_MTLS_CA", result.stderr)

    def test_missing_port_argument_fails_closed(self):
        result = run_entrypoint("alert-writer-service", port=None)
        self.assertNotEqual(result.returncode, 0)

    def test_the_three_services_use_their_documented_ports(self):
        self.assertEqual(PORTS["alert-writer-service"], "8095")
        self.assertEqual(PORTS["incident-builder-service"], "8096")
        self.assertEqual(PORTS["ai-rag-service"], "8094")


# ---------------------------------------------------------------------------
# docker-healthcheck.py: build_request() is pure (no network I/O), imported
# and tested directly for its URL-scheme/SSLContext selection logic.
# ---------------------------------------------------------------------------

def _load_build_request(service: str):
    import importlib.util
    path = BASE_DIR / "services" / service / "docker-healthcheck.py"
    spec = importlib.util.spec_from_file_location(f"{service.replace('-', '_')}_healthcheck", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module.build_request


class TestDockerHealthcheckBuildRequest(unittest.TestCase):
    def test_files_exist_for_every_service(self):
        for service in SERVICES:
            script = BASE_DIR / "services" / service / "docker-healthcheck.py"
            self.assertTrue(script.is_file(), f"{service} missing docker-healthcheck.py")

    def test_disabled_by_default_uses_plain_http_and_no_ssl_context(self):
        build_request = _load_build_request("alert-writer-service")
        url, ctx = build_request("8095", env={})
        self.assertEqual(url, "http://127.0.0.1:8095/health")
        self.assertIsNone(ctx)

    def test_enabled_uses_https_and_real_client_cert_ssl_context(self):
        build_request = _load_build_request("alert-writer-service")
        certs = BASE_DIR / "storage" / "certs" / "internal-mtls"
        if not (certs / "ca.crt").is_file():
            self.skipTest("dev/test certs not generated (run scripts/xdr_generate_internal_mtls_certs.py --generate)")
        url, ctx = build_request("8095", env={
            "XDR_INTERNAL_MTLS_ENABLED": "true",
            "XDR_INTERNAL_MTLS_CA": str(certs / "ca.crt"),
            "XDR_INTERNAL_MTLS_CLIENT_CERT": str(certs / "client.crt"),
            "XDR_INTERNAL_MTLS_CLIENT_KEY": str(certs / "client.key"),
        })
        self.assertEqual(url, "https://127.0.0.1:8095/health")
        self.assertIsNotNone(ctx)
        # verify_mode defaults to CERT_REQUIRED under create_default_context,
        # confirming this is a real, verifying client context, not a
        # permissive/insecure one.
        import ssl
        self.assertEqual(ctx.verify_mode, ssl.CERT_REQUIRED)

    def test_ports_match_the_uvicorn_entrypoint_ports(self):
        for service in SERVICES:
            build_request = _load_build_request(service)
            url, _ = build_request(PORTS[service], env={})
            self.assertIn(f":{PORTS[service]}/health", url)


if __name__ == "__main__":
    unittest.main(verbosity=2)
