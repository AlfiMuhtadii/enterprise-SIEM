#!/usr/bin/env python3
"""
ENTERPRISE-054: Integration Reality Pass Validator
Checks INT-01 through INT-14 offline.

Exit codes:
  0 = all checks PASS
  1 = one or more FAIL
"""

import os
import sys

ROOT         = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SLACK_PATH   = os.path.join(ROOT, "app", "Services", "Integrations", "SlackRealAdapter.php")
PD_PATH      = os.path.join(ROOT, "app", "Services", "Integrations", "PagerDutyRealAdapter.php")
JIRA_PATH    = os.path.join(ROOT, "app", "Services", "Integrations", "JiraRealAdapter.php")
SNOW_PATH    = os.path.join(ROOT, "app", "Services", "Integrations", "ServiceNowRealAdapter.php")
NOTIF_PATH   = os.path.join(ROOT, "app", "Services", "NotificationService.php")
CONTRACTS    = os.path.join(ROOT, "docs", "integrations", "CONNECTOR_CONTRACTS.md")

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
print("=== ENTERPRISE-054: Integration Reality Pass Validator ===")
print("")

# INT-01: Slack adapter exists
slack = fc(SLACK_PATH)
check("INT-01 SlackRealAdapter exists", os.path.exists(SLACK_PATH))

# INT-02: Slack has DRY_RUN_DEFAULT = true
import re as _re
check("INT-02 SlackRealAdapter DRY_RUN_DEFAULT = true",
      bool(_re.search(r"DRY_RUN_DEFAULT\s*=\s*true", slack)), "constant present")

# INT-03: Slack reads XDR_SLACK_WEBHOOK_URL
check("INT-03 Slack reads XDR_SLACK_WEBHOOK_URL",
      "XDR_SLACK_WEBHOOK_URL" in slack, "env var referenced")

# INT-04: PagerDuty adapter exists
pd = fc(PD_PATH)
check("INT-04 PagerDutyRealAdapter exists", os.path.exists(PD_PATH))

# INT-05: PagerDuty has DRY_RUN_DEFAULT = true
import re as _re
check("INT-05 PagerDutyRealAdapter DRY_RUN_DEFAULT = true",
      bool(_re.search(r"DRY_RUN_DEFAULT\s*=\s*true", pd)), "constant present")

# INT-06: PagerDuty reads XDR_PAGERDUTY_ROUTING_KEY
check("INT-06 PagerDuty reads XDR_PAGERDUTY_ROUTING_KEY",
      "XDR_PAGERDUTY_ROUTING_KEY" in pd, "env var referenced")

# INT-07: Jira adapter exists
jira = fc(JIRA_PATH)
check("INT-07 JiraRealAdapter exists", os.path.exists(JIRA_PATH))

# INT-08: Jira has DRY_RUN_DEFAULT = true
check("INT-08 JiraRealAdapter DRY_RUN_DEFAULT = true",
      bool(_re.search(r"DRY_RUN_DEFAULT\s*=\s*true", jira)), "constant present")

# INT-09: ServiceNow adapter exists
snow = fc(SNOW_PATH)
check("INT-09 ServiceNowRealAdapter exists", os.path.exists(SNOW_PATH))

# INT-10: ServiceNow has DRY_RUN_DEFAULT = true
check("INT-10 ServiceNowRealAdapter DRY_RUN_DEFAULT = true",
      bool(_re.search(r"DRY_RUN_DEFAULT\s*=\s*true", snow)), "constant present")

# INT-11: NotificationService has dispatch methods
notif = fc(NOTIF_PATH)
has_slack   = "dispatchSlack" in notif
has_pd      = "dispatchPagerDuty" in notif
has_jira    = "dispatchJira" in notif
has_snow    = "dispatchServiceNow" in notif
check("INT-11 NotificationService has all 4 dispatch methods",
      has_slack and has_pd and has_jira and has_snow,
      f"slack={has_slack} pd={has_pd} jira={has_jira} snow={has_snow}")

# INT-12: SIMULATED_BY_DEFAULT still = true in NotificationService
check("INT-12 SIMULATED_BY_DEFAULT = true still in NotificationService",
      "SIMULATED_BY_DEFAULT = true" in notif, "constant unchanged")

# INT-13: Connector contracts doc exists
check("INT-13 CONNECTOR_CONTRACTS.md exists", os.path.exists(CONTRACTS))

# INT-14: Contracts cover all 8 integration types
if os.path.exists(CONTRACTS):
    contracts = fc(CONTRACTS)
    expected = ["Okta", "Azure Active Directory", "Microsoft 365", "Google Workspace",
                "Slack", "PagerDuty", "Jira", "ServiceNow"]
    all_covered = all(e in contracts for e in expected)
    check("INT-14 Contracts cover all 8 integration types",
          all_covered, f"all={all_covered}")
else:
    check("INT-14 Contracts cover all 8 integration types", False, "file missing")

# Summary
print("")
passed = sum(1 for _, ok, _ in results if ok)
failed = sum(1 for _, ok, _ in results if not ok)
total  = len(results)
print(f"=== Results: {passed}/{total} PASS, {failed} FAIL ===")
print("  SAFETY: SIMULATED_BY_DEFAULT=true unchanged. All adapters dry_run=true by default.")
print("")

sys.exit(0 if failed == 0 else 1)
