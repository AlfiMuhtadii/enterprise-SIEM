import copy
import unittest

from scripts.xdr_release_manifest import (
    EXPECTED_SERVICES,
    ManifestError,
    aggregate_fragments,
    validate_fragment,
)


def evidence(service: str) -> dict:
    index = sorted(EXPECTED_SERVICES).index(service) + 1
    return {
        "schema_version": 1,
        "service": service,
        "image": f"ghcr.io/example/detector-{service}",
        "release_tag": "v1.2.3-rc.1",
        "image_tag": "1.2.3-rc.1",
        "commit": "a" * 40,
        "digest": f"sha256:{index:064x}",
        "workflow_run_id": "12345",
        "signature": {
            "certificate_identity_regexp": "^https://github.com/example/repo/.+$",
            "oidc_issuer": "https://token.actions.githubusercontent.com",
        },
        "attestation": {"url": f"https://github.com/example/repo/attestations/{index}"},
    }


class ReleaseManifestTest(unittest.TestCase):
    def setUp(self) -> None:
        self.fragments = [evidence(service) for service in EXPECTED_SERVICES]

    def test_aggregate_requires_complete_sorted_service_matrix(self) -> None:
        manifest = aggregate_fragments(reversed(self.fragments))

        self.assertEqual(1, manifest["schema_version"])
        self.assertEqual("v1.2.3-rc.1", manifest["release"]["tag"])
        self.assertEqual(
            sorted(EXPECTED_SERVICES),
            [image["service"] for image in manifest["images"]],
        )

    def test_rejects_missing_service(self) -> None:
        with self.assertRaisesRegex(ManifestError, "service coverage mismatch"):
            aggregate_fragments(self.fragments[:-1])

    def test_rejects_duplicate_service(self) -> None:
        duplicate = self.fragments + [copy.deepcopy(self.fragments[0])]
        with self.assertRaisesRegex(ManifestError, "duplicate service evidence"):
            aggregate_fragments(duplicate)

    def test_rejects_mixed_release_commits(self) -> None:
        self.fragments[0]["commit"] = "b" * 40
        with self.assertRaisesRegex(ManifestError, "inconsistent commit"):
            aggregate_fragments(self.fragments)

    def test_allows_identical_content_digest_in_different_repositories(self) -> None:
        self.fragments[1]["digest"] = self.fragments[0]["digest"]

        manifest = aggregate_fragments(self.fragments)

        self.assertEqual(len(EXPECTED_SERVICES), len(manifest["images"]))

    def test_rejects_image_not_matching_service(self) -> None:
        fragment = evidence("app")
        fragment["image"] = "ghcr.io/example/detector-telemetry-worker"
        with self.assertRaisesRegex(ManifestError, "image does not match service"):
            validate_fragment(fragment)

    def test_rejects_non_sha256_digest(self) -> None:
        fragment = evidence("app")
        fragment["digest"] = "latest"
        with self.assertRaisesRegex(ManifestError, "invalid digest"):
            validate_fragment(fragment)


if __name__ == "__main__":
    unittest.main()
