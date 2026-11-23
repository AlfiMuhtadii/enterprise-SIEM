package main

import (
	"bytes"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestCaptureSourceRegistryRequiresSecureConfiguration(t *testing.T) {
	key := sha256.Sum256([]byte("test client certificate"))
	secret := strings.Repeat("a", 32)
	entry := fmt.Sprintf(`{"source_id":"sensor-local","tenant_id":"tenant-a","client_cert_sha256":"%s","telemetry_types":["endpoint"]}`,
		hex.EncodeToString(key[:]))
	path := filepath.Join(t.TempDir(), "sources.json")
	write := func(content string) {
		t.Helper()
		if err := os.WriteFile(path, []byte(content), 0600); err != nil {
			t.Fatal(err)
		}
	}
	write("[" + entry + "]")
	sources, err := loadCaptureSources(path, true, true, secret)
	if err != nil || sources[key].id != "sensor-local" || !sources[key].types["endpoint"] {
		t.Fatalf("valid registry did not load: %v", err)
	}
	for _, test := range []struct {
		mtls, sigv2 bool
		secret      string
	}{
		{false, true, secret},
		{true, false, secret},
		{true, true, "dev-secret-change-me"},
	} {
		if _, err := loadCaptureSources(path, test.mtls, test.sigv2, test.secret); err == nil {
			t.Fatal("capture admission accepted weak configuration")
		}
	}
	for _, bad := range []string{
		"null", "[]", "[" + entry + "," + entry + "]", "[" + entry + "]null",
		"[" + strings.Replace(entry, "source_id", "typo", 1) + "]",
		"[" + strings.Replace(entry, `["endpoint"]`, `["endpoint","endpoint"]`, 1) + "]",
	} {
		write(bad)
		if _, err := loadCaptureSources(path, true, true, secret); err == nil {
			t.Fatal("invalid capture source registry was accepted")
		}
	}
}

func TestCaptureAdmissionRequiresVerifiedLeafAndScopedBatch(t *testing.T) {
	leaf := &x509.Certificate{Raw: []byte("registered leaf")}
	key := sha256.Sum256(leaf.Raw)
	sources := captureSources{key: {id: "sensor-local", tenant: "tenant-a", types: map[string]bool{"endpoint": true}}}
	req := httptest.NewRequest(http.MethodPost, "/v1/ingest", nil)
	req.TLS = &tls.ConnectionState{PeerCertificates: []*x509.Certificate{leaf}}
	event := map[string]any{"event_id": "obs-a", "tenant_id": "tenant-a", "telemetry_type": "endpoint", captureAdmissionField: "invalid"}
	at := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	batch := []map[string]any{event}
	if admitted, reason := sources.apply(req, batch, at); admitted || reason != "" || event[captureAdmissionField] != nil {
		t.Fatalf("unverified peer incorrectly produced admission: %v %s %v", admitted, reason, event)
	}

	req.TLS.VerifiedChains = [][]*x509.Certificate{{leaf}}
	for _, second := range []map[string]any{
		{"event_id": "obs-b", "tenant_id": "tenant-b", "telemetry_type": "endpoint"},
		{"event_id": "obs-b", "tenant_id": "tenant-a", "telemetry_type": "dns"},
		{"tenant_id": "tenant-a", "telemetry_type": "endpoint"},
	} {
		if admitted, reason := sources.apply(req, append(batch, second), at); admitted || reason == "" || event[captureAdmissionField] != nil {
			t.Fatalf("invalid trailing record partly admitted: %v %s %v", admitted, reason, event)
		}
	}
	admitted, reason := sources.apply(req, batch, at)
	if stamp, ok := event[captureAdmissionField].(captureAdmission); !admitted || reason != "" || !ok ||
		stamp.SourceID != "sensor-local" || stamp.Method != "verified_mtls" || stamp.ReceivedAt != "2026-01-01T00:00:00Z" {
		t.Fatalf("unexpected approved stamp: %v %s %v", admitted, reason, event)
	}
}

func TestIngestStripsAdmissionFromOrdinaryClientsAndRejectsNullRecords(t *testing.T) {
	received := make(chan map[string]any, 1)
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var body struct {
			Records []struct{ Value map[string]any }
		}
		if err := json.NewDecoder(r.Body).Decode(&body); err != nil || len(body.Records) != 1 {
			w.WriteHeader(http.StatusBadRequest)
			return
		}
		received <- body.Records[0].Value
		w.WriteHeader(http.StatusOK)
	}))
	defer srv.Close()
	gw := newTestGateway(srv.URL)
	response := postIngest(gw, []byte(`[{"event_id":"ordinary-1","capture_admission":{"source_id":"not-from-gateway"}}]`), "")
	if response.Code != http.StatusAccepted {
		t.Fatalf("ordinary admission changed: %v", response.Code)
	}
	if event := <-received; event[captureAdmissionField] != nil || gw.captureAdmitted.Load() != 0 {
		t.Fatalf("ordinary event got unearned provenance: %v", event)
	}
	req := httptest.NewRequest(http.MethodPost, "/v1/ingest", bytes.NewBufferString("[null]"))
	response = httptest.NewRecorder()
	gw.ingest(response, req)
	if response.Code != http.StatusBadRequest {
		t.Fatalf("null event was not rejected: %d", response.Code)
	}
}
