from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

SCRIPTS_DIR = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS_DIR))
import validate_live_xdr_pipeline as pipeline


class TestLivePipelineAllServiceMutualTls(unittest.TestCase):
    def test_ingestion_only_mode_keeps_legacy_scope(self):
        args = pipeline._parse_args([
            "--mtls-enabled",
            "--mtls-ca", "ca.pem",
            "--mtls-client-cert", "client.pem",
            "--mtls-client-key", "key.pem",
        ])
        context = MagicMock()
        with patch.object(pipeline.ssl, "create_default_context", return_value=context):
            self.assertIs(
                pipeline.build_ingestion_mtls_context(args, "https://gateway"),
                context,
            )
        pipeline.validate_all_services_mtls(args, {"normalizer": "http://normalizer"})

    def test_all_service_mode_requires_https_for_each_service(self):
        args = pipeline._parse_args(["--all-services-mtls-enabled"])
        with self.assertRaisesRegex(ValueError, "normalizer"):
            pipeline.validate_all_services_mtls(
                args,
                {"normalizer": "http://normalizer", "correlation": "https://correlation"},
            )

    def test_worker_identity_is_not_sent_to_redpanda_admin(self):
        service_get = MagicMock(return_value=(200, '{"processed":1}'))
        with patch.object(pipeline, "http_get", return_value=(200, "")) as broker_get:
            pipeline.check_worker_processing_movement(
                "normalizer",
                "https://normalizer",
                "telemetry.raw",
                "http://redpanda:9644",
                3,
                _worker_http_get_fn=service_get,
            )
        service_get.assert_called_once_with("https://normalizer/metrics", 3)
        broker_get.assert_called_once_with(
            "http://redpanda:9644/public_metrics", 3, max_bytes=131072
        )

    def test_auth_posture_helpers_use_injected_service_transport(self):
        service_get = MagicMock(
            return_value=(200, '{"internal_auth_mode":"enforced"}')
        )
        env = {
            "XDR_ENFORCE_INTERNAL_AUTH": "true",
            "XDR_NORMALIZER_INTERNAL_TOKEN": "token",
            "XDR_INTERNAL_AUTH_SECRET": "secret",
            "XDR_ALERT_WRITER_INTERNAL_TOKEN": "token",
        }
        pipeline.check_internal_auth_posture(
            "https://normalizer", env, 3, _http_get_fn=service_get
        )
        pipeline.check_internal_auth_posture_service(
            "alert-writer", "https://alert-writer",
            "XDR_ALERT_WRITER_INTERNAL_TOKEN", env, 3,
            _http_get_fn=service_get,
        )
        self.assertEqual(
            [call.args[0] for call in service_get.call_args_list],
            ["https://normalizer/metrics", "https://alert-writer/metrics"],
        )

    def test_invalid_all_service_config_stops_before_health_checks(self):
        args = pipeline._parse_args(["--all-services-mtls-enabled"])
        with patch.object(pipeline, "check_service_health") as health:
            self.assertEqual(pipeline.main(args), 2)
        health.assert_not_called()

    def test_main_scopes_context_to_first_party_urls(self):
        context = MagicMock(name="internal_context")
        with tempfile.TemporaryDirectory() as tmp:
            env_path = Path(tmp) / ".env"
            env_path.write_text(
                "\n".join([
                    "XDR_INGEST_ADDR=https://gateway:8091",
                    "XDR_NORMALIZER_ADDR=https://normalizer:8092",
                    "XDR_CORRELATION_WORKER_URL=https://correlation:8093",
                    "XDR_ALERT_WRITER_URL=https://alert-writer:8095",
                    "XDR_INCIDENT_BUILDER_URL=https://incident-builder:8096",
                    "XDR_REDPANDA_REST_URL=http://redpanda:8082",
                ]),
                encoding="utf-8",
            )
            args = pipeline._parse_args([
                "--env", str(env_path),
                "--all-services-mtls-enabled",
                "--mtls-ca", "ca.pem",
                "--mtls-client-cert", "client.pem",
                "--mtls-client-key", "key.pem",
            ])
            with patch.object(
                pipeline, "build_ingestion_mtls_context", return_value=context
            ), patch.object(
                pipeline, "http_get", return_value=(200, "{}")
            ) as http_get, patch("builtins.print"):
                pipeline.main(args)

        first_party_hosts = (
            "gateway", "normalizer", "correlation", "alert-writer", "incident-builder"
        )
        first_party_calls = [
            call for call in http_get.call_args_list
            if any(host in call.args[0] for host in first_party_hosts)
        ]
        redpanda_calls = [
            call for call in http_get.call_args_list
            if "redpanda" in call.args[0] or "127.0.0.1:9644" in call.args[0]
        ]
        self.assertTrue(first_party_calls)
        self.assertTrue(redpanda_calls)
        self.assertTrue(
            all(call.kwargs.get("ssl_context") is context for call in first_party_calls)
        )
        self.assertTrue(
            all(call.kwargs.get("ssl_context") is None for call in redpanda_calls)
        )


if __name__ == "__main__":
    unittest.main()
