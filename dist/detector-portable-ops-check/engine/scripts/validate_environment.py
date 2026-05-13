#!/usr/bin/env python3
"""
Validate local, staging, and production environment files before deployment.
"""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path
from typing import Dict, List, Tuple


BOOL_FALSE = {"false", "0", "no", "off"}
BOOL_TRUE = {"true", "1", "yes", "on"}


def parse_env(path: Path) -> Dict[str, str]:
    data: Dict[str, str] = {}
    if not path.exists():
        raise FileNotFoundError(path)
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        value = value.strip().strip('"').strip("'")
        data[key.strip()] = value
    return data


def is_false(value: str) -> bool:
    return value.lower() in BOOL_FALSE


def is_true(value: str) -> bool:
    return value.lower() in BOOL_TRUE


def require(env: Dict[str, str], key: str, errors: List[str]) -> str:
    value = env.get(key, "").strip()
    if not value:
        errors.append(f"{key} is required")
    return value


def validate(profile: str, env: Dict[str, str], allow_placeholders: bool = False) -> Tuple[List[str], List[str]]:
    errors: List[str] = []
    warnings: List[str] = []

    require(env, "APP_ENV", errors)
    require(env, "APP_URL", errors)
    require(env, "DB_CONNECTION", errors)
    require(env, "DB_HOST", errors)
    require(env, "DB_DATABASE", errors)
    require(env, "DB_USERNAME", errors)

    if env.get("DB_CONNECTION") != "pgsql":
        warnings.append("DB_CONNECTION should be pgsql for the production SOC schema.")

    if profile == "local":
        if env.get("QUEUE_CONNECTION") not in {"sync", "database", "redis"}:
            warnings.append("QUEUE_CONNECTION should be sync, database, or redis.")
        return errors, warnings

    if profile in {"staging", "production"}:
        if env.get("APP_ENV") != profile:
            errors.append(f"APP_ENV must be {profile}")
        if not is_false(env.get("APP_DEBUG", "")):
            errors.append("APP_DEBUG must be false")
        if env.get("QUEUE_CONNECTION") == "sync":
            errors.append("QUEUE_CONNECTION must not be sync outside local")
        if not env.get("APP_KEY") or (not allow_placeholders and ("CHANGE_ME" in env.get("APP_KEY", "") or "REPLACE_" in env.get("APP_KEY", ""))):
            errors.append("APP_KEY must be generated and non-placeholder")
        if not env.get("SOC_WEBHOOK_SECRET") or (not allow_placeholders and env.get("SOC_WEBHOOK_SECRET") in {"change-me", "REPLACE_WITH_STRONG_WEBHOOK_SECRET"}):
            errors.append("SOC_WEBHOOK_SECRET must be set to a strong secret")
        if int(env.get("SOC_EXPORT_MAX_ROWS", "0") or "0") > 5000:
            warnings.append("SOC_EXPORT_MAX_ROWS is high; confirm export access controls and memory limits.")

    if profile == "production":
        if not is_true(env.get("SESSION_SECURE_COOKIE", "")):
            errors.append("SESSION_SECURE_COOKIE must be true in production")
        if not is_true(env.get("APP_FORCE_HTTPS", "")):
            errors.append("APP_FORCE_HTTPS must be true in production")
        if env.get("DB_PASSWORD") in {"", "postgres", "password", "secret"} or (not allow_placeholders and env.get("DB_PASSWORD") == "REPLACE_WITH_STRONG_PASSWORD"):
            errors.append("DB_PASSWORD must not use a default password in production")
        if re.match(r"^http://", env.get("APP_URL", "")):
            errors.append("APP_URL must use https in production")

    return errors, warnings


def main() -> int:
    parser = argparse.ArgumentParser(description="Validate Detector environment profile")
    parser.add_argument("--profile", required=True, choices=["local", "staging", "production"])
    parser.add_argument("--env-file", default=".env")
    parser.add_argument("--json-output", default="")
    parser.add_argument("--allow-placeholders", action="store_true", help="Allow template placeholders in example files")
    args = parser.parse_args()

    env = parse_env(Path(args.env_file))
    errors, warnings = validate(args.profile, env, args.allow_placeholders)
    result = {
        "profile": args.profile,
        "env_file": args.env_file,
        "ok": not errors,
        "errors": errors,
        "warnings": warnings,
    }
    print(json.dumps(result, indent=2))
    if args.json_output:
        out = Path(args.json_output)
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(json.dumps(result, indent=2), encoding="utf-8")
    return 1 if errors else 0


if __name__ == "__main__":
    raise SystemExit(main())
