# Security Event Contract v1

The detector consumes JSONL: one JSON object per line.

## Required Fields

| Field | Type | Description |
| --- | --- | --- |
| `schema_version` | integer | Must be `1`. |
| `ts` | string | ISO-8601 timestamp. |
| `event` | string | Event name. Main values: `http_request`, `auth_login_failed`, `auth_login_success`, `authorization_denied`. |
| `request_id` | string | UUID for request correlation. |
| `ip` | string | Source IP address. |
| `user_agent_hash` | string | HMAC-SHA256 lowercase hex hash. |
| `method` | string | HTTP method. |
| `path` | string | Request path starting with `/`. |
| `status` | integer | HTTP response status `100..599`. |

## Optional Fields

| Field | Type | Description |
| --- | --- | --- |
| `user_id` | integer or null | Authenticated user id if available. |
| `email_hash` | string or null | HMAC-SHA256 lowercase hex hash of normalized email. |
| `latency_ms` | integer or null | Request latency in milliseconds. |
| `query_hash` | string or null | HMAC-SHA256 lowercase hex hash of query payload. |
| `has_sql_keywords` | boolean or null | True when payload contains SQLi indicators. |
| `has_script_payload` | boolean or null | True when payload contains script/XSS indicators. |

## Privacy Rule

Do not send raw email, password, session token, cookie, query string, request body, or user-agent to the detector. Hash sensitive values at the source application.

## Example

```json
{"schema_version":1,"ts":"2026-05-09T02:30:00+07:00","event":"http_request","request_id":"2f15fca8-571f-4f2d-a0bc-7fd7e8570f84","ip":"198.51.100.77","user_agent_hash":"db778e2cd460a77cbb3c8f2fb6e6e5945b4a5ece56c64ee053e1b432d469d4ea","user_id":null,"email_hash":null,"method":"GET","path":"/search","status":200,"latency_ms":24,"query_hash":"51fd93d4248f0ba7291795fa85ce82351b284436da5683e1c8e5b53e7e9f5e5e","has_sql_keywords":true,"has_script_payload":false}
```
