FROM python:3.12-slim@sha256:423ed6ab25b1921a477529254bfeeabf5855151dc2c3141699a1bfc852199fbf
WORKDIR /app
RUN groupadd -r app && useradd -r -g app -d /app app
COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt
COPY --chown=app:app main.py ./
COPY --chown=app:app xdr_event_contracts.py ./
COPY --chown=app:app traceparent.py ./
COPY --chown=app:app otlp_export.py ./
COPY --chown=app:app pg_pool.py ./
COPY --chown=app:app incident_aggregation.py ./
COPY --chown=app:app docker-entrypoint.sh ./
COPY --chown=app:app docker-healthcheck.py ./
USER app
EXPOSE 8096
HEALTHCHECK --interval=30s --timeout=5s --retries=3 --start-period=10s \
  CMD python docker-healthcheck.py 8096 || exit 1
CMD ["sh", "docker-entrypoint.sh", "8096"]
