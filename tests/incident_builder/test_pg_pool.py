"""PERF-DB-CONN-LEAK: bounded psycopg_pool connection pooling.

Direct-import tests against pg_pool.py's pure conninfo-building and lazy
pool-construction logic. ConnectionPool itself is mocked throughout -- these
tests verify pg_pool wires the right conninfo/min/max/timeout values and
correctly reuses one pool across calls, not that psycopg_pool itself works."""
from __future__ import annotations

import os
import sys
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

SERVICES_DIR = Path(__file__).parent.parent.parent / "services" / "incident-builder-service"
sys.path.insert(0, str(SERVICES_DIR))

import pg_pool  # noqa: E402


class TestConninfo(unittest.TestCase):
    def setUp(self):
        self._env_backup = dict(os.environ)
        for key in ("SECURITY_INGEST_DSN", "DATABASE_URL", "DB_HOST", "DB_DATABASE",
                    "DB_USERNAME", "DB_PASSWORD", "DB_PORT"):
            os.environ.pop(key, None)

    def tearDown(self):
        os.environ.clear()
        os.environ.update(self._env_backup)

    def test_prefers_explicit_dsn(self):
        os.environ["SECURITY_INGEST_DSN"] = "postgresql://x/y"
        self.assertEqual(pg_pool.conninfo(), "postgresql://x/y")

    def test_falls_back_to_database_url(self):
        os.environ["DATABASE_URL"] = "postgresql://a/b"
        self.assertEqual(pg_pool.conninfo(), "postgresql://a/b")

    def test_none_when_no_config_present(self):
        self.assertIsNone(pg_pool.conninfo())

    def test_none_when_host_config_incomplete(self):
        os.environ["DB_HOST"] = "localhost"
        # missing DB_DATABASE / DB_USERNAME
        self.assertIsNone(pg_pool.conninfo())

    def test_builds_conninfo_from_discrete_host_vars(self):
        os.environ["DB_HOST"] = "localhost"
        os.environ["DB_DATABASE"] = "detector"
        os.environ["DB_USERNAME"] = "postgres"
        os.environ["DB_PASSWORD"] = "secret"
        info = pg_pool.conninfo()
        self.assertIsNotNone(info)
        self.assertIn("detector", info)
        self.assertIn("postgres", info)

    def test_none_when_psycopg_unavailable(self):
        os.environ["DB_HOST"] = "localhost"
        os.environ["DB_DATABASE"] = "detector"
        os.environ["DB_USERNAME"] = "postgres"
        with patch.object(pg_pool, "psycopg", None):
            self.assertIsNone(pg_pool.conninfo())


class TestGetPool(unittest.TestCase):
    def setUp(self):
        pg_pool.reset_pool_for_tests()
        self._env_backup = dict(os.environ)

    def tearDown(self):
        pg_pool.reset_pool_for_tests()
        os.environ.clear()
        os.environ.update(self._env_backup)

    def test_none_when_no_conninfo(self):
        with patch.object(pg_pool, "conninfo", return_value=None):
            self.assertIsNone(pg_pool.get_pool())

    def test_none_when_connectionpool_class_unavailable(self):
        with patch.object(pg_pool, "conninfo", return_value="postgresql://x/y"), \
             patch.object(pg_pool, "ConnectionPool", None):
            self.assertIsNone(pg_pool.get_pool())

    def test_creates_pool_once_and_reuses_it(self):
        fake_pool_cls = MagicMock()
        with patch.object(pg_pool, "conninfo", return_value="postgresql://x/y"), \
             patch.object(pg_pool, "ConnectionPool", fake_pool_cls):
            first = pg_pool.get_pool()
            second = pg_pool.get_pool()
        self.assertIs(first, second)
        fake_pool_cls.assert_called_once()

    def test_bounded_min_max_from_env(self):
        os.environ["DB_POOL_MIN_SIZE"] = "2"
        os.environ["DB_POOL_MAX_SIZE"] = "9"
        os.environ["DB_POOL_TIMEOUT_SECONDS"] = "3.5"
        fake_pool_cls = MagicMock()
        with patch.object(pg_pool, "conninfo", return_value="postgresql://x/y"), \
             patch.object(pg_pool, "ConnectionPool", fake_pool_cls):
            pg_pool.get_pool()
        _, kwargs = fake_pool_cls.call_args
        self.assertEqual(kwargs["min_size"], 2)
        self.assertEqual(kwargs["max_size"], 9)
        self.assertEqual(kwargs["timeout"], 3.5)

    def test_default_bounds_are_sane(self):
        fake_pool_cls = MagicMock()
        with patch.object(pg_pool, "conninfo", return_value="postgresql://x/y"), \
             patch.object(pg_pool, "ConnectionPool", fake_pool_cls):
            pg_pool.get_pool()
        _, kwargs = fake_pool_cls.call_args
        self.assertEqual(kwargs["min_size"], 1)
        self.assertEqual(kwargs["max_size"], 5)
        self.assertGreater(kwargs["max_size"], kwargs["min_size"])

    def test_reset_closes_and_clears_cached_pool(self):
        fake_pool = MagicMock()
        fake_pool_cls = MagicMock(return_value=fake_pool)
        with patch.object(pg_pool, "conninfo", return_value="postgresql://x/y"), \
             patch.object(pg_pool, "ConnectionPool", fake_pool_cls):
            pg_pool.get_pool()
        pg_pool.reset_pool_for_tests()
        fake_pool.close.assert_called_once()
        self.assertIsNone(pg_pool._pool)


if __name__ == "__main__":
    unittest.main(verbosity=2)
