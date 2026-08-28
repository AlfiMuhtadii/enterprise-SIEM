"""PERF-DB-CONN-LEAK: bounded psycopg_pool connection pooling.

Previously every write path called connect_pg(), opening a brand-new
TCP+auth connection per batch and relying on psycopg3's `with conn:`
context manager to close it on exit -- correct (no leak), but a fresh
connection round trip per call does not scale under sustained throughput.

A hand-rolled module-level shared connection would be thread-unsafe: this
service's FastAPI HTTP handlers run concurrently with the consumer event
loop, and a single psycopg3 connection is not safe for concurrent use.
psycopg_pool.ConnectionPool solves exactly this -- it is thread-safe by
design (internal locking, bounded checkout/return) -- so this module wraps
one lazily-created, bounded pool per process.
"""
from __future__ import annotations

import os
import threading
from typing import Optional

try:
    import psycopg
    from psycopg_pool import ConnectionPool
except Exception:  # pragma: no cover - exercised via psycopg-absent tests
    psycopg = None  # type: ignore
    ConnectionPool = None  # type: ignore

_pool: Optional["ConnectionPool"] = None
_pool_lock = threading.Lock()


def conninfo() -> Optional[str]:
    """Build a psycopg conninfo string from the same env vars connect_pg()
    used to read directly. Returns None when no DB config is present."""
    dsn = os.getenv("SECURITY_INGEST_DSN") or os.getenv("DATABASE_URL") or ""
    if dsn:
        return dsn
    if psycopg is None:
        return None
    host = os.getenv("DB_HOST", "")
    database = os.getenv("DB_DATABASE", "")
    user = os.getenv("DB_USERNAME", "")
    if not host or not database or not user:
        return None
    parameters = {
        "host": host,
        "port": int(os.getenv("DB_PORT", "5432")),
        "dbname": database,
        "user": user,
        "password": os.getenv("DB_PASSWORD", ""),
    }
    for env_name, parameter in (
        ("DB_SSLMODE", "sslmode"),
        ("DB_SSLROOTCERT", "sslrootcert"),
        ("DB_SSLCERT", "sslcert"),
        ("DB_SSLKEY", "sslkey"),
    ):
        value = os.getenv(env_name, "")
        if value:
            parameters[parameter] = value
    return psycopg.conninfo.make_conninfo(**parameters)


def get_pool() -> Optional["ConnectionPool"]:
    """Lazily create the bounded pool on first real use.

    Returns None when psycopg/psycopg_pool isn't installed or no DB config
    is present -- callers must treat that exactly like connect_pg() used to
    returning None (no database configured / dry-run)."""
    global _pool
    if psycopg is None or ConnectionPool is None:
        return None
    info = conninfo()
    if info is None:
        return None
    with _pool_lock:
        if _pool is None:
            _pool = ConnectionPool(
                info,
                min_size=int(os.getenv("DB_POOL_MIN_SIZE", "1")),
                max_size=int(os.getenv("DB_POOL_MAX_SIZE", "5")),
                timeout=float(os.getenv("DB_POOL_TIMEOUT_SECONDS", "5")),
                open=True,
            )
    return _pool


def reset_pool_for_tests() -> None:
    """Test-only: drop the cached pool so the next get_pool() call builds a
    fresh one (e.g. against a mocked conninfo)."""
    global _pool
    with _pool_lock:
        if _pool is not None:
            _pool.close()
        _pool = None
