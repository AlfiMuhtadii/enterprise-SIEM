package main

import (
	"testing"
)

// ---------------------------------------------------------------------------
// makeAlert — demo lineage propagation tests
// ---------------------------------------------------------------------------

func TestMakeAlertNoDemoFields(t *testing.T) {
	events := []Event{
		{EventID: "ev-001", TelemetryType: "identity", EventType: "mfa_failure", User: "alice"},
		{EventID: "ev-002", TelemetryType: "cloud", EventType: "access_key_created", User: "alice"},
	}
	alert := makeAlert("IDENTITY_MFA_THEN_KEY", "alice", events, 0.80)

	// Evidence must NOT contain any demo keys for non-demo events.
	if _, ok := alert.Evidence["demo_lineage_present"]; ok {
		t.Error("demo_lineage_present must not appear in evidence for non-demo events")
	}
	if _, ok := alert.Evidence["demo_run_id"]; ok {
		t.Error("demo_run_id must not appear in evidence for non-demo events")
	}
	if _, ok := alert.Evidence["demo_run_ids"]; ok {
		t.Error("demo_run_ids must not appear in evidence for non-demo events")
	}
	// Standard evidence keys must still be present.
	if _, ok := alert.Evidence["evidence_ids"]; !ok {
		t.Error("evidence_ids must be present")
	}
	if _, ok := alert.Evidence["involved_users"]; !ok {
		t.Error("involved_users must be present")
	}
}

func TestMakeAlertSingleDemoEvent(t *testing.T) {
	demoRunID := "demo-20260622-abc123"
	events := []Event{
		{
			EventID:       "ev-001",
			TelemetryType: "identity",
			EventType:     "mfa_failure",
			User:          "alice",
			TraceID:       demoRunID + "-trace-0001",
			DemoRunID:     demoRunID,
			SourceEventID: "atk-mfa-001",
			ScenarioID:    "sc-test-01",
		},
	}
	alert := makeAlert("IDENTITY_MFA_FAILURE_BURST", "alice", events, 0.70)

	// demo_lineage_present must be true.
	present, ok := alert.Evidence["demo_lineage_present"]
	if !ok || present != true {
		t.Errorf("expected demo_lineage_present=true, got %v", present)
	}
	// Scalar demo_run_id must be set (single contributor).
	if got, _ := alert.Evidence["demo_run_id"].(string); got != demoRunID {
		t.Errorf("expected demo_run_id=%q, got %q", demoRunID, got)
	}
	// demo_run_ids array must also be set.
	ids, _ := alert.Evidence["demo_run_ids"].([]string)
	if len(ids) != 1 || ids[0] != demoRunID {
		t.Errorf("expected demo_run_ids=[%q], got %v", demoRunID, ids)
	}
	// trace_ids must include the trace.
	traceIDs, _ := alert.Evidence["trace_ids"].([]string)
	if len(traceIDs) == 0 {
		t.Error("trace_ids must not be empty for demo event")
	}
	// source_event_ids must include the source event id.
	srcIDs, _ := alert.Evidence["source_event_ids"].([]string)
	if len(srcIDs) == 0 {
		t.Error("source_event_ids must not be empty for demo event")
	}
	// scenario_id must be present.
	if got, _ := alert.Evidence["scenario_id"].(string); got != "sc-test-01" {
		t.Errorf("expected scenario_id=sc-test-01, got %q", got)
	}
}

func TestMakeAlertMultipleDemoEventsSameDemoRun(t *testing.T) {
	demoRunID := "demo-20260622-shared"
	events := []Event{
		{
			EventID:       "ev-001",
			TelemetryType: "identity",
			EventType:     "mfa_failure",
			User:          "alice",
			TraceID:       demoRunID + "-trace-0001",
			DemoRunID:     demoRunID,
			SourceEventID: "atk-mfa-001",
		},
		{
			EventID:       "ev-002",
			TelemetryType: "identity",
			EventType:     "mfa_failure",
			User:          "alice",
			TraceID:       demoRunID + "-trace-0002",
			DemoRunID:     demoRunID,
			SourceEventID: "atk-mfa-002",
		},
		{
			EventID:       "ev-003",
			TelemetryType: "cloud",
			EventType:     "new_access_key_created",
			User:          "alice",
			TraceID:       demoRunID + "-trace-0004",
			DemoRunID:     demoRunID,
			SourceEventID: "atk-cloud-001",
		},
	}
	alert := makeAlert("IDENTITY_MFA_BURST_CLOUD", "alice", events, 0.85)

	// Scalar demo_run_id should be set since all events share the same value.
	if got, _ := alert.Evidence["demo_run_id"].(string); got != demoRunID {
		t.Errorf("expected demo_run_id=%q, got %q", demoRunID, got)
	}
	// trace_ids must include all 3 distinct traces.
	traceIDs, _ := alert.Evidence["trace_ids"].([]string)
	if len(traceIDs) != 3 {
		t.Errorf("expected 3 trace_ids, got %d: %v", len(traceIDs), traceIDs)
	}
	// source_event_ids must include all 3.
	srcIDs, _ := alert.Evidence["source_event_ids"].([]string)
	if len(srcIDs) != 3 {
		t.Errorf("expected 3 source_event_ids, got %d: %v", len(srcIDs), srcIDs)
	}
}

func TestMakeAlertMixedDemoPresentAndAbsent(t *testing.T) {
	// One contributing event has demo fields; the other does not (normal production event).
	demoRunID := "demo-20260622-mixed"
	events := []Event{
		{
			EventID:       "ev-prod-001",
			TelemetryType: "identity",
			EventType:     "mfa_failure",
			User:          "alice",
			// No demo fields — simulates a real production event.
		},
		{
			EventID:       "ev-demo-001",
			TelemetryType: "cloud",
			EventType:     "new_access_key_created",
			User:          "alice",
			DemoRunID:     demoRunID,
			SourceEventID: "atk-demo-001",
			TraceID:       demoRunID + "-trace-0001",
		},
	}
	alert := makeAlert("IDENTITY_MFA_THEN_CLOUD", "alice", events, 0.75)

	// demo_lineage_present must be true since at least one event has a demo_run_id.
	present, _ := alert.Evidence["demo_lineage_present"].(bool)
	if !present {
		t.Error("demo_lineage_present should be true when at least one event has DemoRunID")
	}
	// Only one demo_run_id contributed.
	if got, _ := alert.Evidence["demo_run_id"].(string); got != demoRunID {
		t.Errorf("expected demo_run_id=%q, got %q", demoRunID, got)
	}
}

func TestMakeAlertNoEventsProducesEmptyEvidence(t *testing.T) {
	// Edge case: no events should not panic.
	alert := makeAlert("EMPTY_ALERT", "nobody", []Event{}, 0.5)
	if _, ok := alert.Evidence["evidence_ids"]; !ok {
		t.Error("evidence_ids key must always be present")
	}
	if _, ok := alert.Evidence["demo_lineage_present"]; ok {
		t.Error("demo_lineage_present must not be set when no events provided")
	}
}
