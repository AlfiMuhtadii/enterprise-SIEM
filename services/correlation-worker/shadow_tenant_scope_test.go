package main

import (
	"strconv"
	"testing"

	"detector-xdr-correlation-worker/internal/shadowrules"
)

func TestEndpointShadowThresholdsAndIDsAreTenantScoped(t *testing.T) {
	var events []map[string]any
	add := func(tenant string, index int) {
		events = append(events, map[string]any{
			"tenant_id": tenant, "telemetry_type": "endpoint", "event_type": "login_event",
			"host": "endpt-1", "user": "local-user", "normalized_event_id": strconv.Itoa(index),
			"auth": map[string]any{"action": "login_failed"},
		})
	}
	for _, tenant := range []string{"tenant-a", "tenant-b"} {
		add(tenant, 0)
		add(tenant, 1)
	}
	if alerts := correlateEndpointShadowAll(events, ""); len(alerts) != 0 {
		t.Fatalf("findings combined evidence from distinct tenants: %+v", alerts)
	}

	add("tenant-a", 2)
	add("tenant-b", 2)
	alerts := correlateEndpointShadowAll(events, "")
	if len(alerts) != 2 || alerts[0].TenantID != "tenant-a" || alerts[1].TenantID != "tenant-b" {
		t.Fatalf("expected one finding per tenant: %+v", alerts)
	}
	if alerts[0].AlertID == alerts[1].AlertID {
		t.Fatal("identical source identities collided across tenants")
	}
}

func TestNetworkShadowDoesNotJoinTenantAndUnassignedEvidence(t *testing.T) {
	var events []map[string]any
	for _, tenant := range []string{"tenant-a", ""} {
		for i := 0; i < 3; i++ {
			events = append(events, map[string]any{
				"tenant_id": tenant, "telemetry_type": "dns", "response_code": "NXDOMAIN", "host_id": "local-host",
			})
		}
	}
	if alerts := evaluateShadowByScope(events, shadowrules.CorrelateNetworkShadowAll); len(alerts) != 0 {
		t.Fatalf("expected no combined tenant/unassigned network findings: %+v", alerts)
	}
}

func TestShadowEnvelopesNeverBorrowTenantOrTrace(t *testing.T) {
	alerts := []shadowrules.EndpointAlert{
		{AlertID: "first", TenantID: "tenant-a", TraceID: "shared"},
		{AlertID: "second", TenantID: "tenant-b", TraceID: "shared"},
		{AlertID: "third", TenantID: "tenant-a"},
	}
	envelopes := shadowEnvelopes("endpoint-shadow", alerts)
	if len(envelopes) != len(alerts) {
		t.Fatalf("expected separate envelopes, got %+v", envelopes)
	}
	for i, envelope := range envelopes {
		rows := envelope["alerts"].([]shadowrules.EndpointAlert)
		if len(rows) != 1 || rows[0].AlertID != alerts[i].AlertID ||
			envelope["trace_id"] != alerts[i].TraceID || envelope["tenant_id"] != alerts[i].TenantID {
			t.Fatalf("envelope metadata does not match its finding: %+v", envelope)
		}
	}
}

func TestShadowPublicationRefusesBothActiveTopics(t *testing.T) {
	w := &Worker{outputTopic: "custom.active"}
	alerts := []shadowrules.EndpointAlert{{AlertID: "advisory", ShadowMode: true}}
	for _, topic := range []string{"xdr.alerts", "custom.active"} {
		if w.publishShadowFindings(topic, "endpoint-shadow", alerts) {
			t.Fatalf("advisory publication to active topic %s was accepted", topic)
		}
	}
	if w.publishErrors.Load() != 2 {
		t.Fatalf("refusals were not counted: %d", w.publishErrors.Load())
	}
}

func TestShadowRulesAndEnvelopesSeparateRehearsalFromUnverified(t *testing.T) {
	var events []map[string]any
	for i := 0; i < 3; i++ {
		ev := map[string]any{
			"tenant_id": "tenant-a", "trace_id": "same-trace", "telemetry_type": "endpoint",
			"event_type": "login_event", "host": "same-host", "normalized_event_id": strconv.Itoa(i),
			"auth": map[string]any{"action": "login_failed"},
		}
		copy := map[string]any{}
		for key, val := range ev {
			copy[key] = val
		}
		copy["shadow_evidence_origin"] = "rehearsal"
		events = append(events, ev, copy)
	}
	if alerts := correlateEndpointShadowAll(events[:4], ""); len(alerts) != 0 {
		t.Fatalf("mixed sources satisfied a rule threshold: %+v", alerts)
	}
	alerts := correlateEndpointShadowAll(events, "")
	if len(alerts) != 2 || alerts[0].AlertID == alerts[1].AlertID ||
		alerts[0].EvidenceOrigin != "unverified" || alerts[1].EvidenceOrigin != "rehearsal" {
		t.Fatalf("expected distinct findings with their source labels: %+v", alerts)
	}
	envelopes := shadowEnvelopes("endpoint-shadow", alerts)
	if len(envelopes) != 2 || envelopes[0]["shadow_evidence_origin"] != "unverified" ||
		envelopes[1]["shadow_evidence_origin"] != "rehearsal" {
		t.Fatalf("envelopes merged different evidence classes: %+v", envelopes)
	}
}
