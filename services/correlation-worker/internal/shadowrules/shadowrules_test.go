package shadowrules

import "testing"

func TestEpStrNestedPathAndMissing(t *testing.T) {
	ev := map[string]any{
		"host": "web-01",
		"network": map[string]any{
			"source_ip": "10.0.0.1",
		},
	}
	if got := EpStr(ev, "host"); got != "web-01" {
		t.Errorf("expected web-01, got %q", got)
	}
	if got := EpStr(ev, "network", "source_ip"); got != "10.0.0.1" {
		t.Errorf("expected 10.0.0.1, got %q", got)
	}
	if got := EpStr(ev, "network", "missing_field"); got != "" {
		t.Errorf("expected empty string for missing field, got %q", got)
	}
	if got := EpStr(ev, "not_a_map", "deeper"); got != "" {
		t.Errorf("expected empty string when path traverses a non-map, got %q", got)
	}
}

func TestEpInt64Coercion(t *testing.T) {
	ev := map[string]any{
		"pid_float": float64(1234),
		"pid_int64": int64(5678),
		"pid_int":   9012,
		"pid_str":   "not_a_number",
	}
	if got := EpInt64(ev, "pid_float"); got != 1234 {
		t.Errorf("expected 1234 from float64, got %d", got)
	}
	if got := EpInt64(ev, "pid_int64"); got != 5678 {
		t.Errorf("expected 5678 from int64, got %d", got)
	}
	if got := EpInt64(ev, "pid_int"); got != 9012 {
		t.Errorf("expected 9012 from int, got %d", got)
	}
	if got := EpInt64(ev, "pid_str"); got != 0 {
		t.Errorf("expected 0 for non-numeric type, got %d", got)
	}
	if got := EpInt64(ev, "missing"); got != 0 {
		t.Errorf("expected 0 for missing field, got %d", got)
	}
}

func TestMakeEndpointAlertDeterministicID(t *testing.T) {
	ev := map[string]any{"host": "web-01", "user": "alice", "normalized_event_id": "evt-1", "event_type": "login"}
	a1 := MakeEndpointAlert("rule_x", "v1", "Title", "high", 0.8, ev)
	a2 := MakeEndpointAlert("rule_x", "v1", "Title", "high", 0.8, ev)
	if a1.AlertID != a2.AlertID {
		t.Errorf("expected deterministic AlertID for identical inputs, got %q vs %q", a1.AlertID, a2.AlertID)
	}
	if a1.Host != "web-01" || a1.User != "alice" {
		t.Errorf("expected Host/User propagated from event, got Host=%q User=%q", a1.Host, a1.User)
	}
	if !a1.ShadowMode {
		t.Error("expected ShadowMode to always be true for shadow-rule alerts")
	}

	a3 := MakeEndpointAlert("rule_y", "v1", "Title", "high", 0.8, ev)
	if a1.AlertID == a3.AlertID {
		t.Error("expected different AlertID for a different rule ID on the same event")
	}
}

func TestMakeEndpointAlertFallsBackToRawEventID(t *testing.T) {
	ev := map[string]any{"host": "web-01", "raw_event_id": "raw-1"}
	a := MakeEndpointAlert("rule_x", "v1", "Title", "high", 0.8, ev)
	if len(a.EvidenceIDs) != 1 || a.EvidenceIDs[0] != "raw-1" {
		t.Errorf("expected fallback to raw_event_id, got %v", a.EvidenceIDs)
	}
}

func TestDedupeEndpointAlertsRemovesDuplicateAlertIDs(t *testing.T) {
	ev := map[string]any{"host": "web-01", "normalized_event_id": "evt-1"}
	a := MakeEndpointAlert("rule_x", "v1", "Title", "high", 0.8, ev)
	alerts := []EndpointAlert{a, a, a}
	out := DedupeEndpointAlerts(alerts)
	if len(out) != 1 {
		t.Errorf("expected 1 deduped alert, got %d", len(out))
	}
}

func TestCorrelateNetworkShadowAllEmptyEvents(t *testing.T) {
	if got := CorrelateNetworkShadowAll(nil); got != nil {
		t.Errorf("expected nil for empty events, got %v", got)
	}
	if got := CorrelateNetworkShadowAll([]map[string]any{}); got != nil {
		t.Errorf("expected nil for empty events slice, got %v", got)
	}
}

func TestRuleNetworkSuspiciousDnsTldFiresOnKnownBadTld(t *testing.T) {
	events := []map[string]any{
		{"telemetry_type": "dns", "host_id": "host-1", "queried_domain": "evil.ru"},
		{"telemetry_type": "dns", "host_id": "host-1", "queried_domain": "example.com"},
	}
	alerts := ruleNetworkSuspiciousDnsTld(events)
	if len(alerts) != 1 {
		t.Fatalf("expected exactly 1 alert for the .ru domain, got %d", len(alerts))
	}
	if alerts[0].RuleID != "suspicious_dns_tld" {
		t.Errorf("expected rule ID suspicious_dns_tld, got %q", alerts[0].RuleID)
	}
	if alerts[0].Evidence["no_blocking"] != true {
		t.Error("expected no_blocking=true in evidence — advisory-only network rule")
	}
}

func TestRuleNetworkSuspiciousDnsTldIgnoresNonDnsEvents(t *testing.T) {
	events := []map[string]any{
		{"telemetry_type": "proxy", "host_id": "host-1", "queried_domain": "evil.ru"},
	}
	if alerts := ruleNetworkSuspiciousDnsTld(events); len(alerts) != 0 {
		t.Errorf("expected 0 alerts for non-dns telemetry_type, got %d", len(alerts))
	}
}

func TestCorrelateNetworkShadowAllAggregatesAcrossRules(t *testing.T) {
	events := []map[string]any{
		{"telemetry_type": "dns", "host_id": "host-1", "queried_domain": "evil.ru"},
	}
	alerts := CorrelateNetworkShadowAll(events)
	if len(alerts) == 0 {
		t.Fatal("expected at least one alert from the aggregated network rule set")
	}
	for _, a := range alerts {
		if !a.ShadowMode {
			t.Errorf("expected every network shadow alert to have ShadowMode=true, rule=%s", a.RuleID)
		}
	}
}
