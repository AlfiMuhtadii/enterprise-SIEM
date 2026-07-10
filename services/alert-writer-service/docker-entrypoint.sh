#!/bin/sh
# ENT-SEC-NO-TLS-INTERNAL: internal mTLS entrypoint wrapper for uvicorn.
#
# Disabled by default (XDR_INTERNAL_MTLS_ENABLED unset or not "true") ->
# behavior is identical to the previous static
# `uvicorn main:app --host 0.0.0.0 --port <port>` Dockerfile CMD. When
# enabled, adds uvicorn's --ssl-* flags to require and verify a client
# certificate on every inbound connection (mutual TLS) - the same
# mechanism already proven on the Go pipeline services
# (services/*/internal/mtls); see
# scripts/xdr_generate_internal_mtls_certs.py to generate dev/test certs.
#
# Usage: docker-entrypoint.sh <port>
set -eu

port="${1:?usage: docker-entrypoint.sh <port>}"

cmd="python -m uvicorn main:app --host 0.0.0.0 --port ${port}"

if [ "${XDR_INTERNAL_MTLS_ENABLED:-false}" = "true" ]; then
    : "${XDR_INTERNAL_MTLS_SERVER_CERT:?XDR_INTERNAL_MTLS_SERVER_CERT is required when XDR_INTERNAL_MTLS_ENABLED=true}"
    : "${XDR_INTERNAL_MTLS_SERVER_KEY:?XDR_INTERNAL_MTLS_SERVER_KEY is required when XDR_INTERNAL_MTLS_ENABLED=true}"
    : "${XDR_INTERNAL_MTLS_CA:?XDR_INTERNAL_MTLS_CA is required when XDR_INTERNAL_MTLS_ENABLED=true}"
    cmd="${cmd} --ssl-certfile ${XDR_INTERNAL_MTLS_SERVER_CERT} --ssl-keyfile ${XDR_INTERNAL_MTLS_SERVER_KEY} --ssl-ca-certs ${XDR_INTERNAL_MTLS_CA} --ssl-cert-reqs 2"
fi

# Test hook: print the resolved command instead of exec'ing it, so the real
# script (not a reimplementation) can be exercised by an automated test
# without needing uvicorn/python-fastapi actually installed.
if [ "${XDR_ENTRYPOINT_TEST_MODE:-}" = "1" ]; then
    echo "${cmd}"
    exit 0
fi

exec ${cmd}
