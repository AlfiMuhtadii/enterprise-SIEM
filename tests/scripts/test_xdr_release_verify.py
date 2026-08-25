import copy
import subprocess
import unittest

from scripts.xdr_release_manifest import EXPECTED_SERVICES, aggregate_fragments
from scripts.xdr_release_verify import (
    OIDC_ISSUER,
    VerificationError,
    expected_certificate_identity,
    verify_release,
)
from tests.scripts.test_xdr_release_manifest import evidence


class ReleaseVerificationTest(unittest.TestCase):
    def setUp(self) -> None:
        self.manifest = aggregate_fragments(evidence(service) for service in EXPECTED_SERVICES)

    def test_verifies_every_digest_with_exact_tag_identity(self) -> None:
        commands = []

        def run(command, **kwargs):
            commands.append((command, kwargs))
            return subprocess.CompletedProcess(command, 0, stdout="verified", stderr="")

        report = verify_release(
            self.manifest,
            "example/repo",
            "v1.2.3-rc.1",
            "a" * 40,
            "/usr/local/bin/cosign",
            run_command=run,
        )

        identity = expected_certificate_identity("example/repo", "v1.2.3-rc.1")
        self.assertEqual("PASS", report["status"])
        self.assertEqual(len(EXPECTED_SERVICES), len(commands))
        for command, kwargs in commands:
            self.assertEqual("/usr/local/bin/cosign", command[0])
            self.assertIn("--certificate-identity", command)
            self.assertIn(identity, command)
            self.assertIn(OIDC_ISSUER, command)
            self.assertIn("--certificate-github-workflow-sha", command)
            self.assertIn("a" * 40, command)
            self.assertIn("--certificate-github-workflow-repository", command)
            self.assertIn("example/repo", command)
            self.assertIn("--certificate-github-workflow-ref", command)
            self.assertIn("refs/tags/v1.2.3-rc.1", command)
            self.assertRegex(command[-1], r"@sha256:[0-9a-f]{64}$")
            self.assertFalse(kwargs["check"])

    def test_rejects_manifest_identity_not_matching_trusted_repository(self) -> None:
        self.manifest["images"][0]["signature"]["certificate_identity"] = (
            "https://github.com/attacker/repo/.github/workflows/release.yml@refs/tags/v1.2.3-rc.1"
        )

        with self.assertRaisesRegex(VerificationError, "untrusted certificate identity"):
            verify_release(
                self.manifest, "example/repo", "v1.2.3-rc.1", "a" * 40, "cosign"
            )

    def test_rejects_untrusted_oidc_issuer_before_running_cosign(self) -> None:
        self.manifest["images"][0]["signature"]["oidc_issuer"] = "https://issuer.invalid"

        with self.assertRaisesRegex(VerificationError, "untrusted OIDC issuer"):
            verify_release(
                self.manifest, "example/repo", "v1.2.3-rc.1", "a" * 40, "cosign"
            )

    def test_returns_fail_and_evidence_when_one_signature_is_invalid(self) -> None:
        calls = 0

        def run(command, **kwargs):
            nonlocal calls
            calls += 1
            return subprocess.CompletedProcess(
                command,
                1 if calls == 1 else 0,
                stdout="",
                stderr="signature verification failed" if calls == 1 else "",
            )

        report = verify_release(
            self.manifest,
            "example/repo",
            "v1.2.3-rc.1",
            "a" * 40,
            "cosign",
            run_command=run,
        )

        self.assertEqual("FAIL", report["status"])
        self.assertEqual(1, sum(not image["verified"] for image in report["images"]))
        self.assertIn("signature verification failed", report["images"][0]["error"])

    def test_timeout_fails_closed(self) -> None:
        calls = 0

        def run(command, **kwargs):
            nonlocal calls
            calls += 1
            raise subprocess.TimeoutExpired(command, kwargs["timeout"])

        report = verify_release(
            self.manifest,
            "example/repo",
            "v1.2.3-rc.1",
            "a" * 40,
            "cosign",
            timeout_seconds=1,
            run_command=run,
        )

        self.assertEqual("FAIL", report["status"])
        self.assertTrue(all(not image["verified"] for image in report["images"]))
        self.assertEqual(1, calls)

    def test_rejects_tampered_release_metadata_before_running_cosign(self) -> None:
        manifest = copy.deepcopy(self.manifest)
        manifest["release"]["commit"] = "b" * 40

        with self.assertRaisesRegex(ValueError, "release metadata does not match"):
            verify_release(
                manifest, "example/repo", "v1.2.3-rc.1", "a" * 40, "cosign"
            )

    def test_rejects_manifest_for_an_unapproved_release(self) -> None:
        with self.assertRaisesRegex(VerificationError, "does not match expected v1.2.4"):
            verify_release(
                self.manifest, "example/repo", "v1.2.4", "a" * 40, "cosign"
            )

    def test_rejects_manifest_for_an_unapproved_commit(self) -> None:
        with self.assertRaisesRegex(VerificationError, "does not match expected"):
            verify_release(
                self.manifest, "example/repo", "v1.2.3-rc.1", "b" * 40, "cosign"
            )

    def test_rejects_invalid_repository_policy(self) -> None:
        with self.assertRaisesRegex(VerificationError, "owner/name"):
            expected_certificate_identity("not-a-repository", "v1.2.3")

    def test_rejects_invalid_expected_tag_before_verification(self) -> None:
        with self.assertRaisesRegex(VerificationError, "exact SemVer"):
            verify_release(
                self.manifest, "example/repo", "latest", "a" * 40, "cosign"
            )

    def test_rejects_invalid_expected_commit_before_verification(self) -> None:
        with self.assertRaisesRegex(VerificationError, "40-character"):
            verify_release(
                self.manifest, "example/repo", "v1.2.3-rc.1", "main", "cosign"
            )


if __name__ == "__main__":
    unittest.main()
