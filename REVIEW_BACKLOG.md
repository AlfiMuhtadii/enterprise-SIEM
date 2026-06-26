# Pending Hardening & Cleanup Tasks (Backlog)

This file tracks all pending security hardening, refactoring, and documentation alignment tasks.
It is synchronized with GitHub Issues. Developers/writing agents (e.g., Claude) should resolve these tasks.

---

## *No open tasks currently.* All issues are closed or completed!

---

## Proposed Task: [RESP-1] Route legacy SOC agent commands through EndpointResponseCommandService [Labels: agent:claude, priority:high, type:response-safety]
* **Source Review**: RESPONSE-SOAR-AUDIT-1, 2026-06-26
* **Goal**: Remove the parallel legacy endpoint command write path and enforce the modern response command allowlist/state machine consistently.

### Goal & Requirements:
> `SocAgentController::queueCommand()` and `SocResponseController::decide()` write directly to `agent_commands` with legacy command types (`collect-now`, `flush-local-queue`, `rotate-agent-secret`, `refresh-policy`, `restart-agent-loop`). This bypasses `EndpointResponseCommandService`, `EndpointResponseCommand::ALLOWED_TYPES`, the endpoint response command event audit trail, and the `CMD-YYYY-NNNNN` ID convention. Refactor legacy command creation to use `EndpointResponseCommandService`, map only behaviorally equivalent safe commands, reject unsupported legacy command types, and add route/service tests proving direct legacy command creation cannot bypass the new allowlist.

---

## Proposed Task: [AGENT-API-1] Enforce agent signatures on endpoint command poll, ack, and result APIs [Labels: agent:claude, priority:high, type:agent-api-security]
* **Source Review**: RESPONSE-SOAR-AUDIT-1, 2026-06-26
* **Goal**: Prevent unauthenticated command disclosure and spoofed command lifecycle mutations in the new endpoint response API.

### Goal & Requirements:
> `EndpointAgentApiController::pollCommands()` returns dispatched commands by `agent_id` without verifying `X-Agent-Signature`. `EndpointResponseCommandService::acknowledge()` and `receiveResult()` log invalid signatures but still update command state. Require valid per-agent authentication for poll, ack, and result endpoints before returning command data or mutating state. Reuse/shared-factor the stronger per-agent secret verification used by `AgentIngestionController::verifiedAgent()` where appropriate. Preserve hardening logs for failures, but return `401/403` and do not transition state on invalid signatures. Add tests for unauthenticated poll denial and invalid ack/result denial.

---

## Proposed Task: [INT-AUTH-1] Remove production host exposure for internal pipeline service ports 8092-8096 [Labels: agent:claude, priority:high, type:infra-security]
* **Source Review**: INTERNAL-AUTH-EDGE-AUDIT-1, 2026-06-26
* **Goal**: Make production compose behavior match the documented boundary that internal pipeline services are not externally exposed.

### Goal & Requirements:
> Base `docker-compose.yml` publishes `normalizer-worker` (`8092:8092`), `correlation-worker` (`8093:8093`), `ai-rag-service` (`8094:8094`), `alert-writer-service` (`8095:8095`), and `incident-builder-service` (`8096:8096`). `docker-compose.prod.yml` claims pipeline services have no external exposure but does not reset these inherited port mappings. Add `ports: !reset []` or localhost-only bindings for these services in the production overlay. Extend `scripts/xdr_production_profile_validate.py` and tests to fail production validation when ports 8092-8096 remain public or inherited from base compose.

---

## Proposed Task: [INT-AUTH-2] Require internal auth and redaction for alert/incident DLQ debug endpoints [Labels: agent:claude, priority:medium, type:data-exposure]
* **Source Review**: INTERNAL-AUTH-EDGE-AUDIT-1, 2026-06-26
* **Goal**: Prevent unauthenticated disclosure of recent alert/incident failure payloads.

### Goal & Requirements:
> `alert-writer-service` and `incident-builder-service` expose `GET /dlq` without checking `X-Internal-Service-Token`. DLQ entries can include trace IDs, error details, alert payload dumps, actor identifiers, tenant context, and incident build inputs. Protect `/dlq` with the same internal auth enforcement used by `/v1/write`, `/v1/process`, and `/v1/build`, or disable the endpoint in production. Redact payloads before returning DLQ items. Add tests proving `/dlq` rejects missing/invalid tokens when `XDR_ENFORCE_INTERNAL_AUTH=true`.
