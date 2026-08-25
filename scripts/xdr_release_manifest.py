#!/usr/bin/env python3
"""Create and validate immutable container release evidence manifests."""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path
from typing import Any, Iterable


EXPECTED_SERVICES = frozenset(
    {
        "app",
        "telemetry-worker",
        "ingestion-gateway",
        "normalizer-worker",
        "log-connector-syslog",
        "log-connector-cloudtrail",
        "log-connector-guardduty",
        "log-connector-gcp-audit",
        "log-connector-o365",
        "correlation-worker",
        "alert-writer-service",
        "incident-builder-service",
        "ai-rag-service",
    }
)
SEMVER_TAG = re.compile(
    r"^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)"
    r"(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$"
)
DIGEST = re.compile(r"^sha256:[0-9a-f]{64}$")
COMMIT = re.compile(r"^[0-9a-f]{40}$")
IMAGE = re.compile(r"^ghcr\.io/[a-z0-9._-]+/detector-([a-z0-9._-]+)$")


class ManifestError(ValueError):
    """Raised when release evidence violates the delivery contract."""


def _require_text(value: Any, field: str) -> str:
    if not isinstance(value, str) or not value.strip():
        raise ManifestError(f"{field} must be a non-empty string")
    return value


def validate_fragment(fragment: dict[str, Any]) -> dict[str, Any]:
    if fragment.get("schema_version") != 1:
        raise ManifestError("schema_version must be 1")

    service = _require_text(fragment.get("service"), "service")
    if service not in EXPECTED_SERVICES:
        raise ManifestError(f"unexpected service: {service}")

    image = _require_text(fragment.get("image"), "image")
    image_match = IMAGE.fullmatch(image)
    if image_match is None or image_match.group(1) != service:
        raise ManifestError(f"image does not match service {service}: {image}")

    release_tag = _require_text(fragment.get("release_tag"), "release_tag")
    if SEMVER_TAG.fullmatch(release_tag) is None:
        raise ManifestError(f"invalid release_tag: {release_tag}")
    if fragment.get("image_tag") != release_tag[1:]:
        raise ManifestError("image_tag must equal release_tag without the leading v")

    commit = _require_text(fragment.get("commit"), "commit")
    if COMMIT.fullmatch(commit) is None:
        raise ManifestError(f"invalid commit: {commit}")

    digest = _require_text(fragment.get("digest"), "digest")
    if DIGEST.fullmatch(digest) is None:
        raise ManifestError(f"invalid digest: {digest}")

    _require_text(fragment.get("workflow_run_id"), "workflow_run_id")
    signature = fragment.get("signature")
    if not isinstance(signature, dict):
        raise ManifestError("signature must be an object")
    _require_text(signature.get("certificate_identity_regexp"), "signature.certificate_identity_regexp")
    _require_text(signature.get("oidc_issuer"), "signature.oidc_issuer")

    attestation = fragment.get("attestation")
    if not isinstance(attestation, dict):
        raise ManifestError("attestation must be an object")
    _require_text(attestation.get("url"), "attestation.url")
    return fragment


def build_fragment(args: argparse.Namespace) -> dict[str, Any]:
    fragment = {
        "schema_version": 1,
        "service": args.service,
        "image": args.image,
        "release_tag": args.release_tag,
        "image_tag": args.release_tag[1:] if args.release_tag.startswith("v") else "",
        "commit": args.commit,
        "digest": args.digest,
        "workflow_run_id": args.workflow_run_id,
        "signature": {
            "certificate_identity_regexp": args.certificate_identity_regexp,
            "oidc_issuer": args.oidc_issuer,
        },
        "attestation": {"url": args.attestation_url},
    }
    return validate_fragment(fragment)


def aggregate_fragments(fragments: Iterable[dict[str, Any]]) -> dict[str, Any]:
    validated = [validate_fragment(fragment) for fragment in fragments]
    services = [fragment["service"] for fragment in validated]
    if len(services) != len(set(services)):
        raise ManifestError("duplicate service evidence")

    missing = EXPECTED_SERVICES - set(services)
    extra = set(services) - EXPECTED_SERVICES
    if missing or extra:
        raise ManifestError(
            f"service coverage mismatch; missing={sorted(missing)}, extra={sorted(extra)}"
        )

    for field in ("release_tag", "image_tag", "commit", "workflow_run_id"):
        values = {fragment[field] for fragment in validated}
        if len(values) != 1:
            raise ManifestError(f"inconsistent {field}: {sorted(values)}")

    images = [fragment["image"] for fragment in validated]
    if len(images) != len(set(images)):
        raise ManifestError("duplicate image evidence")

    first = validated[0]
    return {
        "schema_version": 1,
        "release": {
            "tag": first["release_tag"],
            "image_tag": first["image_tag"],
            "commit": first["commit"],
            "workflow_run_id": first["workflow_run_id"],
        },
        "images": sorted(validated, key=lambda item: item["service"]),
    }


def _write_json(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def _fragment_command(args: argparse.Namespace) -> None:
    _write_json(args.output, build_fragment(args))


def _aggregate_command(args: argparse.Namespace) -> None:
    paths = sorted(args.input_dir.glob("*.json"))
    if not paths:
        raise ManifestError(f"no evidence fragments found in {args.input_dir}")
    fragments = [json.loads(path.read_text(encoding="utf-8")) for path in paths]
    _write_json(args.output, aggregate_fragments(fragments))


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="command", required=True)

    fragment = subparsers.add_parser("fragment", help="create one service evidence fragment")
    for name in (
        "service",
        "image",
        "release-tag",
        "commit",
        "digest",
        "workflow-run-id",
        "certificate-identity-regexp",
        "oidc-issuer",
        "attestation-url",
    ):
        fragment.add_argument(f"--{name}", required=True)
    fragment.add_argument("--output", required=True, type=Path)
    fragment.set_defaults(handler=_fragment_command)

    aggregate = subparsers.add_parser("aggregate", help="validate and combine all fragments")
    aggregate.add_argument("--input-dir", required=True, type=Path)
    aggregate.add_argument("--output", required=True, type=Path)
    aggregate.set_defaults(handler=_aggregate_command)
    return parser


def main() -> int:
    args = _parser().parse_args()
    try:
        args.handler(args)
    except (ManifestError, json.JSONDecodeError) as exc:
        raise SystemExit(f"release manifest validation failed: {exc}") from exc
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
