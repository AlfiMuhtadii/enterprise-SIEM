#!/usr/bin/env python3
"""
ENT-SDLC-NO-SUPPLYCHAIN (continuation) — Offline CycloneDX SBOM generator.

Base-image digest pinning was already done (see REVIEW_COMPLETED.md). This
covers the remaining "SBOM generation" half without requiring syft/cyclonedx
CLI tooling (neither is installed in this environment) — it hand-builds a
valid CycloneDX 1.5 JSON document per service directly from data this repo
already has and already trusts: each service's requirements.txt/go.mod
(exact-pinned dependency versions) and its Dockerfile's digest-pinned FROM
line (base image + sha256).

Vulnerability scan gating (trivy) and signed builds (cosign) remain out of
scope — both need real, licensed/network-dependent tooling this environment
cannot install or verify, unlike SBOM generation which is a pure data
transform of files already in the repo.
"""

import json
import os
import re
import sys
import unittest
import uuid
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent
SERVICES_DIR = BASE_DIR / "services"
SBOM_DIR = BASE_DIR / "docs" / "security" / "sbom"

PYTHON_REQ_RE = re.compile(r"^([A-Za-z0-9_.\-]+)(?:\[[^\]]*\])?==([A-Za-z0-9_.\-]+)\s*$")
GO_MODULE_RE = re.compile(r"^module\s+(\S+)")
GO_VERSION_RE = re.compile(r"^go\s+(\S+)")
GO_REQUIRE_RE = re.compile(r"^\s*([A-Za-z0-9_./\-]+)\s+v(\S+)")
DOCKER_FROM_RE = re.compile(r"^FROM\s+([A-Za-z0-9_./\-]+):([A-Za-z0-9_.\-]+)@sha256:([0-9a-f]{64})", re.MULTILINE)


def discover_services() -> list:
    services = []
    for d in sorted(SERVICES_DIR.iterdir()):
        if not d.is_dir():
            continue
        if (d / "requirements.txt").is_file() or (d / "go.mod").is_file():
            services.append(d)
    return services


def parse_requirements(path: Path) -> list:
    components = []
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        m = PYTHON_REQ_RE.match(line)
        if not m:
            continue
        name, version = m.group(1), m.group(2)
        components.append({
            "type": "library",
            "name": name,
            "version": version,
            "purl": f"pkg:pypi/{name.lower()}@{version}",
        })
    return components


def parse_go_mod(path: Path) -> list:
    components = []
    module_name = None
    go_version = None
    for line in path.read_text(encoding="utf-8").splitlines():
        stripped = line.strip()
        if module_name is None:
            m = GO_MODULE_RE.match(stripped)
            if m:
                module_name = m.group(1)
                continue
        if go_version is None:
            m = GO_VERSION_RE.match(stripped)
            if m:
                go_version = m.group(1)
                continue
        m = GO_REQUIRE_RE.match(line)
        if m and not stripped.startswith(("module", "go ")):
            name, version = m.group(1), m.group(2)
            components.append({
                "type": "library",
                "name": name,
                "version": version,
                "purl": f"pkg:golang/{name}@v{version}",
            })
    if go_version:
        components.insert(0, {
            "type": "framework",
            "name": "go",
            "version": go_version,
            "purl": f"pkg:generic/go@{go_version}",
        })
    return components


def parse_dockerfile_base_images(path: Path) -> list:
    if not path.is_file():
        return []
    text = path.read_text(encoding="utf-8")
    components = []
    seen = set()
    for image, tag, digest in DOCKER_FROM_RE.findall(text):
        key = (image, tag, digest)
        if key in seen:
            continue
        seen.add(key)
        components.append({
            "type": "container",
            "name": image,
            "version": tag,
            "purl": f"pkg:docker/{image}@{tag}?digest=sha256%3A{digest}",
            "hashes": [{"alg": "SHA-256", "content": digest}],
        })
    return components


def generate_sbom_for_service(service_dir: Path) -> dict:
    service_name = service_dir.name
    components = []

    req = service_dir / "requirements.txt"
    if req.is_file():
        components.extend(parse_requirements(req))

    go_mod = service_dir / "go.mod"
    if go_mod.is_file():
        components.extend(parse_go_mod(go_mod))

    components.extend(parse_dockerfile_base_images(service_dir / "Dockerfile"))

    return {
        "bomFormat": "CycloneDX",
        "specVersion": "1.5",
        "serialNumber": f"urn:uuid:{uuid.uuid5(uuid.NAMESPACE_DNS, f'detector-xdr.{service_name}.sbom')}",
        "version": 1,
        "metadata": {
            "component": {
                "type": "application",
                "name": service_name,
                "version": "unversioned",
            },
        },
        "components": components,
    }


def generate_all() -> dict:
    generated = {}
    for service_dir in discover_services():
        generated[service_dir.name] = generate_sbom_for_service(service_dir)
    return generated


def write_all(out_dir: Path = SBOM_DIR) -> dict:
    os.makedirs(out_dir, exist_ok=True)
    generated = generate_all()
    written = []
    for name, sbom in generated.items():
        out_path = out_dir / f"{name}.cyclonedx.json"
        out_path.write_text(json.dumps(sbom, indent=2) + "\n", encoding="utf-8")
        written.append(str(out_path))
    return {"services": list(generated.keys()), "files_written": written}


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------

class TestSbomGenerator(unittest.TestCase):
    def test_discovers_all_services(self):
        services = discover_services()
        names = {d.name for d in services}
        self.assertIn("ai-rag-service", names)
        self.assertIn("correlation-worker", names)
        self.assertIn("log-connector-syslog", names)
        self.assertGreaterEqual(len(services), 9)

    def test_parse_requirements_extracts_pinned_versions(self):
        components = parse_requirements(SERVICES_DIR / "alert-writer-service" / "requirements.txt")
        names = {c["name"] for c in components}
        self.assertIn("fastapi", names)
        self.assertIn("psycopg", names)
        fastapi = next(c for c in components if c["name"] == "fastapi")
        self.assertEqual(fastapi["version"], "0.139.0")
        self.assertEqual(fastapi["purl"], "pkg:pypi/fastapi@0.139.0")

    def test_parse_requirements_strips_extras(self):
        components = parse_requirements(SERVICES_DIR / "alert-writer-service" / "requirements.txt")
        names = {c["name"] for c in components}
        self.assertIn("uvicorn", names)
        self.assertNotIn("uvicorn[standard]", names)

    def test_parse_go_mod_captures_go_version(self):
        components = parse_go_mod(SERVICES_DIR / "correlation-worker" / "go.mod")
        go_component = next((c for c in components if c["name"] == "go"), None)
        self.assertIsNotNone(go_component)
        self.assertEqual(go_component["type"], "framework")

    def test_parse_dockerfile_captures_digest_pinned_base_images(self):
        components = parse_dockerfile_base_images(SERVICES_DIR / "correlation-worker" / "Dockerfile")
        self.assertGreaterEqual(len(components), 1)
        for c in components:
            self.assertEqual(c["type"], "container")
            self.assertEqual(len(c["hashes"][0]["content"]), 64)
            self.assertRegex(c["hashes"][0]["content"], r"^[0-9a-f]{64}$")

    def test_generate_sbom_for_service_is_valid_cyclonedx_shape(self):
        sbom = generate_sbom_for_service(SERVICES_DIR / "ai-rag-service")
        self.assertEqual(sbom["bomFormat"], "CycloneDX")
        self.assertEqual(sbom["specVersion"], "1.5")
        self.assertIn("components", sbom)
        self.assertEqual(sbom["metadata"]["component"]["name"], "ai-rag-service")

    def test_generate_sbom_serial_number_is_deterministic(self):
        first = generate_sbom_for_service(SERVICES_DIR / "ai-rag-service")
        second = generate_sbom_for_service(SERVICES_DIR / "ai-rag-service")
        self.assertEqual(first["serialNumber"], second["serialNumber"])

    def test_go_only_service_includes_container_base_images_not_python_libs(self):
        sbom = generate_sbom_for_service(SERVICES_DIR / "correlation-worker")
        types = {c["type"] for c in sbom["components"]}
        self.assertIn("container", types)
        purls = [c["purl"] for c in sbom["components"]]
        self.assertTrue(all(not p.startswith("pkg:pypi/") for p in purls))

    def test_python_service_includes_pypi_and_container_components(self):
        sbom = generate_sbom_for_service(SERVICES_DIR / "alert-writer-service")
        types = {c["type"] for c in sbom["components"]}
        self.assertIn("library", types)
        self.assertIn("container", types)

    def test_generate_all_covers_every_service(self):
        generated = generate_all()
        self.assertEqual(set(generated.keys()), {d.name for d in discover_services()})

    def test_all_purls_are_syntactically_well_formed(self):
        for sbom in generate_all().values():
            for c in sbom["components"]:
                self.assertRegex(c["purl"], r"^pkg:[a-z]+/")


if __name__ == "__main__":
    if "--generate" in sys.argv:
        result = write_all()
        print(json.dumps(result, indent=2))
        sys.exit(0)
    else:
        sys.argv = [a for a in sys.argv if a != "--test"]
        unittest.main(verbosity=2)
