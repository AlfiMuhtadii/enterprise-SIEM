FROM python:3.12-slim-bookworm

# libsnappy1v5/libsnappy-dev: found live -- this consumer previously crashed
# with UnsupportedCodecError on any snappy-compressed record (franz-go's
# native-transport producer default), even though nothing in this Dockerfile
# ever installed snappy support. Needed at both build time (python-snappy's
# C extension) and runtime (libsnappy1v5).
RUN apt-get update && apt-get install -y --no-install-recommends \
    libsnappy1v5 libsnappy-dev gcc \
    && rm -rf /var/lib/apt/lists/*

# kafka-python: native Kafka protocol client (Pandaproxy REST consumer API not supported by Redpanda)
# psycopg2-binary: bundles PostgreSQL client library — no build tools needed
# python-snappy: decodes snappy-compressed Kafka records (see above)
RUN pip install --no-cache-dir \
    kafka-python-ng==2.2.3 \
    psycopg2-binary==2.9.10 \
    python-snappy==0.7.3

ENV PYTHONUNBUFFERED=1

WORKDIR /app

# Mirror the scripts/ directory so Path(__file__).parents[1] resolves to /app
# and storage/logs/telemetry_dead_letter.jsonl writes correctly
COPY scripts/telemetry_stream_worker.py  scripts/
COPY scripts/ingest_telemetry_events.py  scripts/
COPY scripts/realtime_detector_consumer.py scripts/
COPY scripts/telemetry_event_contract.py scripts/
COPY scripts/security_event_contract.py  scripts/
COPY scripts/xdr_infra_clients.py        scripts/

RUN mkdir -p storage/logs

CMD ["python3", "scripts/telemetry_stream_worker.py"]
