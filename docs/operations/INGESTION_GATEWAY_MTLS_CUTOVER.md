# Ingestion Gateway mTLS Cutover

Production terminates mutual TLS on the ingestion gateway. The five bundled Go
connectors and Laravel scenario runner use CA-verifying clients and present the
internal client identity. Endpoint agents use separately provisioned endpoint
identities; private endpoint keys must not be included in deployment packages.

## Preflight

1. Generate or provision the internal CA, gateway server certificate, and
   client identities. The gateway certificate must contain
   `ingestion-gateway`, `localhost`, and every external gateway DNS name used
   by endpoint agents in its SAN list. The repository generator is for local
   development and does not know production DNS names.
2. Confirm private keys are outside source control and readable only by their
   owner and the runtime key group.
3. Run:

   ```powershell
   python scripts/xdr_ingestion_mtls_compose_validate.py
   docker compose -f docker-compose.yml -f docker-compose.prod.yml `
     --env-file .env.production.example --profile strangler --profile app config --quiet
   ```

4. Rebuild the ingestion gateway and connector images. The gateway image must
   include the `curl` client used by its certificate-authenticated healthcheck.
5. Provision every external endpoint agent with its own CA/client-cert/key
   configuration before moving that endpoint to the production gateway.

## Coordinated Deployment

Deploy `docker-compose.prod.yml` as one change. It performs these coupled
operations:

- enables the gateway mTLS server on port 8091;
- changes every bundled connector ingestion URL to HTTPS and enables its
  client-only mTLS mode;
- makes connectors wait for the gateway to become healthy;
- changes Laravel service-health and scenario ingestion URLs to HTTPS;
- removes connector metrics ports from the production host while retaining
  syslog TCP/UDP 5140.

The gateway does not offer plaintext and TLS listeners simultaneously on port
8091. Do not deploy only one side of this change.

## Verification

Inside the gateway container, the Compose healthcheck verifies the CA,
hostname, and client identity against `https://localhost:8091/health`. Also
verify:

- a connector event receives a 2xx ingestion response;
- the same request without a client certificate fails during TLS negotiation;
- gateway logs show no certificate, hostname, or HMAC failures for provisioned
  clients;
- connector metrics show forwarding progress and no sustained forward errors;
- Laravel real-mode scenario ingestion reaches the gateway over HTTPS.

## Rollback

Rollback must also be coordinated. In one deployment, disable the gateway mTLS
server, restore connector and scenario URLs to HTTP, and disable connector
client mTLS. Rolling back only the gateway or only its callers stops ingestion.
Treat any events buffered by clients during the transition according to each
client's replay and bounded-buffer policy.
