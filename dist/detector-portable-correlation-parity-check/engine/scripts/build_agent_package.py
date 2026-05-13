#!/usr/bin/env python3
"""
Build a lightweight endpoint agent deployment package for Windows or Linux.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import shutil
import time
from pathlib import Path


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def copy(src: Path, dst: Path) -> None:
    dst.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(src, dst)


def main() -> int:
    parser = argparse.ArgumentParser(description="Build endpoint agent package")
    parser.add_argument("--platform", required=True, choices=["windows", "linux"])
    parser.add_argument("--env", default="local", choices=["local", "staging", "production"])
    parser.add_argument("--server-url", default="http://127.0.0.1:8000")
    parser.add_argument("--enrollment-token", default="")
    parser.add_argument("--version", default="0.2.0")
    parser.add_argument("--output", default="dist/agent-package")
    args = parser.parse_args()

    root = Path(__file__).resolve().parents[1]
    out = root / args.output / f"detector-agent-{args.platform}-{args.env}-{args.version}"
    if out.exists():
        shutil.rmtree(out)
    out.mkdir(parents=True, exist_ok=True)

    copy(root / "scripts" / "endpoint_telemetry_agent.py", out / "endpoint_telemetry_agent.py")
    if args.platform == "windows":
        copy(root / "deploy" / "agent" / "windows" / "install-agent-service.ps1", out / "install-agent-service.ps1")
        copy(root / "deploy" / "agent" / "windows" / "uninstall-agent-service.ps1", out / "uninstall-agent-service.ps1")
    else:
        copy(root / "deploy" / "agent" / "linux" / "detector-endpoint-agent.service", out / "detector-endpoint-agent.service")

    config = {
        "platform": args.platform,
        "environment": args.env,
        "server_url": args.server_url,
        "enrollment_token": args.enrollment_token,
        "version": args.version,
        "default_interval_seconds": 60,
        "stream_mode": True,
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
    }
    (out / "agent-config.json").write_text(json.dumps(config, indent=2), encoding="utf-8")
    (out / "README_DEPLOY.md").write_text(
        "# Detector Endpoint Agent Package\n\n"
        "Run the agent manually:\n\n"
        "```bash\npython endpoint_telemetry_agent.py --daemon --stream --server-url {server} --enrollment-token {token}\n```\n\n"
        "Install service files from this package according to platform.\n".format(server=args.server_url, token=args.enrollment_token or "<token>"),
        encoding="utf-8",
    )

    files = []
    for path in sorted(p for p in out.rglob("*") if p.is_file()):
        files.append({"path": str(path.relative_to(out)), "sha256": sha256(path), "bytes": path.stat().st_size})
    manifest = {
        "package": out.name,
        "version": args.version,
        "platform": args.platform,
        "environment": args.env,
        "files": files,
    }
    (out / "MANIFEST.json").write_text(json.dumps(manifest, indent=2), encoding="utf-8")
    archive = shutil.make_archive(str(out), "zip", out)
    print(f"package={out}")
    print(f"archive={archive}")
    print(f"sha256={sha256(Path(archive))}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
