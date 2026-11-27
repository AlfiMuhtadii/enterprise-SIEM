package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
	"time"

	"detector-xdr-correlation-worker/internal/shadowrules"

	"github.com/twmb/franz-go/pkg/kgo"
)

func testNormalizedDNS(t *testing.T, offset int64, base time.Time, elapsed time.Duration, kind string) *kgo.Record {
	t.Helper()
	at := base.Add(elapsed)
	value, err := json.Marshal(map[string]any{
		"ts": at, "telemetry_type": "dns", "query_type": kind, "host_id": "lab-client",
		"normalized_event_id": time.Duration(offset).String(), "tenant_id": "lab",
	})
	if err != nil {
		t.Fatal(err)
	}
	return &kgo.Record{Topic: "telemetry.normalized", Offset: offset, Timestamp: at, Value: value}
}

func TestDNSCheckpointRetainsPendingFindingAfterPublishFailure(t *testing.T) {
	path := filepath.Join(t.TempDir(), "state.json")
	store, err := openDNSCheckpointStore(path)
	if err != nil {
		t.Fatal(err)
	}
	if other, err := openDNSCheckpointStore(path); err == nil {
		other.close()
		store.close()
		t.Fatal("two processes could own the same checkpoint")
	}
	start := time.Date(2026, 11, 27, 10, 0, 0, 0, time.UTC)
	lane := dnsWindowLane{store: store, checkpoint: dnsCheckpoint{
		Source: dnsSource{Topic: "telemetry.normalized", Cluster: "test"},
		State:  shadowrules.NewDNSWindowState(0),
	}}
	first := testNormalizedDNS(t, 0, start, time.Second, "TXT")
	if err := lane.persistInput([]*kgo.Record{first}); err != nil {
		t.Fatal(err)
	}
	store.close()
	store, err = openDNSCheckpointStore(path)
	if err != nil {
		t.Fatal(err)
	}
	lane.store = store
	saved, err := store.load()
	if err != nil || saved == nil || saved.Anchor != dnsRecordAnchor(first) || len(saved.State.Open) != 1 {
		t.Fatalf("did not reopen first poll checkpoint: %v", err)
	}
	lane.checkpoint = *saved
	second := testNormalizedDNS(t, 1, start, 2*time.Second, "TXT")
	closeRecord := testNormalizedDNS(t, 2, start, 90*time.Second, "A")
	if err := lane.persistInput([]*kgo.Record{second, closeRecord}); err != nil {
		t.Fatal(err)
	}
	var unconfirmed string
	lane.publish = func(alerts []shadowrules.EndpointAlert) bool {
		if len(alerts) != 1 || len(alerts[0].EvidenceIDs) != 2 {
			t.Fatal("reopened window lost lineage")
		}
		unconfirmed = alerts[0].AlertID
		return false
	}
	if err := lane.publishPending(); err == nil {
		t.Fatal("unconfirmed publication reported success")
	}
	store.close()
	store, err = openDNSCheckpointStore(path)
	if err != nil {
		t.Fatal(err)
	}
	defer store.close()
	saved, err = store.load()
	if err != nil || saved == nil || saved.State.NextOffset != 3 || len(saved.Pending) != 1 {
		t.Fatalf("failed output did not survive store reopen: %v", err)
	}
	lane.store, lane.checkpoint = store, *saved
	lane.publish = func(alerts []shadowrules.EndpointAlert) bool {
		if len(alerts) != 1 || alerts[0].AlertID != unconfirmed {
			t.Fatal("retry changed the finding identity")
		}
		return true
	}
	if err := lane.publishPending(); err != nil {
		t.Fatal(err)
	}
	cleared, err := store.load()
	if err != nil || cleared == nil || cleared.State.NextOffset != 3 || len(cleared.Pending) != 0 {
		t.Fatalf("acknowledged publication did not clear the outbox: %v", err)
	}
}

func TestDNSCheckpointRejectsCorruptStorage(t *testing.T) {
	path := filepath.Join(t.TempDir(), "state.json")
	store, err := openDNSCheckpointStore(path)
	if err != nil {
		t.Fatal(err)
	}
	defer store.close()
	if err := os.WriteFile(path, []byte(`{"sha256":"wrong","checkpoint":{}}`), 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := store.load(); err == nil {
		t.Fatal("corrupt state was accepted")
	}
}
