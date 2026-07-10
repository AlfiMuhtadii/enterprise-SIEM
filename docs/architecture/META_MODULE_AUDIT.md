# Meta-Module Audit

**Backlog item:** META-MODULE-RATIONALIZE
**Date:** 2026-07-10
**Scope:** Documentation only — no code changes in this pass. Classifies the
self-referential meta-module set the finding named as a "sprawl" concern and
recommends a path forward, without executing any merge/deprecation itself
(each of those is a separate, individually-scoped follow-up if approved).

---

## 1. What this audit is

The original finding: "effort spent certifying readiness instead of building
the capability that readiness would certify" — a class of service that
produces advisory records **about the platform's own readiness/maturity**
(shadow-promotion gates, soak evidence, certification scorecards) rather than
actual XDR detection/response capability. The finding cited ~32 such services
plus a "versioned-duplication smell" (`StabilityEvidenceFreeze` V2/V3/V4 and
seven overlapping soak services).

A prior pass ([META-MODULE-RATIONALIZE (bounded step)](../../REVIEW_COMPLETED.md))
already addressed the V2/V3/V4 smell with `StabilityFreezeOverviewService` —
a read-only facade, not a merge (see §4). This audit is the other half of the
original proposed fix: **"Audit the meta-module set"**.

## 2. Current inventory — the sprawl is larger than documented, not smaller

A fresh survey (glob `app/Services/*.php` + keyword grep, cross-checked
against the finding's named list) found **33 meta-modules**, not ~32:

- All **17 names the finding cited are still present and unchanged.**
- **16 additional meta-modules exist that were never named in the finding**:
  `DomainSoakHarnessService`, `DomainSoakSimulationService`,
  `EndpointSoakPlanService`, `Phase1SoakEvidenceFreezeService`,
  `Phase1SoakExecutionService`, `PilotExecutionService`,
  `PilotTenantOnboardingService`, `RealDomainSoakPlanService`,
  `RedpandaRecoveryHardeningService`, `RetentionGovernanceService`,
  `RuleEvidenceGovernanceService`, `SecurityHardeningEvidenceFreezeService`,
  `SensorHardeningService`, `SoakChaosValidationService`,
  `StabilityFreezeOverviewService`, `TelemetryScalePilotService`.

**Conclusion: the sprawl grew, it did not shrink**, between when the finding
was written and now. That is the single most important fact this audit
surfaces — a rationalization effort that stops at documenting a stale list
would already be behind.

Migration footprint: **24 files** hit the finding's literal search terms
(`readiness|certification|maturity|freeze|soak|governance|scorecard`); the
true footprint including `hardening`/`pilot`/`demo`-named tables is
**~36 migration files**. This is a real, non-trivial schema surface area for
a capability class that produces no detection/response signal.

## 3. Classification

Every meta-module below keeps its current behavior — this table is a
**recommendation for a future, individually-scoped pass**, not an action
taken by this audit.

| Service | Classification | Rationale |
|---|---|---|
| `StabilityEvidenceFreezeV2Service` | Keep | Distinct phase range (E045-E048), still-valid historical evidence |
| `StabilityEvidenceFreezeV3Service` | Keep | Distinct phase range (E049-E054) |
| `StabilityEvidenceFreezeV4Service` | Keep | Distinct phase range (E055-...), most current |
| `StabilityFreezeOverviewService` | Keep — **is the pattern** | Read-only facade over V2/V3/V4; the template for handling the rest of this table, see §4 |
| `DetectionPromotionReadinessService` | Keep | Real decision-support: rule-level shadow→active promotion criteria, distinct from platform-level certification |
| `CapacityGovernanceService` | Keep | Concrete cost/capacity projection, closer to real ops tooling than self-certification |
| `ComplianceGovernanceService` | Keep | Compliance-specific scope, distinct from general readiness |
| `TenantStrictModeReadinessService` | Keep | Narrow, concrete scope (tenant strict-mode cutover gate) |
| `RetentionGovernanceService` | Keep | Operational: resolves per-tenant retention days, actively consumed by `SecurityRetentionCommand` |
| `RuleEvidenceGovernanceService` | Keep | Distinct scope: detection rule evidence trail |
| `SecurityHardeningEvidenceFreezeService` | Keep | Distinct scope: security-control evidence, not platform-wide certification |
| `SensorHardeningService` | Keep | Operational: endpoint sensor resource governance |
| `RedpandaRecoveryHardeningService` | Keep | Operational: broker recovery validation, not self-certification |
| `EnterpriseDeploymentHardeningService` | Keep | Real deployment-readiness checks (canary tiers, drift detection) |
| `PilotExecutionService` | Keep | Operational: executes/tracks a real pilot run, not advisory-about-advisory |
| `PilotTenantOnboardingService` | Keep | Operational: tenant onboarding workflow |
| `TelemetryScalePilotService` | Keep | Distinct scope: telemetry-volume scale validation |
| `DomainSoakHarnessService` | Keep | General evidence-accumulation harness for promotion decisions |
| `DomainSoakSimulationService` | Keep | Already `is_simulated=true`-labelled (Track A); explicitly a dry-run, not a duplicate of the real soak services |
| `EndpointSoakPlanService` | Keep | Distinct artifact: tiering plan, not an evidence record |
| `Phase1SoakExecutionService` | Keep | The actual soak run/evaluation — real work, not meta-about-meta |
| `Phase1SoakEvidenceFreezeService` | Keep | Snapshot of a completed real run, distinct from the plan/execution services |
| `RealDomainSoakPlanService` | Keep | Multi-phase rollout structure, distinct from single-domain plans |
| `SoakChaosValidationService` | Keep | Distinct discipline: fault-injection/resilience testing, not promotion evidence |
| `EnterprisePilotReadinessMatrixService` | Keep — **corrected 2026-07-10** | Originally classified "Merge-candidate" below; investigated for the actual merge attempt and found genuinely distinct — see §3a |
| `PilotReadinessService` | Keep — **corrected 2026-07-10** | See §3a |
| `CommercialReadinessService` | Keep, **naming-risk** | Legitimate top-level summary, but the name alone (out of context) risks being read as a real commercial certification — no behavior change needed, `is_advisory` already enforced; flag for a defense/demo script note, not a code change |
| `FinalXdrCertificationService` | Keep, **naming-risk (highest)** | Same concern as above but sharper — "Final XDR Certification" is the exact phrase the original finding quoted as a credibility risk. Runtime output is already `is_advisory`/`freeze_approved=false`-gated; the risk is purely in how the class/feature name reads out of context |
| `CodeLevelXdrMaturityService` | Keep, low-risk naming | Narrower framing ("maturity scorecard") reads less like a real accreditation than "certification" |
| `ReleaseCandidateStabilizationService` | Keep | Distinct scope: RC-specific stabilization gate |
| `ReleaseGovernanceService` | Keep | Distinct scope: release process governance, not RC-specific |
| `LongRunningOperationalService` | Keep | Distinct scope: multi-week operational-window analysis |
| `DemoPlatformPackagingService` | **Lowest production value** | Name and scope suggest defense/demo packaging rather than an operational platform capability — candidate for relabeling as explicitly "demo tooling" (e.g. a `demo/` sub-namespace) rather than sitting alongside production governance services, not for deletion |

**Net recommendation (updated 2026-07-10 — see §3a): 0 of 33 are a genuine
merge-candidate pair; 1 of 33 is a naming/categorization candidate; the rest
are legitimately distinct in scope even though they share the same
advisory-scaffolding shape.** The finding's instinct that this is "sprawl"
is correct about the *shape* (near-identical
`ADVISORY_ONLY`/`freeze_approved=false`/gate-table scaffolding repeated 33
times) more than the *substance* (every service answers a genuinely
different question) — more so than this audit's own first pass concluded.

## 3a. Correction: the one "merge-candidate" pair was not actually a duplicate

This audit originally classified `PilotReadinessService` +
`EnterprisePilotReadinessMatrixService` as the one genuine merge-candidate
pair, on the strength of both names literally containing "pilot readiness."
When Task I of the next session round attempted the actual merge, deeper
investigation found the same thing the earlier `StabilityEvidenceFreeze`
investigation found: **superficial name similarity, structurally unrelated
services.**

- `PilotReadinessService` is **operational execution/tracking**: registers
  pilot onboarding, runs health checks, records success metrics, validates
  rollback, snapshots telemetry pressure, records operator sign-off and
  audit events. It does things.
- `EnterprisePilotReadinessMatrixService` is a **generic gate/evidence
  scorecard**: `REQUIRED_GATE_IDS` (`soak_validation`, `replay_verification`,
  `tenant_isolation`, `rollback_readiness`) and `DIMENSIONS` (`technical`,
  `operational`, `security`, `telemetry`, `easm`, `certification`) are
  evaluated from evidence an operator manually attaches via `linkEvidence()`
  — not hard-coded to `PilotReadinessService` at all. **Grep confirms zero
  references between the two services** — no shared models, no method calls
  either direction. The gate IDs *conceptually* overlap with what
  `PilotReadinessService` tracks (e.g. `rollback_readiness` ↔
  `validateRollback()`), but the matrix service is designed to aggregate
  evidence from *any* source, the same architectural role
  `StabilityEvidenceFreezeV2/V3/V4` play relative to the services *they*
  aggregate evidence from (`DetectionPromotionReadinessService`,
  `TenantBoundaryService`, etc.) — an evidence-aggregator is not a duplicate
  of the things it aggregates evidence about.

**Corrected outcome: no merge attempted, no facade needed either** (unlike
V2/V3/V4, these two don't cover sequential/overlapping ranges of the *same*
kind of evidence — there's no "which one is current" ambiguity to resolve
with an overview facade). The lesson generalizes: **this audit's own
name-similarity heuristic was exactly the mistake CLAUDE.md warns against
elsewhere in this codebase** ("distinct nouns, same verb" pattern) — every
"looks like a duplicate" finding in this class of service needs the same
"read the actual code, check for real coupling" verification the freeze
services already got, not just a classification pass over docblocks and
class names.

## 4. The versioned-duplication smell — already has a working pattern

`StabilityEvidenceFreeze` V2/V3/V4 was the finding's sharpest example. A
prior pass investigated a literal merge and rejected it: the three cover
non-overlapping phase ranges with different gate counts (12/22/16), separate
tables, commands, and controllers — collapsing them risked silently changing
gate coverage or losing per-version evidence with no live-pipeline verifier
available to catch a mistake.

Instead it shipped `StabilityFreezeOverviewService` / `stability:freeze-overview`
— a read-only facade that calls each version's existing `getLatestFreeze()`
and reports per-version status plus "most recent across all versions,"
explicitly labelled so a reader cannot mistake `v4`'s status for a merged or
superseding result.

**This is the recommended pattern for the soak-service overlap too**, not a
literal merge of `DomainSoakHarnessService` / `DomainSoakSimulationService` /
`EndpointSoakPlanService` / `Phase1SoakExecutionService` /
`Phase1SoakEvidenceFreezeService` / `RealDomainSoakPlanService` /
`SoakChaosValidationService` — each is confirmed distinct-purpose in §3
(harness=accumulation, simulation=dry-run, plan=tiering, execution=the run,
freeze=snapshot, real-plan=rollout structure, chaos=fault-injection), so the
"overlap" is structural repetition, not redundant computation. A future
`SoakOverviewService` mirroring `StabilityFreezeOverviewService`'s shape
(read-only, per-service `getLatest()` calls, explicit "these cover different
things" labelling) would address the finding's underlying concern —
"which of these 7 things is the current soak status" — without the risk of
merging services whose tables and gate logic have never been proven
equivalent.

## 5. Naming drift (Controller/Service mismatch)

Found during the survey, unrelated to the merge question but a real
low-risk cleanup opportunity for a future pass:

| Service | Controller (name doesn't match) |
|---|---|
| `EnterpriseDeploymentHardeningService` | `EnterpriseDeploymentController` (drops "Hardening") |
| `RedpandaRecoveryHardeningService` | `RedpandaHealthController` (drops "Recovery Hardening") |
| `LongRunningOperationalService` | `LongRunningOperationsController` (singular → plural) |
| `EnterprisePilotReadinessMatrixService` | `PilotReadinessMatrixController` (drops "Enterprise") |

Also notable: most meta-modules have a Controller but no Artisan Command
(unlike the soak/freeze services, which mostly have both) — inconsistent
operability between "browse in the SOC UI" and "run from CI/cron."

## 6. What this audit does NOT recommend

- **No service deletion.** Every "Keep" above has a distinct question it
  answers; removing it would lose that signal, not just tidy up sprawl.
- **No literal merge of the 7 soak services** or the 3 freeze versions
  beyond the read-only overview pattern already shipped — see §4 for why.
- **No renaming in this pass.** §3/§5's naming flags are candidates for a
  future, separately-approved cleanup, not executed here (renaming a public
  class changes every call site and risks a mechanical-but-large diff for
  zero functional benefit if done carelessly).

## 7. Concrete next steps (not executed by this audit)

1. ~~Merge `PilotReadinessService` into `EnterprisePilotReadinessMatrixService`~~
   — **retracted, see §3a**: investigated and found genuinely distinct, not
   a duplicate. No merge action remains for this pair.
2. Build a `SoakOverviewService` mirroring `StabilityFreezeOverviewService`'s
   read-only, per-service, explicitly-labelled pattern (§4).
3. Redirect new capability work toward the connector/search/asset-context
   tasks the original finding pointed at, rather than adding a 34th
   meta-module — the sprawl already grew once since the finding was written.
