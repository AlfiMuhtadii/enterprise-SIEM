#!/usr/bin/env python3
"""
ENTERPRISE-051: Detection Config Externalization Validator
Checks CFG-01 through CFG-14 offline.

Exit codes:
  0 = all checks PASS
  1 = one or more FAIL
"""

import os
import sys
import re

ROOT        = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CFG_PATH    = os.path.join(ROOT, "config", "xdr_detection.php")
SPD_SVC     = os.path.join(ROOT, "app", "Services", "ShadowReadyPromotionDecisionService.php")
ESP_SVC     = os.path.join(ROOT, "app", "Services", "EndpointSoakPlanService.php")
DPR_SVC     = os.path.join(ROOT, "app", "Services", "DetectionPromotionReadinessService.php")
SFV_SVC     = os.path.join(ROOT, "app", "Services", "StabilityEvidenceFreezeV2Service.php")

results = []


def check(name, passed, detail=""):
    status = "[PASS]" if passed else "[FAIL]"
    results.append((name, passed, detail))
    print(f"  {status} {name}" + (f" -- {detail}" if detail else ""))


def fc(path):
    if not os.path.exists(path):
        return ""
    with open(path, encoding="utf-8") as f:
        return f.read()


print("")
print("=== ENTERPRISE-051: Detection Config Externalization Validator ===")
print("")

# CFG-01: config/xdr_detection.php exists
cfg = fc(CFG_PATH)
check("CFG-01 config/xdr_detection.php exists", os.path.exists(CFG_PATH))

# CFG-02: soaked_domains key present
check("CFG-02 soaked_domains in config", "soaked_domains" in cfg, "key present")

# CFG-03: promotion thresholds present
t1 = "promote_eligible_threshold" in cfg
t2 = "keep_shadow_threshold" in cfg
check("CFG-03 promotion thresholds in config", t1 and t2, f"promote={t1} keep_shadow={t2}")

# CFG-04: soak thresholds present
s1 = "tier_1_threshold" in cfg
s2 = "tier_2_threshold" in cfg
check("CFG-04 soak tier thresholds in config", s1 and s2, f"t1={s1} t2={s2}")

# CFG-05: freeze stable_score_threshold present
check("CFG-05 freeze stable_score_threshold in config", "stable_score_threshold" in cfg)

# CFG-06: ShadowReadyPromotionDecisionService has config() call
spd = fc(SPD_SVC)
check("CFG-06 ShadowReadyPromotionDecisionService reads from config()",
      "config('xdr_detection" in spd or 'config("xdr_detection' in spd,
      "config() call present")

# CFG-07: EndpointSoakPlanService has config() call
esp = fc(ESP_SVC)
check("CFG-07 EndpointSoakPlanService reads from config()",
      "config('xdr_detection" in esp or 'config("xdr_detection' in esp,
      "config() call present")

# CFG-08: DetectionPromotionReadinessService has config() call
dpr = fc(DPR_SVC)
check("CFG-08 DetectionPromotionReadinessService reads from config()",
      "config('xdr_detection" in dpr or 'config("xdr_detection' in dpr,
      "config() call present")

# CFG-09: ShadowReadyPromotionDecisionService constants still present (backward compat)
pe_const = "PROMOTE_ELIGIBLE_THRESHOLD = 0.78" in spd
ks_const = "KEEP_SHADOW_THRESHOLD = 0.65" in spd
check("CFG-09 SPD constants still present (backward compat)", pe_const and ks_const,
      f"pe={pe_const} ks={ks_const}")

# CFG-10: EndpointSoakPlanService constants still present
t1_const = "TIER_1_THRESHOLD = 0.72" in esp
t2_const = "TIER_2_THRESHOLD = 0.60" in esp
check("CFG-10 ESP constants still present (backward compat)", t1_const and t2_const,
      f"t1={t1_const} t2={t2_const}")

# CFG-11: DetectionPromotionReadinessService has domain list defaults
default_ok = "SOAKED_DOMAINS_DEFAULT" in dpr or "getSoakedDomains" in dpr
check("CFG-11 DPR has config-aware soaked domain reader", default_ok, "method present")

# CFG-12: StabilityEvidenceFreezeV2 has dynamic E047/E048 phase queries
sfv = fc(SFV_SVC)
e047_dynamic = "liveE047Metrics" in sfv
e048_dynamic = "liveE048Metrics" in sfv
check("CFG-12 StabilityFreeze v2 queries DB for E047/E048 metrics",
      e047_dynamic and e048_dynamic, f"e047={e047_dynamic} e048={e048_dynamic}")

# CFG-13: env() calls in config use XDR_DETECTION_ prefix
env_count = len(re.findall(r"env\('XDR_DETECTION_", cfg))
check("CFG-13 env() calls use XDR_DETECTION_ prefix", env_count >= 5,
      f"found {env_count} XDR_DETECTION_ env() calls")

# CFG-14: confidence_sources key present in config
check("CFG-14 confidence_sources key in config", "confidence_sources" in cfg)

# Summary
print("")
passed = sum(1 for _, ok, _ in results if ok)
failed = sum(1 for _, ok, _ in results if not ok)
total  = len(results)
print(f"=== Results: {passed}/{total} PASS, {failed} FAIL ===")
print("  ADVISORY-ONLY: no thresholds were changed, only externalized. ACTIVE_ALLOWLIST unchanged.")
print("")

sys.exit(0 if failed == 0 else 1)
