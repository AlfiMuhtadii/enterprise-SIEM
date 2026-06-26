# XDR Platform — Integration Connector Contracts

> **Status:** Advisory-only. All integrations use `dry_run=true` by default.
> No real API calls are made unless both the required env vars are set **and** `XDR_*_DRY_RUN=false`.

---

## 1. Slack Incoming Webhook

**Status:** Real adapter implemented (`SlackRealAdapter`). Opt-in via env.

**Required env vars:**
```
XDR_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/T.../B.../...
XDR_SLACK_DRY_RUN=false    # default: true
```

**Payload schema:**
```json
{
  "text": "string (max 150 chars)",
  "blocks": [
    {"type": "section", "text": {"type": "mrkdwn", "text": "string (max 2000 chars)"}}
  ],
  "username": "XDR SOC"
}
```

**Restrictions:** No credential fields in payload. Body length capped at 2000 chars.

---

## 2. PagerDuty Events API v2

**Status:** Real adapter implemented (`PagerDutyRealAdapter`). Opt-in via env.

**Required env vars:**
```
XDR_PAGERDUTY_ROUTING_KEY=<32-char integration key>
XDR_PAGERDUTY_DRY_RUN=false    # default: true
```

**Endpoint:** `POST https://events.pagerduty.com/v2/enqueue`

**Payload schema:**
```json
{
  "routing_key": "<routing_key>",
  "event_action": "trigger",
  "dedup_key": "xdr-<source_reference>",
  "payload": {
    "summary": "string (max 1024 chars)",
    "source": "xdr-soc",
    "severity": "critical|error|warning|info",
    "custom_details": {
      "body": "string (max 512 chars)",
      "advisory": true
    }
  }
}
```

**Restrictions:** Only `trigger` action; no `resolve` or `acknowledge` autonomous actions.

---

## 3. Jira Cloud REST API v3

**Status:** Real adapter implemented (`JiraRealAdapter`). Opt-in via env.

**Required env vars:**
```
XDR_JIRA_URL=https://yourcompany.atlassian.net
XDR_JIRA_EMAIL=soc-bot@yourcompany.com
XDR_JIRA_API_TOKEN=<Jira API token>
XDR_JIRA_PROJECT_KEY=SOC
XDR_JIRA_DRY_RUN=false    # default: true
```

**Endpoint:** `POST {XDR_JIRA_URL}/rest/api/3/issue`

**Payload schema:**
```json
{
  "fields": {
    "project": {"key": "SOC"},
    "summary": "string (max 255 chars)",
    "description": {"type": "doc", "version": 1, "content": [...]},
    "issuetype": {"name": "Task"},
    "priority": {"name": "Highest|High|Medium|Low"},
    "labels": ["xdr-soc", "advisory"]
  }
}
```

**Restrictions:** Create-only; no automatic issue closing or assignment.

---

## 4. ServiceNow Table API

**Status:** Real adapter implemented (`ServiceNowRealAdapter`). Opt-in via env.

**Required env vars:**
```
XDR_SERVICENOW_URL=https://yourinstance.service-now.com
XDR_SERVICENOW_USER=xdr-integration
XDR_SERVICENOW_PASSWORD=<service account password>
XDR_SERVICENOW_DRY_RUN=false    # default: true
```

**Endpoint:** `POST {XDR_SERVICENOW_URL}/api/now/table/incident`

**Payload schema:**
```json
{
  "short_description": "string (max 160 chars)",
  "description": "string (max 4000 chars)",
  "urgency": "1|2|3",
  "impact": "1|2|3",
  "category": "security",
  "assignment_group": "SOC",
  "caller_id": "xdr-soc"
}
```

**Restrictions:** Create-only; no state transitions, no automatic ticket closure.

---

## 5. Okta Identity Provider (IdP Events)

**Status:** Schema-only / documented contract. Connector stub not yet implemented.

**Integration type:** Inbound webhook (Okta Event Hook)

**Event types supported:**
- `user.session.start` → identity/login events
- `user.authentication.auth_via_mfa` → MFA events
- `policy.evaluate_sign_on` → policy events
- `user.account.update_password` → account modification

**Payload contract (Okta Event Hook v1):**
```json
{
  "eventType": "com.okta.event_hook",
  "eventTypeVersion": "1.0",
  "cloudEventsVersion": "0.1",
  "data": {
    "events": [
      {
        "eventType": "user.session.start",
        "actor": {"id": "00u...", "type": "User", "displayName": "...", "login": "..."},
        "outcome": {"result": "SUCCESS|FAILURE"},
        "target": [{"id": "...", "type": "AppInstance"}],
        "request": {"ipChain": [{"ip": "..."}]},
        "published": "2026-06-26T00:00:00.000Z"
      }
    ]
  }
}
```

**XDR normalizer:** `okta-v1` normalizer maps to `identity` domain events.

---

## 6. Azure Active Directory (Azure AD)

**Status:** Schema-only / documented contract. Connector stub not yet implemented.

**Integration type:** Azure Monitor webhook / Log Analytics export

**Event types supported:**
- Sign-in logs → identity/login events
- Audit logs → identity/admin events
- Conditional Access evaluations → policy events

**Payload contract (Azure Monitor webhook):**
```json
{
  "category": "SignInLogs",
  "operationName": "Sign-in activity",
  "resultType": "0",
  "properties": {
    "userPrincipalName": "user@domain.com",
    "ipAddress": "1.2.3.4",
    "location": {"city": "...", "countryOrRegion": "..."},
    "conditionalAccessStatus": "success|failure|notApplied",
    "riskLevelDuringSignIn": "none|low|medium|high"
  }
}
```

**XDR normalizer:** `azure-ad-v1` normalizer maps to `identity` domain events.

---

## 7. Microsoft 365 Audit Logs

**Status:** Schema-only / documented contract. Connector stub not yet implemented.

**Integration type:** Microsoft 365 Management Activity API

**Event types supported:**
- SharePoint/OneDrive operations → saas/cloud events
- Exchange Online operations → saas events
- Teams operations → saas events
- Azure AD operations → identity events

**Payload contract (M365 Audit Log):**
```json
{
  "CreationTime": "2026-06-26T00:00:00",
  "Operation": "FileDownloaded|FileDeleted|UserLoggedIn",
  "Workload": "SharePoint|Exchange|Teams|AzureActiveDirectory",
  "UserId": "user@domain.com",
  "ClientIP": "1.2.3.4",
  "ObjectId": "...",
  "ResultStatus": "Succeeded|Failed",
  "SiteUrl": "https://..."
}
```

**XDR normalizer:** `o365-v1` normalizer maps to `saas` domain events.

---

## 8. Google Workspace Audit Logs

**Status:** Schema-only / documented contract. Connector stub not yet implemented.

**Integration type:** Google Workspace Admin SDK Reports API

**Event types supported:**
- login events → identity events
- admin events → identity/admin events
- drive events → saas events

**Payload contract (Google Workspace Reports API):**
```json
{
  "kind": "admin#reports#activity",
  "id": {"time": "2026-06-26T00:00:00.000Z", "applicationName": "login|admin|drive"},
  "actor": {"email": "user@domain.com", "profileId": "..."},
  "ipAddress": "1.2.3.4",
  "events": [
    {
      "type": "login",
      "name": "login_success|login_failure|login_challenge",
      "parameters": [{"name": "login_type", "value": "google_password"}]
    }
  ]
}
```

**XDR normalizer:** `gsuite-v1` normalizer maps to `identity`/`saas` domain events.

---

## Security Constraints (All Integrations)

- No credential fields (passwords, tokens, secrets) in notification bodies
- Payload bodies capped at adapter-specific maximums
- No autonomous account suspension, ticket closure, or resource deletion
- All real deliveries are append-only audited in `notification_deliveries`
- `simulated=true` default; real delivery requires explicit analyst opt-in
- No autonomous retry without analyst approval for `escalation` or `sla_breach` types
