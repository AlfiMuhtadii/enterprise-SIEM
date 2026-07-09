package main

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strconv"
	"testing"
	"time"

	"detector-xdr-log-connector-syslog/internal/cef"
	"detector-xdr-log-connector-syslog/internal/leef"
	"detector-xdr-log-connector-syslog/internal/registry"
)

func TestMapCEFToEventPromotesCommonFields(t *testing.T) {
	msg, err := cef.Parse(`CEF:0|Fortinet|FortiGate|7.0|0100|Suspicious DNS Query|5|src=10.0.0.5 dst=203.0.113.9 spt=51820 dpt=53 proto=UDP act=blocked suser=alice`)
	if err != nil {
		t.Fatalf("unexpected parse error: %v", err)
	}
	now := time.Date(2026, 7, 9, 10, 0, 0, 0, time.UTC)
	event := mapCEFToEvent(msg, "tenant-a", now)

	if event["telemetry_type"] != "syslog_cef" {
		t.Fatalf("expected telemetry_type=syslog_cef, got %v", event["telemetry_type"])
	}
	if event["event_type"] != "suspicious_dns_query" {
		t.Fatalf("expected event_type derived from CEF Name, got %v", event["event_type"])
	}
	if event["source_ip"] != "10.0.0.5" || event["destination_ip"] != "203.0.113.9" {
		t.Fatalf("expected src/dst promoted, got %v/%v", event["source_ip"], event["destination_ip"])
	}
	if event["protocol"] != "udp" {
		t.Fatalf("expected protocol lowercased, got %v", event["protocol"])
	}
	if event["action"] != "blocked" || event["user"] != "alice" {
		t.Fatalf("expected act/suser promoted, got action=%v user=%v", event["action"], event["user"])
	}
	if event["tenant_id"] != "tenant-a" {
		t.Fatalf("expected tenant_id preserved, got %v", event["tenant_id"])
	}
	ext, ok := event["cef_extension"].(map[string]string)
	if !ok || ext["spt"] != "51820" {
		t.Fatalf("expected full extension preserved verbatim, got %v", event["cef_extension"])
	}
}

func TestMapCEFToEventDefaultsEventTypeWhenNameEmpty(t *testing.T) {
	msg, err := cef.Parse(`CEF:0|Vendor|Product|1.0|100||5|`)
	if err != nil {
		t.Fatalf("unexpected parse error: %v", err)
	}
	event := mapCEFToEvent(msg, "", time.Now())
	if event["event_type"] != "cef_event" {
		t.Fatalf("expected cef_event fallback, got %v", event["event_type"])
	}
	if _, hasTenant := event["tenant_id"]; hasTenant {
		t.Fatalf("expected no tenant_id key when tenantID is empty")
	}
}

func TestMapLEEFToEventPromotesCommonFields(t *testing.T) {
	msg, err := leef.Parse("LEEF:2.0|Vendor|Firewall|1.0|100|^|src=10.0.0.5^dst=203.0.113.9^srcPort=51820^dstPort=53^proto=UDP^cat=blocked^usrName=alice")
	if err != nil {
		t.Fatalf("unexpected parse error: %v", err)
	}
	now := time.Date(2026, 7, 10, 10, 0, 0, 0, time.UTC)
	event := mapLEEFToEvent(msg, "tenant-a", now)

	if event["telemetry_type"] != "syslog_leef" {
		t.Fatalf("expected telemetry_type=syslog_leef, got %v", event["telemetry_type"])
	}
	if event["event_type"] != "100" {
		t.Fatalf("expected event_type derived from LEEF EventID, got %v", event["event_type"])
	}
	if event["source_ip"] != "10.0.0.5" || event["destination_ip"] != "203.0.113.9" {
		t.Fatalf("expected src/dst promoted, got %v/%v", event["source_ip"], event["destination_ip"])
	}
	if event["protocol"] != "udp" {
		t.Fatalf("expected protocol lowercased, got %v", event["protocol"])
	}
	if event["action"] != "blocked" || event["user"] != "alice" {
		t.Fatalf("expected cat/usrName promoted, got action=%v user=%v", event["action"], event["user"])
	}
	if event["tenant_id"] != "tenant-a" {
		t.Fatalf("expected tenant_id preserved, got %v", event["tenant_id"])
	}
	ext, ok := event["leef_extension"].(map[string]string)
	if !ok || ext["srcPort"] != "51820" {
		t.Fatalf("expected full extension preserved verbatim, got %v", event["leef_extension"])
	}
}

func TestMapLEEFToEventDefaultsEventTypeWhenEventIDEmpty(t *testing.T) {
	msg, err := leef.Parse("LEEF:1.0|Vendor|Product|1.0||")
	if err != nil {
		t.Fatalf("unexpected parse error: %v", err)
	}
	event := mapLEEFToEvent(msg, "", time.Now())
	if event["event_type"] != "leef_event" {
		t.Fatalf("expected leef_event fallback, got %v", event["event_type"])
	}
	if _, hasTenant := event["tenant_id"]; hasTenant {
		t.Fatalf("expected no tenant_id key when tenantID is empty")
	}
}

func TestMapRegistryToEventPromotesFieldMapAndPreservesExtension(t *testing.T) {
	def := registry.SourceDefinition{
		Name:           "generic_fw",
		Marker:         "APPFW:",
		TelemetryType:  "syslog_generic_kv",
		EventTypeField: "act",
		FieldMap: map[string]string{
			"source_ip": "src",
			"action":    "act",
			"user":      "suser",
		},
	}
	msg := def.Parse("APPFW: src=10.0.0.5 act=blocked suser=alice extra=kept")
	now := time.Date(2026, 7, 10, 10, 0, 0, 0, time.UTC)
	event := mapRegistryToEvent(msg, "tenant-a", now)

	if event["telemetry_type"] != "syslog_generic_kv" {
		t.Fatalf("expected configured telemetry_type, got %v", event["telemetry_type"])
	}
	if event["event_type"] != "blocked" {
		t.Fatalf("expected event_type from configured event_type_field, got %v", event["event_type"])
	}
	if event["source_ip"] != "10.0.0.5" || event["action"] != "blocked" || event["user"] != "alice" {
		t.Fatalf("expected field_map fields promoted, got source_ip=%v action=%v user=%v",
			event["source_ip"], event["action"], event["user"])
	}
	if event["source_type"] != "generic_fw" {
		t.Fatalf("expected source_type set to source definition name, got %v", event["source_type"])
	}
	if event["tenant_id"] != "tenant-a" {
		t.Fatalf("expected tenant_id preserved, got %v", event["tenant_id"])
	}
	ext, ok := event["generic_extension"].(map[string]string)
	if !ok || ext["extra"] != "kept" {
		t.Fatalf("expected unmapped key preserved verbatim in generic_extension, got %v", event["generic_extension"])
	}
}

func TestMapRegistryToEventDefaultsWhenEventTypeFieldMissing(t *testing.T) {
	def := registry.SourceDefinition{Name: "bare", Marker: "X:", FieldMap: map[string]string{}}
	msg := def.Parse("X: a=1")
	event := mapRegistryToEvent(msg, "", time.Now())
	if event["event_type"] != "bare_event" {
		t.Fatalf("expected fallback event_type, got %v", event["event_type"])
	}
	if event["telemetry_type"] != "syslog_generic_kv" {
		t.Fatalf("expected fallback telemetry_type, got %v", event["telemetry_type"])
	}
	if _, hasTenant := event["tenant_id"]; hasTenant {
		t.Fatalf("expected no tenant_id key when tenantID is empty")
	}
}

func TestProcessLineDispatchesToRegistryWhenMarkerMatches(t *testing.T) {
	reg := &registry.Registry{Sources: []registry.SourceDefinition{
		{
			Name:           "generic_fw",
			Marker:         "APPFW:",
			TelemetryType:  "syslog_generic_kv",
			EventTypeField: "act",
			FieldMap:       map[string]string{"source_ip": "src", "action": "act"},
		},
	}}
	out := processLine("APPFW: src=1.2.3.4 act=deny", "", time.Now(), reg)
	if out["telemetry_type"] != "syslog_generic_kv" {
		t.Fatalf("expected registry dispatch, got %v", out["telemetry_type"])
	}
	if out["source_ip"] != "1.2.3.4" || out["action"] != "deny" {
		t.Fatalf("expected registry field_map applied, got %v", out)
	}
}

func TestProcessLineFallsBackToRawWhenRegistryHasNoMatch(t *testing.T) {
	reg := &registry.Registry{Sources: []registry.SourceDefinition{
		{Name: "generic_fw", Marker: "APPFW:", FieldMap: map[string]string{}},
	}}
	out := processLine("this line matches nothing configured", "", time.Now(), reg)
	if out["telemetry_type"] != "syslog_raw" {
		t.Fatalf("expected raw fallback when registry has no match, got %v", out["telemetry_type"])
	}
}

func TestShippedSampleRegistryConfigParsesEndToEnd(t *testing.T) {
	reg, err := registry.Load("parsers.sample.json")
	if err != nil {
		t.Fatalf("failed to load shipped parsers.sample.json: %v", err)
	}
	line := "<134>Jul 10 10:00:00 fw-1 APPFW: src=10.0.0.5 dst=203.0.113.9 spt=51820 dpt=443 proto=TCP act=blocked suser=alice"
	out := processLine(line, "", time.Now(), reg)
	if out["telemetry_type"] != "syslog_generic_kv" {
		t.Fatalf("expected the shipped sample source to match, got %v", out["telemetry_type"])
	}
	if out["source_ip"] != "10.0.0.5" || out["destination_ip"] != "203.0.113.9" || out["action"] != "blocked" || out["user"] != "alice" {
		t.Fatalf("expected shipped field_map applied, got %v", out)
	}
}

func TestMapRawToEventPreservesLine(t *testing.T) {
	event := mapRawToEvent("not a cef line at all", "tenant-b", time.Now())
	if event["telemetry_type"] != "syslog_raw" || event["event_type"] != "unparsed" {
		t.Fatalf("expected syslog_raw/unparsed envelope, got %v/%v", event["telemetry_type"], event["event_type"])
	}
	if event["raw_message"] != "not a cef line at all" {
		t.Fatalf("expected raw line preserved verbatim, got %v", event["raw_message"])
	}
}

func TestProcessLineDispatchesAndSkipsBlank(t *testing.T) {
	if processLine("   \n", "", time.Now(), nil) != nil {
		t.Fatalf("expected nil for blank line")
	}
	cefLine := `CEF:0|V|P|1.0|100|Name|5|src=1.2.3.4`
	out := processLine(cefLine, "", time.Now(), nil)
	if out["telemetry_type"] != "syslog_cef" {
		t.Fatalf("expected CEF dispatch, got %v", out["telemetry_type"])
	}
	leefLine := "LEEF:1.0|V|P|1.0|100|src=1.2.3.4"
	out = processLine(leefLine, "", time.Now(), nil)
	if out["telemetry_type"] != "syslog_leef" {
		t.Fatalf("expected LEEF dispatch, got %v", out["telemetry_type"])
	}
	rawLine := "plain syslog message, no CEF/LEEF marker"
	out = processLine(rawLine, "", time.Now(), nil)
	if out["telemetry_type"] != "syslog_raw" {
		t.Fatalf("expected raw fallback dispatch, got %v", out["telemetry_type"])
	}
}

func TestSignMatchesIngestionGatewaySigV2Scheme(t *testing.T) {
	secret := "test-secret"
	body := []byte(`[{"a":1}]`)
	ts := int64(1700000000)

	got := sign(secret, ts, body)

	// Independently recompute using the exact algorithm ingestion-gateway's
	// verifySignature expects (sha256=HMAC(ts + "." + body)), to prove this
	// connector's signature is verifiable by the real gateway, not just
	// self-consistent.
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(strconv.FormatInt(ts, 10)))
	mac.Write([]byte("."))
	mac.Write(body)
	want := "sha256=" + hex.EncodeToString(mac.Sum(nil))

	if got != want {
		t.Fatalf("signature mismatch: got %q, want %q", got, want)
	}
}

func TestForwardSendsSignedBatchToIngestURL(t *testing.T) {
	var capturedBody []map[string]any
	var capturedSig, capturedTS string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		capturedSig = r.Header.Get("X-XDR-Signature")
		capturedTS = r.Header.Get("X-XDR-Timestamp")
		_ = json.NewDecoder(r.Body).Decode(&capturedBody)
		w.WriteHeader(http.StatusAccepted)
	}))
	defer server.Close()

	c := &Connector{
		ingestURL:  server.URL,
		secret:     "shared-secret",
		httpClient: &http.Client{Timeout: 5 * time.Second},
	}
	events := []map[string]any{{"telemetry_type": "syslog_cef", "event_type": "x"}}
	if err := c.forward(events); err != nil {
		t.Fatalf("unexpected forward error: %v", err)
	}
	if capturedSig == "" || capturedTS == "" {
		t.Fatalf("expected signature and timestamp headers to be set")
	}
	if len(capturedBody) != 1 || capturedBody[0]["event_type"] != "x" {
		t.Fatalf("expected forwarded body to match batch, got %v", capturedBody)
	}
	if c.forwarded.Load() != 1 {
		t.Fatalf("expected forwarded counter=1, got %d", c.forwarded.Load())
	}
}

func TestForwardRecordsErrorOnNonSuccessStatus(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusUnauthorized)
	}))
	defer server.Close()

	c := &Connector{ingestURL: server.URL, secret: "s", httpClient: &http.Client{Timeout: 5 * time.Second}}
	if err := c.forward([]map[string]any{{"a": 1}}); err == nil {
		t.Fatalf("expected error on non-2xx response")
	}
	if c.forwardErrors.Load() != 1 {
		t.Fatalf("expected forwardErrors counter=1, got %d", c.forwardErrors.Load())
	}
}

func TestIngestLineFlushesAtBatchSize(t *testing.T) {
	var requestCount int
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requestCount++
		w.WriteHeader(http.StatusAccepted)
	}))
	defer server.Close()

	c := &Connector{
		ingestURL:  server.URL,
		secret:     "s",
		batchSize:  2,
		httpClient: &http.Client{Timeout: 5 * time.Second},
	}
	c.ingestLine("plain line one")
	if requestCount != 0 {
		t.Fatalf("expected no flush yet, got %d requests", requestCount)
	}
	c.ingestLine("plain line two")
	if requestCount != 1 {
		t.Fatalf("expected exactly one flush at batch size, got %d requests", requestCount)
	}
	if c.received.Load() != 2 || c.parsedRaw.Load() != 2 {
		t.Fatalf("expected received=2 parsedRaw=2, got received=%d parsedRaw=%d", c.received.Load(), c.parsedRaw.Load())
	}
}
