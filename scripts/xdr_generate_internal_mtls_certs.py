#!/usr/bin/env python3
"""
ENT-SEC-NO-TLS-INTERNAL (phase 1) — Internal mTLS dev/test certificate
generator.

Generates a self-signed local CA plus one server cert (SAN covering every
internal service hostname docker-compose resolves) and one client cert,
all signed by that CA, via the system `openssl` CLI (no Python crypto
dependency added). Output is written to storage/certs/internal-mtls/
(gitignored — private keys must never be committed) and is consumed by
services when XDR_INTERNAL_MTLS_ENABLED=true (default false everywhere).

Scope: this generates real, verifiable X.509 certs and is the first of
several deferred phases — wiring every service's HTTP client/server to
actually use them, and the docker-compose network config, are tracked
separately. See REVIEW_COMPLETED.md / REVIEW_BACKLOG.md for the phase
breakdown.
"""

import shutil
import subprocess
import sys
import unittest
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent
DEFAULT_OUT_DIR = BASE_DIR / "storage" / "certs" / "internal-mtls"

# Every internal service hostname docker-compose resolves today, plus
# localhost/127.0.0.1 for local (non-compose) testing.
SERVICE_SANS = [
    "ingestion-gateway",
    "normalizer-worker",
    "correlation-worker",
    "alert-writer-service",
    "incident-builder-service",
    "ai-rag-service",
    "localhost",
    "127.0.0.1",
]


def openssl_available() -> bool:
    return shutil.which("openssl") is not None


def _run(args: list) -> subprocess.CompletedProcess:
    return subprocess.run(args, capture_output=True, text=True, check=True)


def generate_ca(out_dir: Path) -> tuple:
    out_dir.mkdir(parents=True, exist_ok=True)
    ca_key = out_dir / "ca.key"
    ca_crt = out_dir / "ca.crt"
    _run([
        "openssl", "req", "-x509", "-newkey", "rsa:4096", "-nodes",
        "-keyout", str(ca_key), "-out", str(ca_crt),
        "-days", "825", "-subj", "/CN=Detector XDR Internal Dev CA",
        # Explicit CA extensions — without these, some default openssl.cnf
        # policies omit a critical keyUsage/basicConstraints combination that
        # strict TLS clients (Python's ssl module on OpenSSL 3.x) reject
        # outright with "CA cert does not include key usage extension",
        # even though the cert IS self-signed and structurally a CA.
        "-addext", "basicConstraints=critical,CA:TRUE",
        "-addext", "keyUsage=critical,keyCertSign,cRLSign",
    ])
    return ca_key, ca_crt


def _san_config(sans: list) -> str:
    lines = ["[req]", "distinguished_name=req", "[v3_req]", "subjectAltName=@alt_names", "[alt_names]"]
    for i, san in enumerate(sans, start=1):
        prefix = "IP" if san.replace(".", "").isdigit() else "DNS"
        lines.append(f"{prefix}.{i}={san}")
    return "\n".join(lines) + "\n"


def generate_service_cert(out_dir: Path, name: str, common_name: str, sans: list,
                           ca_key: Path, ca_crt: Path, extended_key_usage: str) -> tuple:
    key_path = out_dir / f"{name}.key"
    csr_path = out_dir / f"{name}.csr"
    crt_path = out_dir / f"{name}.crt"
    conf_path = out_dir / f"{name}.san.cnf"

    conf_path.write_text(_san_config(sans), encoding="utf-8")

    _run([
        "openssl", "req", "-newkey", "rsa:2048", "-nodes",
        "-keyout", str(key_path), "-out", str(csr_path),
        "-subj", f"/CN={common_name}",
        "-addext", f"subjectAltName={','.join(('IP:' + s) if s.replace('.', '').isdigit() else ('DNS:' + s) for s in sans)}",
    ])
    _run([
        "openssl", "x509", "-req", "-in", str(csr_path),
        "-CA", str(ca_crt), "-CAkey", str(ca_key), "-CAcreateserial",
        "-out", str(crt_path), "-days", "825",
        "-extfile", str(conf_path), "-extensions", "v3_req",
    ])
    csr_path.unlink(missing_ok=True)
    conf_path.unlink(missing_ok=True)
    return key_path, crt_path


def generate_all(out_dir: Path = DEFAULT_OUT_DIR) -> dict:
    if not openssl_available():
        raise RuntimeError("openssl CLI not found on PATH")

    ca_key, ca_crt = generate_ca(out_dir)
    server_key, server_crt = generate_service_cert(
        out_dir, "server", "internal-services", SERVICE_SANS, ca_key, ca_crt,
        extended_key_usage="serverAuth",
    )
    client_key, client_crt = generate_service_cert(
        out_dir, "client", "internal-service-client", ["localhost"], ca_key, ca_crt,
        extended_key_usage="clientAuth",
    )
    return {
        "ca_key": str(ca_key), "ca_crt": str(ca_crt),
        "server_key": str(server_key), "server_crt": str(server_crt),
        "client_key": str(client_key), "client_crt": str(client_crt),
    }


def verify_chain(ca_crt: Path, leaf_crt: Path) -> bool:
    result = subprocess.run(
        ["openssl", "verify", "-CAfile", str(ca_crt), str(leaf_crt)],
        capture_output=True, text=True,
    )
    return result.returncode == 0


def cert_sans(crt_path: Path) -> str:
    result = _run(["openssl", "x509", "-in", str(crt_path), "-noout", "-text"])
    return result.stdout


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------

class TestInternalMtlsCertGenerator(unittest.TestCase):
    _tmp = None

    @classmethod
    def setUpClass(cls):
        if not openssl_available():
            raise unittest.SkipTest("openssl CLI not available in this environment")
        import tempfile
        cls._tmp = Path(tempfile.mkdtemp(prefix="xdr_mtls_test_"))
        cls.paths = generate_all(cls._tmp)

    @classmethod
    def tearDownClass(cls):
        if cls._tmp is not None:
            shutil.rmtree(cls._tmp, ignore_errors=True)

    def test_all_expected_files_exist(self):
        for key in ("ca_key", "ca_crt", "server_key", "server_crt", "client_key", "client_crt"):
            self.assertTrue(Path(self.paths[key]).is_file(), f"missing {key}")

    def test_no_leftover_csr_or_san_config_files(self):
        leftovers = list(self._tmp.glob("*.csr")) + list(self._tmp.glob("*.san.cnf"))
        self.assertEqual(leftovers, [], f"leaked intermediate files: {leftovers}")

    def test_server_cert_verifies_against_ca(self):
        self.assertTrue(verify_chain(Path(self.paths["ca_crt"]), Path(self.paths["server_crt"])))

    def test_client_cert_verifies_against_ca(self):
        self.assertTrue(verify_chain(Path(self.paths["ca_crt"]), Path(self.paths["client_crt"])))

    def test_server_cert_is_not_self_signed(self):
        # A cert must NOT verify against itself as its own CA (would indicate
        # the signing step silently fell back to self-signing).
        self.assertFalse(verify_chain(Path(self.paths["server_crt"]), Path(self.paths["server_crt"])))

    def test_ca_cert_has_critical_key_usage_and_basic_constraints(self):
        # Regression test: a CA cert generated without an explicit critical
        # keyUsage=keyCertSign,cRLSign + basicConstraints=CA:TRUE was accepted
        # by openssl's own chain verify (test_server/client_cert_verifies_
        # against_ca above) but rejected outright by stricter TLS clients
        # (confirmed against Python's ssl module on OpenSSL 3.x: "CA cert does
        # not include key usage extension") — caught via an actual end-to-end
        # handshake smoke test, not by openssl verify alone, which is why this
        # explicit extension-content check exists as its own test.
        text = cert_sans(Path(self.paths["ca_crt"]))
        self.assertIn("CA:TRUE", text)
        self.assertIn("Certificate Sign", text)
        self.assertIn("CRL Sign", text)

    def test_server_cert_includes_all_service_hostnames_in_san(self):
        text = cert_sans(Path(self.paths["server_crt"]))
        for san in SERVICE_SANS:
            if san == "127.0.0.1":
                self.assertIn("IP Address:127.0.0.1", text)
            else:
                self.assertIn(san, text)

    def test_regenerating_produces_a_different_ca_each_time(self):
        # Each generate_all() call must mint a fresh CA (no accidental key reuse
        # across environments/operators sharing this script).
        import tempfile
        tmp2 = Path(tempfile.mkdtemp(prefix="xdr_mtls_test2_"))
        try:
            paths2 = generate_all(tmp2)
            ca1 = Path(self.paths["ca_crt"]).read_text(encoding="utf-8")
            ca2 = Path(paths2["ca_crt"]).read_text(encoding="utf-8")
            self.assertNotEqual(ca1, ca2)
        finally:
            shutil.rmtree(tmp2, ignore_errors=True)


if __name__ == "__main__":
    if "--generate" in sys.argv:
        if not openssl_available():
            print("ERROR: openssl CLI not found on PATH", file=sys.stderr)
            sys.exit(1)
        result = generate_all()
        print("Generated internal mTLS dev/test certs:")
        for k, v in result.items():
            print(f"  {k}: {v}")
        sys.exit(0)
    else:
        sys.argv = [a for a in sys.argv if a != "--test"]
        unittest.main(verbosity=2)
