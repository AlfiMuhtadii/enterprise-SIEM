#!/usr/bin/env python3
"""Reject mutable or deprecated action references in GitHub workflows."""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path
from typing import Iterable

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW_DIR = ROOT / ".github" / "workflows"
USES_PATTERN = re.compile(r"^\s*(?:-\s*)?uses:\s*([^\s#]+)", re.MULTILINE)
COMMIT_SHA_PATTERN = re.compile(r"^[0-9a-f]{40}$")
DOCKER_DIGEST_PATTERN = re.compile(r"^docker://.+@sha256:[0-9a-f]{64}$")

# These revisions are verified Node 24 action releases. Keeping this list
# explicit makes runtime upgrades reviewable instead of silently following tags.
APPROVED_NODE24_REFS = {
    "actions/cache": "caa296126883cff596d87d8935842f9db880ef25",
    "actions/checkout": "3d3c42e5aac5ba805825da76410c181273ba90b1",
    "actions/setup-go": "b7ad1dad31e06c5925ef5d2fc7ad053ef454303e",
    "actions/setup-node": "820762786026740c76f36085b0efc47a31fe5020",
    "actions/setup-python": "5fda3b95a4ea91299a34e894583c3862153e4b97",
    "actions/upload-artifact": "043fb46d1a93c77aae656e7c1c64a875d1fc6a0a",
    "github/codeql-action": "cdf488f595d80d6e07e03d4674febd5ab45fa938",
    "shivammathur/setup-php": "f3e473d116dcccaddc5834248c87452386958240",
}


def validate_workflow(path: Path, text: str) -> tuple[list[str], int, int]:
    errors: list[str] = []
    action_count = 0
    pinned_count = 0

    for match in USES_PATTERN.finditer(text):
        value = match.group(1)
        line = text.count("\n", 0, match.start()) + 1
        location = f"{path.as_posix()}:{line}"

        if value.startswith("./"):
            continue

        action_count += 1
        if value.startswith("docker://"):
            if DOCKER_DIGEST_PATTERN.fullmatch(value):
                pinned_count += 1
            else:
                errors.append(f"{location}: container action must use a sha256 digest: {value}")
            continue

        if "@" not in value:
            errors.append(f"{location}: action reference has no revision: {value}")
            continue

        action, revision = value.rsplit("@", 1)
        if not COMMIT_SHA_PATTERN.fullmatch(revision):
            errors.append(f"{location}: action must be pinned to a 40-character commit SHA: {value}")
            continue

        pinned_count += 1
        parts = action.split("/")
        repository = "/".join(parts[:2]) if len(parts) >= 2 else action
        approved = APPROVED_NODE24_REFS.get(repository)
        if approved is not None and revision != approved:
            errors.append(
                f"{location}: {repository} must use approved Node 24 revision {approved}, got {revision}"
            )

    return errors, action_count, pinned_count


def validate_paths(paths: Iterable[Path]) -> tuple[list[str], int, int]:
    errors: list[str] = []
    action_count = 0
    pinned_count = 0
    for path in paths:
        try:
            text = path.read_text(encoding="utf-8")
        except OSError as exc:
            errors.append(f"{path.as_posix()}: unable to read workflow: {exc}")
            continue
        workflow_errors, workflow_actions, workflow_pinned = validate_workflow(path, text)
        errors.extend(workflow_errors)
        action_count += workflow_actions
        pinned_count += workflow_pinned
    return errors, action_count, pinned_count


def workflow_paths(root: Path = WORKFLOW_DIR) -> list[Path]:
    return sorted([*root.glob("*.yml"), *root.glob("*.yaml")])


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", help="optional JSON evidence path")
    args = parser.parse_args()

    paths = workflow_paths()
    errors, action_count, pinned_count = validate_paths(paths)
    report = {
        "status": "PASS" if not errors else "FAIL",
        "workflow_count": len(paths),
        "remote_action_count": action_count,
        "pinned_action_count": pinned_count,
        "errors": errors,
    }
    if args.output:
        output = Path(args.output)
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, indent=2))
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
