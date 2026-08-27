#!/usr/bin/env bash
set -euo pipefail

PACKAGE_PATH="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PYTHON_BIN="${PYTHON_BIN:-python3}"
START_SERVICE=1

while [[ $# -gt 0 ]]; do
    case "$1" in
        --package)
            PACKAGE_PATH="$2"
            shift 2
            ;;
        --python)
            PYTHON_BIN="$2"
            shift 2
            ;;
        --no-start)
            START_SERVICE=0
            shift
            ;;
        *)
            echo "Unknown argument: $1" >&2
            exit 2
            ;;
    esac
done

PACKAGE_PATH="$(cd "$PACKAGE_PATH" && pwd)"
command -v "$PYTHON_BIN" >/dev/null 2>&1 || {
    echo "Python runtime not found: $PYTHON_BIN" >&2
    exit 1
}

"$PYTHON_BIN" "$PACKAGE_PATH/verify_agent_package.py" --package "$PACKAGE_PATH"

if [[ ${EUID} -ne 0 ]]; then
    echo "Installation requires root after package verification." >&2
    exit 1
fi

if ! id detector >/dev/null 2>&1; then
    useradd --system --home-dir /var/lib/xdr-agent --shell /usr/sbin/nologin detector
fi

install -d -o root -g root -m 0755 /opt/detector/services/endpoint-agent
install -d -o root -g detector -m 0750 /etc/detector/agent
install -d -o detector -g detector -m 0750 /var/lib/xdr-agent
install -o root -g root -m 0755 "$PACKAGE_PATH/agent.py" /opt/detector/services/endpoint-agent/agent.py
if [[ ! -e /etc/detector/agent/config.json ]]; then
    install -o root -g detector -m 0640 "$PACKAGE_PATH/config.json" /etc/detector/agent/config.json
fi
install -o root -g root -m 0644 "$PACKAGE_PATH/detector-endpoint-agent.service" /etc/systemd/system/detector-endpoint-agent.service

systemctl daemon-reload
if [[ $START_SERVICE -eq 1 ]]; then
    systemctl enable --now detector-endpoint-agent
else
    echo "Package installed without starting detector-endpoint-agent."
fi
