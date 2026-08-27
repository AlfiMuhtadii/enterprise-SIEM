#!/usr/bin/env python3
"""Fail-closed integrity verification for Detector endpoint-agent packages."""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
import re
import sys
from pathlib import Path, PurePosixPath
from typing import Any

MANIFEST_NAME = "MANIFEST.json"
MANIFEST_CHECKSUM_NAME = "MANIFEST.sha256"
CONTROL_FILES = {MANIFEST_NAME, MANIFEST_CHECKSUM_NAME}
SHA256_PATTERN = re.compile(r"^[0-9a-f]{64}$")
MANIFEST_CHECKSUM_PATTERN = re.compile(r"^([0-9a-f]{64})  MANIFEST\.json\n?$")
PORTABLE_PATH_PATTERN = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._/-]*$")


class PackageVerificationError(RuntimeError):
    pass


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def canonical_manifest_path(value: Any) -> PurePosixPath:
    if not isinstance(value, str) or not value or not PORTABLE_PATH_PATTERN.fullmatch(value):
        raise PackageVerificationError(f"invalid manifest path: {value!r}")
    if "\\" in value:
        raise PackageVerificationError(f"manifest path must use forward slashes: {value}")
    parts = value.split("/")
    if any(part in ("", ".", "..") for part in parts):
        raise PackageVerificationError(f"non-canonical manifest path: {value}")
    path = PurePosixPath(value)
    if path.is_absolute() or path.name in CONTROL_FILES:
        raise PackageVerificationError(f"manifest path is not a payload path: {value}")
    return path


def load_manifest(package_root: Path) -> dict[str, Any]:
    manifest_path = package_root / MANIFEST_NAME
    checksum_path = package_root / MANIFEST_CHECKSUM_NAME
    if not manifest_path.is_file() or not checksum_path.is_file():
        raise PackageVerificationError("MANIFEST.json and MANIFEST.sha256 are required")
    if manifest_path.is_symlink() or checksum_path.is_symlink():
        raise PackageVerificationError("manifest control files must not be symlinks")

    checksum_text = checksum_path.read_text(encoding="ascii")
    checksum_match = MANIFEST_CHECKSUM_PATTERN.fullmatch(checksum_text)
    if checksum_match is None:
        raise PackageVerificationError("MANIFEST.sha256 has an invalid format")
    actual_manifest_hash = sha256(manifest_path)
    if not hmac.compare_digest(checksum_match.group(1), actual_manifest_hash):
        raise PackageVerificationError("MANIFEST.json checksum mismatch")

    payload = json.loads(manifest_path.read_text(encoding="utf-8"))
    if not isinstance(payload, dict):
        raise PackageVerificationError("MANIFEST.json must contain an object")
    if payload.get("schema_version") != 1:
        raise PackageVerificationError("unsupported manifest schema_version")
    if payload.get("platform") not in ("windows", "linux"):
        raise PackageVerificationError("unsupported manifest platform")
    for field in ("package", "version", "environment"):
        if not isinstance(payload.get(field), str) or not payload[field]:
            raise PackageVerificationError(f"manifest {field} must be a non-empty string")
    if not isinstance(payload.get("files"), list) or not payload["files"]:
        raise PackageVerificationError("manifest files must be a non-empty array")
    return payload


def inventory_payload(package_root: Path) -> dict[str, Path]:
    inventory: dict[str, Path] = {}
    for directory, directory_names, file_names in os.walk(package_root, followlinks=False):
        current = Path(directory)
        for name in directory_names:
            if (current / name).is_symlink():
                relative = (current / name).relative_to(package_root).as_posix()
                raise PackageVerificationError(f"symlink is not allowed: {relative}")
        for name in file_names:
            path = current / name
            relative = path.relative_to(package_root).as_posix()
            if path.is_symlink():
                raise PackageVerificationError(f"symlink is not allowed: {relative}")
            if relative not in CONTROL_FILES:
                inventory[relative] = path
    return inventory


def verify_package(package_path: Path) -> dict[str, Any]:
    package_root = package_path.resolve(strict=True)
    if not package_root.is_dir():
        raise PackageVerificationError(f"package path is not a directory: {package_root}")

    manifest = load_manifest(package_root)
    expected: dict[str, dict[str, Any]] = {}
    casefold_paths: set[str] = set()
    for entry in manifest["files"]:
        if not isinstance(entry, dict):
            raise PackageVerificationError("manifest file entries must be objects")
        relative = canonical_manifest_path(entry.get("path")).as_posix()
        casefold_path = relative.casefold()
        if relative in expected or casefold_path in casefold_paths:
            raise PackageVerificationError(f"duplicate or case-colliding path: {relative}")
        digest = entry.get("sha256")
        size = entry.get("bytes")
        if not isinstance(digest, str) or not SHA256_PATTERN.fullmatch(digest):
            raise PackageVerificationError(f"invalid SHA-256 for {relative}")
        if isinstance(size, bool) or not isinstance(size, int) or size < 0:
            raise PackageVerificationError(f"invalid byte count for {relative}")
        expected[relative] = {"sha256": digest, "bytes": size}
        casefold_paths.add(casefold_path)

    actual = inventory_payload(package_root)
    missing = sorted(set(expected) - set(actual))
    unexpected = sorted(set(actual) - set(expected))
    if missing:
        raise PackageVerificationError("missing payload files: " + ", ".join(missing))
    if unexpected:
        raise PackageVerificationError("unexpected payload files: " + ", ".join(unexpected))

    total_bytes = 0
    for relative, metadata in expected.items():
        path = actual[relative]
        actual_size = path.stat().st_size
        if actual_size != metadata["bytes"]:
            raise PackageVerificationError(f"size mismatch: {relative}")
        if not hmac.compare_digest(sha256(path), metadata["sha256"]):
            raise PackageVerificationError(f"SHA-256 mismatch: {relative}")
        total_bytes += actual_size

    return {
        "status": "PASS",
        "package": manifest["package"],
        "version": manifest["version"],
        "platform": manifest["platform"],
        "environment": manifest["environment"],
        "files_verified": len(expected),
        "bytes_verified": total_bytes,
        "manifest_sha256": sha256(package_root / MANIFEST_NAME),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--package", default=".", help="extracted agent package directory")
    parser.add_argument("--output", help="optional JSON evidence path")
    args = parser.parse_args()

    try:
        report = verify_package(Path(args.package))
    except (OSError, json.JSONDecodeError, UnicodeError, PackageVerificationError) as exc:
        print(f"status=ERROR detail={exc}", file=sys.stderr)
        return 1

    if args.output:
        output = Path(args.output)
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(
        f"status=PASS package={report['package']} platform={report['platform']} "
        f"files={report['files_verified']} bytes={report['bytes_verified']}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
