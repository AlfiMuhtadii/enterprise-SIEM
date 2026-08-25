#!/usr/bin/env python3
"""Fail-closed verification of signed container release manifests."""

from __future__ import annotations

import argparse
import json
import re
import shutil
import subprocess
from pathlib import Path
from typing import Any, Callable

if __package__:
    from .xdr_release_manifest import COMMIT, SEMVER_TAG, ManifestError, validate_manifest
else:
    from xdr_release_manifest import COMMIT, SEMVER_TAG, ManifestError, validate_manifest


OIDC_ISSUER = "https://token.actions.githubusercontent.com"
REPOSITORY = re.compile(r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$")
CommandRunner = Callable[..., subprocess.CompletedProcess[str]]


class VerificationError(ValueError):
    """Raised when verifier policy or local tooling is invalid."""


def expected_certificate_identity(repository: str, release_tag: str) -> str:
    if REPOSITORY.fullmatch(repository) is None:
        raise VerificationError("repository must use the owner/name format")
    if SEMVER_TAG.fullmatch(release_tag) is None:
        raise VerificationError("release-tag must be an exact SemVer tag")
    return (
        f"https://github.com/{repository}/.github/workflows/"
        f"release.yml@refs/tags/{release_tag}"
    )


def resolve_cosign(executable: str) -> str:
    resolved = shutil.which(executable)
    if resolved is None:
        raise VerificationError(f"Cosign executable not found: {executable}")
    return resolved


def verify_release(
    manifest: dict[str, Any],
    repository: str,
    release_tag: str,
    commit: str,
    cosign_path: str,
    *,
    timeout_seconds: int = 60,
    run_command: CommandRunner = subprocess.run,
) -> dict[str, Any]:
    normalized = validate_manifest(manifest)
    release = normalized["release"]
    identity = expected_certificate_identity(repository, release_tag)
    if COMMIT.fullmatch(commit) is None:
        raise VerificationError("commit must be a 40-character lowercase hexadecimal SHA")
    if release["tag"] != release_tag:
        raise VerificationError(
            f"manifest release tag {release['tag']} does not match expected {release_tag}"
        )
    if release["commit"] != commit:
        raise VerificationError(
            f"manifest commit {release['commit']} does not match expected {commit}"
        )
    images = normalized["images"]
    for image in images:
        signature = image["signature"]
        if signature["certificate_identity"] != identity:
            raise VerificationError(
                f"untrusted certificate identity for {image['service']}: "
                f"{signature['certificate_identity']}"
            )
        if signature["oidc_issuer"] != OIDC_ISSUER:
            raise VerificationError(
                f"untrusted OIDC issuer for {image['service']}: {signature['oidc_issuer']}"
            )

    results = []
    for index, image in enumerate(images):
        reference = f"{image['image']}@{image['digest']}"
        command = [
            cosign_path,
            "verify",
            "--certificate-identity",
            identity,
            "--certificate-oidc-issuer",
            OIDC_ISSUER,
            "--certificate-github-workflow-sha",
            commit,
            "--certificate-github-workflow-repository",
            repository,
            "--certificate-github-workflow-ref",
            f"refs/tags/{release_tag}",
            reference,
        ]
        try:
            completed = run_command(
                command,
                capture_output=True,
                text=True,
                timeout=timeout_seconds,
                check=False,
            )
            verified = completed.returncode == 0
            error = "" if verified else (completed.stderr or completed.stdout).strip()[-2000:]
        except subprocess.TimeoutExpired:
            verified = False
            error = f"verification timed out after {timeout_seconds} seconds"

        results.append(
            {
                "service": image["service"],
                "reference": reference,
                "verified": verified,
                "error": error,
            }
        )
        if error.startswith("verification timed out"):
            results.extend(
                {
                    "service": remaining["service"],
                    "reference": f"{remaining['image']}@{remaining['digest']}",
                    "verified": False,
                    "error": "verification skipped after an earlier timeout",
                }
                for remaining in images[index + 1 :]
            )
            break

    passed = all(result["verified"] for result in results)
    return {
        "schema_version": 1,
        "status": "PASS" if passed else "FAIL",
        "release": release,
        "policy": {
            "certificate_identity": identity,
            "oidc_issuer": OIDC_ISSUER,
            "digest_pinned": True,
        },
        "images": results,
    }


def _write_report(path: Path, report: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--manifest", required=True, type=Path)
    parser.add_argument("--repository", required=True, help="Trusted GitHub owner/repository")
    parser.add_argument("--release-tag", required=True, help="Approved SemVer release tag")
    parser.add_argument("--commit", required=True, help="Approved 40-character release commit")
    parser.add_argument("--cosign", default="cosign", help="Cosign executable name or path")
    parser.add_argument("--timeout-seconds", type=int, default=60)
    parser.add_argument("--output", required=True, type=Path)
    return parser


def main() -> int:
    args = _parser().parse_args()
    report: dict[str, Any]
    try:
        if args.timeout_seconds < 1 or args.timeout_seconds > 600:
            raise VerificationError("timeout-seconds must be between 1 and 600")
        manifest = json.loads(args.manifest.read_text(encoding="utf-8"))
        if not isinstance(manifest, dict):
            raise ManifestError("manifest root must be an object")
        report = verify_release(
            manifest,
            args.repository,
            args.release_tag,
            args.commit,
            resolve_cosign(args.cosign),
            timeout_seconds=args.timeout_seconds,
        )
    except (ManifestError, VerificationError, json.JSONDecodeError, OSError) as exc:
        report = {"schema_version": 1, "status": "ERROR", "error": str(exc)}

    _write_report(args.output, report)
    print(f"status={report['status']} output={args.output}")
    return 0 if report["status"] == "PASS" else 1


if __name__ == "__main__":
    raise SystemExit(main())
