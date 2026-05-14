# Staged Cutover Status

**Updated:** 2026-05-14

---

## Status by Domain

| Domain | Engine | State | Evidence |
|---|---|---|---|
| identity | go | staged_active | 6h soak PASS 2026-05-14 |
| cloud | go | staged_active | 6h soak PASS 2026-05-14 |
| saas | go | staged_active | 6h soak PASS 2026-05-14 |
| endpoint | shadow | shadow-only | No domain soak — cutover not approved |
| dns | shadow | shadow-only | No domain soak — cutover not approved |
| proxy | shadow | shadow-only | No domain soak — cutover not approved |
| firewall | shadow | shadow-only | No domain soak — cutover not approved |

---

## Current Configuration

```env
XDR_CORRELATION_ENGINE=go
XDR_CORRELATION_SCOPE=identity-cloud
XDR_CORRELATION_FALLBACK_TO_LEGACY=true
XDR_CORRELATION_FALLBACK_FAILURE_THRESHOLD=3
```

---

## Rollback

Rollback capability is preserved at all times. To return to shadow mode:

```env
XDR_CORRELATION_ENGINE=shadow
```

The circuit breaker provides automatic runtime fallback:
- 1–2 consecutive failures: no fallback
- 3 consecutive failures: fallback to legacy

---

## Control Plane — Unchanged

Laravel remains the SOC control plane. Go correlation promotion does not change:

- Dashboard
- RBAC
- Incident workflow
- Audit and reporting
- Configuration management

---

## Migration Stages

This project follows a strangler migration pattern:

1. Extract service
2. Run shadow (parallel with legacy; output not consumed)
3. Parity validation (shadow vs legacy output match)
4. Replay validation (idempotency, determinism)
5. 6h soak (stability gates)
6. Staged active (Go output consumed; legacy fallback preserved)
7. Sustained monitoring
8. Permanent promotion (requires extended operational evidence — not yet approved)

**Current state:** Step 6 complete for identity/cloud/SaaS.

---

## Gates Required for Endpoint/DNS/Proxy/Firewall

Before any shadow-only domain can advance to staged_active:

- [ ] Golden parity validation (shadow vs legacy output match)
- [ ] Large replay parity validation
- [ ] Domain-specific 6h soak — all gates PASS
- [ ] Duplicate rate confirmed = 0
- [ ] p95 latency < 300 ms confirmed
- [ ] Rollback validation confirmed
- [ ] Explicit approval

Do not advance shadow-only domains without completing all gates above.

---

## What This Is Not

- Not a full EDR
- Not a kernel telemetry platform
- Not a hyperscale commercial SIEM replacement
- Not permanently production-promoted — staged active with rollback preserved
