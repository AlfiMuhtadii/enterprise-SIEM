package shadowrules

import (
	"bytes"
	"encoding/json"
	"fmt"
	"reflect"
	"testing"
	"time"
)

var dnsTestMinute = time.Date(2026, 11, 27, 10, 0, 0, 0, time.UTC)

func testDNSRecord(offset int64, second int, tenant, host, origin, id string) DNSWindowRecord {
	observed := dnsTestMinute.Add(time.Duration(second) * time.Second)
	return DNSWindowRecord{Offset: offset, ArrivedAt: observed.Add(time.Second), Query: &DNSWindowQuery{
		Tenant: tenant, Host: host, Origin: origin, Observed: observed,
		Event: DNSWindowEvent{ID: id, Trace: "trace-" + id, TXT: true},
	}}
}

func advanceDNS(t *testing.T, state DNSWindowState, records ...DNSWindowRecord) (DNSWindowState, []DNSWindow) {
	t.Helper()
	next, closed, err := state.Advance(records)
	if err != nil {
		t.Fatal(err)
	}
	return next, closed
}

func TestDNSWindowSurvivesPollSplitAndCheckpointRoundTrip(t *testing.T) {
	one := testDNSRecord(10, 2, "tenant-a", "client-a", "unverified", "one")
	two := testDNSRecord(11, 15, "tenant-a", "client-a", "unverified", "two")
	clock := DNSWindowRecord{Offset: 12, ArrivedAt: dnsTestMinute.Add(DNSWindowDuration + DNSWindowLateness)}
	initial := NewDNSWindowState(10)
	full, whole := advanceDNS(t, initial, one, two, clock)
	partial, closed := advanceDNS(t, initial, one)
	if len(closed) != 0 || partial.NextOffset != 11 {
		t.Fatal("first poll closed the window or did not advance its checkpoint")
	}
	checkpoint, err := json.Marshal(partial)
	if err != nil {
		t.Fatal(err)
	}
	var resumed DNSWindowState
	if err := json.Unmarshal(checkpoint, &resumed); err != nil {
		t.Fatal(err)
	}
	split, remaining := advanceDNS(t, resumed, one, two, clock) // first record redelivered at its original offset
	if len(remaining) != 1 || !reflect.DeepEqual(split, full) || !reflect.DeepEqual(remaining, whole) {
		t.Fatalf("different event-time results across polls and restore: %#v", remaining)
	}
	findings := remaining[0].Findings()
	if len(findings) != 1 || findings[0].TenantID != "tenant-a" ||
		findings[0].EvidenceOrigin != "unverified" || findings[0].Evidence["aggregation_mode"] != "dns-event-time-v1" ||
		findings[0].TraceID != "" || !reflect.DeepEqual(findings[0].EvidenceIDs, []string{"one", "two"}) {
		t.Fatalf("unexpected advisory finding: %#v", findings)
	}
	again, replayed := advanceDNS(t, split, one, two, clock)
	if !reflect.DeepEqual(again, split) || len(replayed) != 0 {
		t.Fatal("redelivered offsets reopened a closed window")
	}
}

func TestDNSWindowDoesNotCombineTenantClientOriginOrAdjacentWindows(t *testing.T) {
	input := []DNSWindowRecord{
		testDNSRecord(0, 1, "tenant-a", "client-a", "unverified", "first"),
		testDNSRecord(1, 2, "tenant-b", "client-a", "unverified", "different-tenant"),
		testDNSRecord(2, 3, "tenant-a", "client-b", "unverified", "different-client"),
		testDNSRecord(3, 4, "tenant-a", "client-a", "rehearsal", "rehearsal"),
		testDNSRecord(4, 61, "tenant-a", "client-a", "unverified", "next-window"),
		{Offset: 5, ArrivedAt: dnsTestMinute.Add(3 * time.Minute)},
	}
	_, windows := advanceDNS(t, NewDNSWindowState(0), input...)
	if len(windows) != 5 {
		t.Fatalf("expected five independent buckets, got %d", len(windows))
	}
	for _, window := range windows {
		if len(window.Events) != 1 || len(window.Findings()) != 0 {
			t.Fatalf("unrelated evidence combined: %#v", window)
		}
	}
}

func TestDNSWindowDeduplicationAndDeadline(t *testing.T) {
	first := testDNSRecord(0, 5, "tenant-a", "client-a", "unverified", "first")
	duplicate := first
	duplicate.Offset++
	withinGrace := testDNSRecord(2, 25, "tenant-a", "client-a", "unverified", "during-grace")
	withinGrace.ArrivedAt = dnsTestMinute.Add(89 * time.Second)
	tooLate := testDNSRecord(3, 35, "tenant-a", "client-a", "unverified", "at-deadline")
	tooLate.ArrivedAt = dnsTestMinute.Add(90 * time.Second)
	laterWatermark := DNSWindowRecord{Offset: 4, ArrivedAt: dnsTestMinute.Add(70 * time.Second)}
	next, windows := advanceDNS(t, NewDNSWindowState(0), first, duplicate, withinGrace, tooLate, laterWatermark)
	if next.Late != 1 || len(windows) != 1 || len(windows[0].Events) != 2 ||
		!next.Watermark.Equal(tooLate.ArrivedAt) {
		t.Fatalf("wrong deadline or duplicate handling: %#v %#v", next, windows)
	}
}

func TestDNSWindowRejectsGapsCheckpointCorruptionAndConflicts(t *testing.T) {
	one := testDNSRecord(0, 1, "", "client-a", "unverified", "a")
	state, _ := advanceDNS(t, NewDNSWindowState(0), one)
	conflict := testDNSRecord(1, 2, "", "client-a", "unverified", "a")
	conflict.Query.Event.Trace = "conflicting-trace"
	for _, nextRecord := range []DNSWindowRecord{{Offset: 2, ArrivedAt: one.ArrivedAt}, conflict} {
		next, closed, err := state.Advance([]DNSWindowRecord{nextRecord})
		if err == nil || len(closed) != 0 || !reflect.DeepEqual(next, state) {
			t.Fatal("bad input changed or closed a saved window")
		}
	}
	bad := state
	bad.Open = append(append([]DNSWindow(nil), state.Open...), state.Open[0])
	if _, _, err := bad.Advance(nil); err == nil {
		t.Fatal("duplicate checkpoint bucket accepted")
	}
	future := testDNSRecord(1, 22, "", "client-a", "unverified", "future")
	future.ArrivedAt = one.ArrivedAt
	next, _ := advanceDNS(t, state, future)
	if next.Invalid != 1 || len(next.Open[0].Events) != 1 || len(state.Open[0].Events) != 1 {
		t.Fatal("bad event time affected valid evidence")
	}
}

func TestDNSWindowFailsClosedOnEvidenceCapacity(t *testing.T) {
	initial := NewDNSWindowState(0)
	var records []DNSWindowRecord
	for i := 0; i <= maxNetworkLineageRefs; i++ {
		records = append(records, testDNSRecord(int64(i), 5, "", "client-a", "unverified", fmt.Sprintf("query-%d", i)))
	}
	got, closed, err := initial.Advance(records)
	if err == nil || len(closed) != 0 || !reflect.DeepEqual(got, initial) {
		t.Fatal("overflow acknowledged an incomplete window")
	}
}

func TestDNSWindowProjectsNormalizedEvidenceAndDoesNotStoreDNSNames(t *testing.T) {
	input := map[string]any{
		"telemetry_type": "dns", "event_type": "dns_query", "host_id": "alias",
		"tenant_id": "tenant-a", "trace_id": "trace", "normalized_event_id": "event",
		"ts": dnsTestMinute.Format(time.RFC3339Nano), "query_type": "A", "response_code": "NXDOMAIN",
		"queried_domain": "private-name.detector.test",
	}
	query := DNSWindowQueryFromNormalized(input, "unverified")
	if query == nil || query.Event != (DNSWindowEvent{ID: "event", Trace: "trace", NX: true}) {
		t.Fatalf("unexpected normalized evidence: %#v", query)
	}
	state, _ := advanceDNS(t, NewDNSWindowState(0),
		DNSWindowRecord{Offset: 0, ArrivedAt: dnsTestMinute, Query: query})
	wire, err := json.Marshal(state)
	if err != nil || len(wire) == 0 {
		t.Fatalf("checkpoint serialization failed: %v", err)
	}
	if bytes.Contains(wire, []byte("private-name.detector.test")) {
		t.Fatal("DNS name leaked into the checkpoint")
	}
	input["tenant_id"] = 42
	badQuery := DNSWindowQueryFromNormalized(input, "unverified")
	next, _ := advanceDNS(t, state, DNSWindowRecord{Offset: 1, ArrivedAt: dnsTestMinute, Query: badQuery})
	if next.Invalid != 1 || len(next.Open) != 1 || len(next.Open[0].Events) != 1 {
		t.Fatal("invalid tenant entered the unassigned scope")
	}
}
