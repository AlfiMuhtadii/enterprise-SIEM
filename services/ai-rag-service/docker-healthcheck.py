#!/usr/bin/env python3
"""ENT-SEC-NO-TLS-INTERNAL: mTLS-aware container healthcheck.

Mirrors docker-entrypoint.sh's XDR_INTERNAL_MTLS_ENABLED toggle. Without
this, the built-in Docker HEALTHCHECK's plain http request would be
rejected by an mTLS-enabled listener (which requires a client cert on
every connection), marking a perfectly healthy container as unhealthy.

build_request() is a pure function (no network I/O) so it can be unit
tested directly; main() does the actual network call.
"""
import os
import ssl
import sys
import urllib.request


def build_request(port: str, env: dict = None):
    """Returns (url, ssl_context_or_None) based on the mTLS env toggle."""
    env = env if env is not None else os.environ
    if env.get("XDR_INTERNAL_MTLS_ENABLED") == "true":
        ctx = ssl.create_default_context(cafile=env["XDR_INTERNAL_MTLS_CA"])
        ctx.load_cert_chain(
            certfile=env["XDR_INTERNAL_MTLS_CLIENT_CERT"],
            keyfile=env["XDR_INTERNAL_MTLS_CLIENT_KEY"],
        )
        return f"https://127.0.0.1:{port}/health", ctx
    return f"http://127.0.0.1:{port}/health", None


def main() -> int:
    port = sys.argv[1]
    url, ctx = build_request(port)
    urllib.request.urlopen(url, timeout=3, context=ctx)
    return 0


if __name__ == "__main__":
    sys.exit(main())
