import importlib.util
import json
import os
import ssl
import tempfile
import unittest
from pathlib import Path
from unittest import mock


SCRIPT_PATH = Path(__file__).resolve().parents[2] / "scripts" / "stream_producer_jsonl.py"
SPEC = importlib.util.spec_from_file_location("stream_producer_jsonl", SCRIPT_PATH)
assert SPEC and SPEC.loader
producer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(producer)


class Response:
    def __init__(self, status=200, body=b'{"offsets":[]}'):
        self.status = status
        self.body = body

    def __enter__(self):
        return self

    def __exit__(self, *_args):
        return False

    def read(self):
        return self.body


class StreamProducerJsonlTest(unittest.TestCase):
    def test_parse_args_preserves_plaintext_defaults(self):
        with mock.patch.dict(os.environ, {}, clear=True):
            args = producer.parse_args([])

        self.assertEqual("http://127.0.0.1:8082", args.rest_url)
        self.assertIsNone(args.tls_ca)

    def test_build_tls_context_returns_none_without_ca(self):
        self.assertIsNone(producer.build_tls_context("https://redpanda:8083", None))

    def test_build_tls_context_rejects_ca_for_plaintext_url(self):
        with self.assertRaisesRegex(ValueError, "requires an HTTPS"):
            producer.build_tls_context("http://redpanda:8082", "ca.crt")

    @mock.patch.object(producer.ssl, "create_default_context")
    def test_build_tls_context_loads_private_ca(self, create_default_context):
        expected = mock.sentinel.context
        create_default_context.return_value = expected

        actual = producer.build_tls_context("https://redpanda:8083", "internal-ca.crt")

        self.assertIs(expected, actual)
        create_default_context.assert_called_once_with(cafile="internal-ca.crt")

    @mock.patch.object(producer.urllib.request, "urlopen")
    def test_post_record_sends_json_with_tls_context(self, urlopen):
        urlopen.return_value = Response(body=b'{"offsets":[{"partition":0,"offset":7}]}')
        context = ssl.create_default_context()

        result = producer.post_record(
            "https://redpanda:8083/",
            "telemetry.raw",
            "event-1",
            {"event_id": "event-1"},
            context,
        )

        request = urlopen.call_args.args[0]
        self.assertEqual("https://redpanda:8083/topics/telemetry.raw", request.full_url)
        self.assertEqual(
            {"records": [{"key": "event-1", "value": {"event_id": "event-1"}}]},
            json.loads(request.data),
        )
        self.assertIs(context, urlopen.call_args.kwargs["context"])
        self.assertEqual(10, urlopen.call_args.kwargs["timeout"])
        self.assertEqual(7, result["offsets"][0]["offset"])

    @mock.patch.object(producer.urllib.request, "urlopen")
    def test_post_record_omits_context_for_plaintext(self, urlopen):
        urlopen.return_value = Response()

        producer.post_record("http://127.0.0.1:8082", "security_events", "", {})

        self.assertNotIn("context", urlopen.call_args.kwargs)

    @mock.patch.object(producer.urllib.request, "urlopen")
    def test_post_record_rejects_non_success_status(self, urlopen):
        urlopen.return_value = Response(status=503)

        with self.assertRaisesRegex(RuntimeError, "status=503"):
            producer.post_record("http://127.0.0.1:8082", "security_events", "", {})

    def test_invalid_tls_configuration_does_not_create_state_file(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / "events.jsonl"
            state = root / "nested" / "offsets.json"
            source.write_text('{"event_id":"event-1"}\n', encoding="utf-8")

            result = producer.main(
                [
                    "--file",
                    str(source),
                    "--state-file",
                    str(state),
                    "--rest-url",
                    "http://127.0.0.1:8082",
                    "--tls-ca",
                    str(root / "ca.crt"),
                ]
            )

            self.assertEqual(2, result)
            self.assertFalse(state.exists())


if __name__ == "__main__":
    unittest.main()
