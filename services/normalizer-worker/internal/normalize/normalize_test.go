package normalize

import (
	"testing"

	"detector-xdr-normalizer-worker/internal/traceparent"
)

func TestEventDispatchesToEndpoint(t *testing.T) {
	raw := map[string]any{
		"ts": "2026-01-01T00:00:00Z", "telemetry_type": "endpoint", "event_type": "process_start",
		"host": "host-1", "process_name": "cmd.exe",
	}
	out, err := Event(raw)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if out["normalization_version"] != "endpoint-v1" {
		t.Fatalf("expected endpoint-v1 normalization, got %v", out["normalization_version"])
	}
}

func TestEventDispatchesToEachTelemetryType(t *testing.T) {
	cases := map[string]string{
		"dns":               "dns-v1",
		"proxy":             "proxy-v1",
		"firewall":          "firewall-v1",
		"identity_provider": "identity-provider-v1",
		"saas_audit":        "saas-audit-v1",
		"ticket_sync":       "ticket-sync-v1",
		"notification":      "notification-event-v1",
		"sysmon":            "sysmon-v1",
		"powershell":        "powershell-v1",
		"security_event":    "security-event-v1",
		"syslog_cef":        "cef-syslog-v1",
	}
	for telemetryType, expectedVersion := range cases {
		raw := map[string]any{"ts": "2026-01-01T00:00:00Z", "telemetry_type": telemetryType, "event_type": "x"}
		out, err := Event(raw)
		if err != nil {
			t.Fatalf("%s: unexpected error: %v", telemetryType, err)
		}
		if out["normalization_version"] != expectedVersion {
			t.Fatalf("%s: expected %s, got %v", telemetryType, expectedVersion, out["normalization_version"])
		}
	}
}

func TestEventFallsBackToDefaultEnvelopeForUnknownType(t *testing.T) {
	raw := map[string]any{"ts": "2026-01-01T00:00:00Z", "telemetry_type": "identity", "event_type": "login_failed"}
	out, err := Event(raw)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if _, hasVersion := out["normalization_version"]; hasVersion {
		t.Fatalf("default envelope should not set normalization_version, got %v", out["normalization_version"])
	}
	if out["telemetry_type"] != "identity" {
		t.Fatalf("expected telemetry_type=identity, got %v", out["telemetry_type"])
	}
}

func TestEventRejectsMissingRequiredFields(t *testing.T) {
	cases := []map[string]any{
		{"telemetry_type": "endpoint", "event_type": "x"},            // missing ts
		{"ts": "2026-01-01T00:00:00Z", "event_type": "x"},            // missing telemetry_type
		{"ts": "2026-01-01T00:00:00Z", "telemetry_type": "endpoint"}, // missing event_type
	}
	for i, raw := range cases {
		if _, err := Event(raw); err == nil {
			t.Fatalf("case %d: expected error for missing required fields, got nil", i)
		}
	}
}

func TestEndpointFieldAliasing(t *testing.T) {
	// "src_ip" is a fallback alias for "source_ip"
	raw := map[string]any{"src_ip": "10.0.0.5", "process_name": "powershell.exe", "pid": float64(123)}
	out, err := Endpoint(raw)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	network := out["network"].(map[string]any)
	if network["source_ip"] != "10.0.0.5" {
		t.Fatalf("expected source_ip alias to resolve src_ip, got %v", network["source_ip"])
	}
	process := out["process"].(map[string]any)
	if process["pid"] != int64(123) {
		t.Fatalf("expected pid to be coerced to int64, got %v (%T)", process["pid"], process["pid"])
	}
}

func TestSysmonMarksAdvisoryOnly(t *testing.T) {
	out, err := Sysmon(map[string]any{"process_name": "rundll32.exe"})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if out["is_advisory"] != true {
		t.Fatalf("expected sysmon output to be marked advisory-only")
	}
}

func TestPowerShellAlwaysReportsScriptExecution(t *testing.T) {
	out, err := PowerShell(map[string]any{"script_block": "Get-Process"})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if out["event_type"] != "script_execution" {
		t.Fatalf("expected event_type=script_execution, got %v", out["event_type"])
	}
	process := out["process"].(map[string]any)
	if process["command_line"] != "Get-Process" {
		t.Fatalf("expected command_line to resolve script_block alias, got %v", process["command_line"])
	}
}

func TestIdentityProviderAndSaasAuditMarkNoAccountAction(t *testing.T) {
	idp, _ := IdentityProvider(map[string]any{"provider": "okta"})
	if idp["no_account_action"] != true || idp["advisory_only"] != true {
		t.Fatalf("expected identity provider output to be advisory-only, no_account_action")
	}
	saas, _ := SaasAudit(map[string]any{"provider": "github"})
	if saas["no_account_action"] != true || saas["advisory_only"] != true {
		t.Fatalf("expected saas audit output to be advisory-only, no_account_action")
	}
}

func TestTicketSyncMarksNoAutoClose(t *testing.T) {
	out, _ := TicketSync(map[string]any{"ticket_id": "JIRA-123"})
	if out["no_auto_close"] != true {
		t.Fatalf("expected ticket sync output to mark no_auto_close")
	}
}

func TestNotificationEventMarksSimulatedByDefault(t *testing.T) {
	out, _ := NotificationEvent(map[string]any{"channel": "slack"})
	if out["simulated"] != true {
		t.Fatalf("expected notification output to be marked simulated")
	}
}

func TestDnsProxyFirewallPreserveTraceAndTenant(t *testing.T) {
	raw := map[string]any{"trace_id": "trace-1", "tenant_id": "tenant-a"}
	for name, fn := range map[string]func(map[string]any) (map[string]any, error){
		"dns": Dns, "proxy": Proxy, "firewall": Firewall,
	} {
		out, err := fn(raw)
		if err != nil {
			t.Fatalf("%s: unexpected error: %v", name, err)
		}
		if out["trace_id"] != "trace-1" || out["tenant_id"] != "tenant-a" {
			t.Fatalf("%s: expected trace_id/tenant_id preserved, got %v/%v", name, out["trace_id"], out["tenant_id"])
		}
	}
}

func TestCefSyslogMarksAdvisoryAndPreservesExtension(t *testing.T) {
	raw := map[string]any{
		"event_type": "suspicious_dns_query", "device_vendor": "Fortinet",
		"source_ip": "10.0.0.5", "destination_ip": "203.0.113.9", "protocol": "UDP",
		"action": "BLOCKED", "user": "alice",
		"cef_extension": map[string]string{"spt": "51820"},
	}
	out, err := CefSyslog(raw)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if out["advisory_only"] != true {
		t.Fatalf("expected cef syslog output to be advisory-only")
	}
	if out["telemetry_type"] != "syslog_cef" || out["device_vendor"] != "Fortinet" {
		t.Fatalf("expected fields preserved, got %v", out)
	}
	if out["protocol"] != "udp" || out["action"] != "blocked" {
		t.Fatalf("expected protocol/action lowercased, got %v/%v", out["protocol"], out["action"])
	}
	ext, ok := out["cef_extension"].(map[string]string)
	if !ok || ext["spt"] != "51820" {
		t.Fatalf("expected cef_extension preserved verbatim, got %v", out["cef_extension"])
	}
}

func TestEventPropagatesTraceparentAsChildSpan(t *testing.T) {
	inbound := traceparent.Generate()
	inboundParsed, _ := traceparent.Parse(inbound)
	raw := map[string]any{
		"ts": "2026-01-01T00:00:00Z", "telemetry_type": "dns", "event_type": "query",
		"traceparent": inbound,
	}
	out, err := Event(raw)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	outTP, _ := out["traceparent"].(string)
	outParsed, err := traceparent.Parse(outTP)
	if err != nil {
		t.Fatalf("expected a valid outbound traceparent, got %q: %v", outTP, err)
	}
	if outParsed.TraceID != inboundParsed.TraceID {
		t.Fatalf("expected trace-id preserved across normalization, got %s vs %s", outParsed.TraceID, inboundParsed.TraceID)
	}
	if outParsed.SpanID == inboundParsed.SpanID {
		t.Fatalf("expected a new span-id for the normalizer hop")
	}
}

func TestEventGeneratesRootTraceparentWhenAbsent(t *testing.T) {
	raw := map[string]any{"ts": "2026-01-01T00:00:00Z", "telemetry_type": "dns", "event_type": "query"}
	out, err := Event(raw)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	outTP, _ := out["traceparent"].(string)
	if _, err := traceparent.Parse(outTP); err != nil {
		t.Fatalf("expected a valid generated traceparent, got %q: %v", outTP, err)
	}
}

func TestRawIntFieldCoercesNumericTypes(t *testing.T) {
	cases := map[string]any{"a": float64(7), "b": int64(8), "c": int(9)}
	for key, want := range map[string]int64{"a": 7, "b": 8, "c": 9} {
		got := rawIntField(cases, key)
		if got != want {
			t.Fatalf("%s: expected %d, got %v", key, want, got)
		}
	}
	if rawIntField(map[string]any{}, "missing") != nil {
		t.Fatalf("expected nil for missing key")
	}
	if rawIntField(map[string]any{"x": "not-a-number"}, "x") != nil {
		t.Fatalf("expected nil for non-numeric type")
	}
}

func TestFirstReturnsFirstNonEmptyAlias(t *testing.T) {
	raw := map[string]any{"b": "value-b", "c": "value-c"}
	if got := first(raw, "a", "b", "c"); got != "value-b" {
		t.Fatalf("expected value-b, got %q", got)
	}
	if got := first(raw, "missing"); got != "" {
		t.Fatalf("expected empty string for missing keys, got %q", got)
	}
}

func TestNumberParsesOrDefaultsToZero(t *testing.T) {
	if got := number("3.5"); got != 3.5 {
		t.Fatalf("expected 3.5, got %v", got)
	}
	if got := number("not-a-number"); got != 0 {
		t.Fatalf("expected 0 for unparseable input, got %v", got)
	}
}
