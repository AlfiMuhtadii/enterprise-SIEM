<?php

/**
 * ENTERPRISE-051: Hardcoded Threshold Externalization
 *
 * All detection thresholds previously hardcoded as PHP constants are now
 * configurable here. Constants in services retain the same values as
 * defaults for backward compatibility and test clarity.
 *
 * Override via environment: XDR_DETECTION_* prefixed vars.
 */
return [

    // ── Active / soaked domains ────────────────────────────────────────────
    // Domains whose 6h soak PASSED (identity/cloud/SaaS, 2026-05-14).
    // Shadow rules in these domains are promotion-eligible after another soak PASS.
    // DO NOT add domains here without a domain-specific 6h soak PASS.
    'soaked_domains' => array_filter(
        explode(',', env('XDR_DETECTION_SOAKED_DOMAINS', 'identity,cloud,saas'))
    ),

    // Domains blocked by infrastructure prerequisite.
    'deferred_domains' => array_filter(
        explode(',', env('XDR_DETECTION_DEFERRED_DOMAINS', 'network,threat-intel,xdr'))
    ),

    // Domains where telemetry flows but no domain-specific soak has been scheduled.
    'soak_needed_domains' => array_filter(
        explode(',', env('XDR_DETECTION_SOAK_NEEDED_DOMAINS', 'endpoint'))
    ),

    // ── Shadow promotion thresholds (ENTERPRISE-047) ───────────────────────
    'promotion' => [
        // Minimum confidence for promote_eligible decision.
        // Rule must also have DLQ errors = 0 in its domain.
        'promote_eligible_threshold' => (float) env('XDR_DETECTION_PROMOTE_ELIGIBLE_THRESHOLD', 0.78),

        // Minimum confidence for keep_shadow (vs defer).
        'keep_shadow_threshold' => (float) env('XDR_DETECTION_KEEP_SHADOW_THRESHOLD', 0.65),

        // DLQ errors allowed in domain for promote_eligible: zero tolerance.
        'max_dlq_for_eligible' => (int) env('XDR_DETECTION_MAX_DLQ_FOR_ELIGIBLE', 0),
    ],

    // ── Endpoint soak plan thresholds (ENTERPRISE-048) ────────────────────
    'soak' => [
        // Confidence ≥ tier_1_threshold → soak_ready (80 endpoint rules)
        'tier_1_threshold' => (float) env('XDR_DETECTION_SOAK_TIER_1_THRESHOLD', 0.72),

        // Confidence ≥ tier_2_threshold → evidence_collection (13 endpoint rules)
        'tier_2_threshold' => (float) env('XDR_DETECTION_SOAK_TIER_2_THRESHOLD', 0.60),
    ],

    // ── Stability freeze thresholds (ENTERPRISE-049) ──────────────────────
    'freeze' => [
        // Minimum gate pass score to declare stability STABLE.
        'stable_score_threshold' => (float) env('XDR_DETECTION_STABLE_SCORE_THRESHOLD', 0.80),
    ],

    // ── Rule evidence governance (ENTERPRISE-050) ─────────────────────────
    'evidence' => [
        // Confidence at or above this value qualifies shadow rules for tier_2_next_batch.
        'tier_2_min_confidence' => (float) env('XDR_DETECTION_EVIDENCE_TIER2_MIN_CONFIDENCE', 0.72),
    ],

    // ── Confidence source labels ───────────────────────────────────────────
    'confidence_sources' => ['manual', 'fixture_tested', 'empirical'],

];
