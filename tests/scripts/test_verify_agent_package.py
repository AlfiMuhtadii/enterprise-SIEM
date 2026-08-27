import json
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest import mock

from scripts import build_agent_package as builder
from scripts import verify_agent_package as verifier

ROOT = Path(__file__).resolve().parents[2]


def write_manifest(package: Path, entries: list[dict], platform: str = "linux") -> None:
    manifest = {
        "schema_version": 1,
        "package": "detector-agent-test",
        "version": "1.2.3",
        "platform": platform,
        "environment": "local",
        "files": entries,
    }
    manifest_path = package / verifier.MANIFEST_NAME
    manifest_path.write_text(
        json.dumps(manifest, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    (package / verifier.MANIFEST_CHECKSUM_NAME).write_text(
        f"{verifier.sha256(manifest_path)}  {verifier.MANIFEST_NAME}\n",
        encoding="ascii",
    )


def entry(package: Path, relative: str) -> dict:
    path = package / relative
    return {
        "path": relative,
        "sha256": verifier.sha256(path),
        "bytes": path.stat().st_size,
    }


class PackageVerifierTests(unittest.TestCase):
    def make_package(self, root: Path) -> Path:
        package = root / "package"
        package.mkdir()
        (package / "agent.py").write_text("print('agent')\n", encoding="utf-8")
        (package / "config.json").write_text("{}\n", encoding="utf-8")
        write_manifest(package, [entry(package, "agent.py"), entry(package, "config.json")])
        return package

    def test_valid_package_passes_with_exact_inventory(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            package = self.make_package(Path(temp_dir))
            report = verifier.verify_package(package)

        self.assertEqual("PASS", report["status"])
        self.assertEqual(2, report["files_verified"])
        self.assertEqual("linux", report["platform"])

    def test_payload_tampering_is_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            package = self.make_package(Path(temp_dir))
            (package / "agent.py").write_text("print('tampered')\n", encoding="utf-8")

            with self.assertRaisesRegex(verifier.PackageVerificationError, "mismatch"):
                verifier.verify_package(package)

    def test_manifest_tampering_is_rejected_before_payload(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            package = self.make_package(Path(temp_dir))
            manifest = package / verifier.MANIFEST_NAME
            manifest.write_text(manifest.read_text(encoding="utf-8") + " ", encoding="utf-8")

            with self.assertRaisesRegex(verifier.PackageVerificationError, "checksum mismatch"):
                verifier.verify_package(package)

    def test_unexpected_file_is_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            package = self.make_package(Path(temp_dir))
            (package / "unlisted.exe").write_bytes(b"unexpected")

            with self.assertRaisesRegex(verifier.PackageVerificationError, "unexpected payload"):
                verifier.verify_package(package)

    def test_traversal_path_is_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            package = self.make_package(Path(temp_dir))
            entries = [entry(package, "agent.py")]
            entries[0]["path"] = "../agent.py"
            write_manifest(package, entries)

            with self.assertRaisesRegex(verifier.PackageVerificationError, "invalid manifest path"):
                verifier.verify_package(package)

    def test_case_colliding_paths_are_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            package = self.make_package(Path(temp_dir))
            first = entry(package, "agent.py")
            second = dict(first, path="AGENT.py")
            write_manifest(package, [first, second])

            with self.assertRaisesRegex(verifier.PackageVerificationError, "case-colliding"):
                verifier.verify_package(package)

    def test_symlink_is_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            package = self.make_package(Path(temp_dir))
            link = package / "agent-link.py"
            try:
                link.symlink_to(package / "agent.py")
            except OSError as exc:
                self.skipTest(f"symlink creation is unavailable: {exc}")
            write_manifest(package, [
                entry(package, "agent.py"),
                entry(package, "config.json"),
                {"path": "agent-link.py", "sha256": verifier.sha256(link), "bytes": link.stat().st_size},
            ])

            with self.assertRaisesRegex(verifier.PackageVerificationError, "symlink"):
                verifier.verify_package(package)


class PackageBuilderIntegrationTests(unittest.TestCase):
    def test_windows_and_linux_archives_verify_after_extraction(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            temp_root = Path(temp_dir)
            for platform in ("windows", "linux"):
                with self.subTest(platform=platform):
                    output = temp_root / platform
                    with mock.patch.object(sys, "argv", [
                        "build_agent_package.py",
                        "--platform", platform,
                        "--env", "staging",
                        "--version", "9.9.9-test",
                        "--output", str(output),
                    ]):
                        self.assertEqual(0, builder.main())

                    package = output / f"detector-agent-{platform}-staging-9.9.9-test"
                    report = verifier.verify_package(package)
                    self.assertEqual("PASS", report["status"])
                    self.assertTrue((package / verifier.MANIFEST_CHECKSUM_NAME).is_file())

                    manifest = json.loads((package / verifier.MANIFEST_NAME).read_text(encoding="utf-8"))
                    paths = {item["path"] for item in manifest["files"]}
                    self.assertIn("verify_agent_package.py", paths)
                    self.assertIn(
                        "install-agent-service.ps1" if platform == "windows" else "install-agent-service.sh",
                        paths,
                    )

                    extracted = temp_root / f"extracted-{platform}"
                    with zipfile.ZipFile(Path(str(package) + ".zip")) as archive:
                        archive.extractall(extracted)
                    self.assertEqual("PASS", verifier.verify_package(extracted)["status"])

    def test_installers_verify_before_service_mutation(self) -> None:
        windows = (ROOT / "deploy" / "agent" / "windows" / "install-agent-service.ps1").read_text(
            encoding="utf-8"
        )
        linux = (ROOT / "deploy" / "agent" / "linux" / "install-agent-service.sh").read_text(
            encoding="utf-8"
        )

        self.assertLess(windows.index("verify_agent_package.py"), windows.index("New-Service"))
        self.assertIn("Installed endpoint agent package verification failed", windows)
        self.assertIn("InstallPath must be empty", windows)
        self.assertLess(linux.index("verify_agent_package.py"), linux.index("useradd"))
        self.assertLess(linux.index("verify_agent_package.py"), linux.index("systemctl daemon-reload"))


if __name__ == "__main__":
    unittest.main()
