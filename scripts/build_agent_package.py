#!/usr/bin/env python3
"""
Build a lightweight endpoint agent deployment package for Windows or Linux.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import shutil
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
    parser.add_argument("--ingestion-gateway-url", default="http://127.0.0.1:8091")
    parser.add_argument("--ingestion-gateway-secret", default="dev-secret-change-me")
    parser.add_argument("--soc-api-url", default="http://127.0.0.1:8000")
    parser.add_argument("--enrollment-token", default="")
    parser.add_argument("--version", default="0.2.0")
    parser.add_argument("--output", default="dist/agent-package")
    args = parser.parse_args()

    root = Path(__file__).resolve().parents[1]
    out = root / args.output / f"detector-agent-{args.platform}-{args.env}-{args.version}"
    if out.exists():
        shutil.rmtree(out)
    out.mkdir(parents=True, exist_ok=True)

    copy(root / "services" / "endpoint-agent" / "agent.py", out / "agent.py")
    copy(root / "scripts" / "verify_agent_package.py", out / "verify_agent_package.py")
    if args.platform == "windows":
        copy(root / "deploy" / "agent" / "windows" / "install-agent-service.ps1", out / "install-agent-service.ps1")
        copy(root / "deploy" / "agent" / "windows" / "uninstall-agent-service.ps1", out / "uninstall-agent-service.ps1")
    else:
        copy(root / "deploy" / "agent" / "linux" / "install-agent-service.sh", out / "install-agent-service.sh")
        copy(root / "deploy" / "agent" / "linux" / "detector-endpoint-agent.service", out / "detector-endpoint-agent.service")

    # config.json is the file agent.py actually reads at runtime (--config), unlike the
    # retired scripts/endpoint_telemetry_agent.py which took everything as CLI flags baked
    # into the service unit file. Only fields that differ from agent.py's own DEFAULT_CONFIG
    # need to be present here — load_config() merges this over the built-in defaults.
    config = {
        "ingestion_gateway_url": args.ingestion_gateway_url,
        "ingestion_gateway_secret": args.ingestion_gateway_secret,
        "soc_api_url": args.soc_api_url,
        "enrollment_token": args.enrollment_token,
        "state_path": "state.json" if args.platform == "windows" else "/var/lib/xdr-agent/state.json",
        "buffer_path": "buffer.jsonl" if args.platform == "windows" else "/var/lib/xdr-agent/buffer.jsonl",
    }
    (out / "config.json").write_text(json.dumps(config, indent=2), encoding="utf-8")
    (out / "README_DEPLOY.md").write_text(
        "# Detector Endpoint Agent Package\n\n"
        "Verify the extracted package before installation or execution:\n\n"
        "```bash\npython verify_agent_package.py --package .\n```\n\n"
        "This detects corruption and unexpected files but does not authenticate the "
        "publisher; external artifact signing remains required for production distribution.\n\n"
        "Run the agent manually:\n\n"
        "```bash\npython agent.py --config config.json\n```\n\n"
        "Run one collection cycle only (smoke test):\n\n"
        "```bash\npython agent.py --config config.json --once\n```\n\n"
        "Install service files from this package according to platform. "
        "Edit config.json first — ingestion_gateway_secret in particular must match the "
        "target ingestion-gateway's XDR_INGEST_SECRET.\n"
        "Generated with: ingestion_gateway_url={gw}, soc_api_url={soc}\n".format(
            gw=args.ingestion_gateway_url, soc=args.soc_api_url,
        ),
        encoding="utf-8",
    )

    files = []
    for path in sorted(p for p in out.rglob("*") if p.is_file()):
        files.append({"path": path.relative_to(out).as_posix(), "sha256": sha256(path), "bytes": path.stat().st_size})
    manifest = {
        "schema_version": 1,
        "package": out.name,
        "version": args.version,
        "platform": args.platform,
        "environment": args.env,
        "files": files,
    }
    manifest_path = out / "MANIFEST.json"
    manifest_path.write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    (out / "MANIFEST.sha256").write_text(
        f"{sha256(manifest_path)}  MANIFEST.json\n",
        encoding="ascii",
    )
    archive = shutil.make_archive(str(out), "zip", out)
    print(f"package={out}")
    print(f"archive={archive}")
    print(f"sha256={sha256(Path(archive))}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
