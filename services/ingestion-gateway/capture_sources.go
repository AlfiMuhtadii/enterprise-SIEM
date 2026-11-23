package main

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"regexp"
	"strings"
	"time"
)

const captureAdmissionField = "capture_admission"

var captureToken = regexp.MustCompile(`^[a-z0-9][a-z0-9._-]{0,63}$`)

type captureSourceConfig struct {
	SourceID         string   `json:"source_id"`
	TenantID         string   `json:"tenant_id"`
	ClientCertSHA256 string   `json:"client_cert_sha256"`
	TelemetryTypes   []string `json:"telemetry_types"`
}

type captureSource struct {
	id, tenant string
	types      map[string]bool
}

type captureSources map[[sha256.Size]byte]captureSource

type captureAdmission struct {
	Method     string `json:"method"`
	SourceID   string `json:"source_id"`
	ReceivedAt string `json:"received_at"`
}

func loadCaptureSources(path string, serverMTLS, sigV2 bool, secret string) (captureSources, error) {
	if path == "" {
		return nil, nil
	}
	if !serverMTLS || !sigV2 || len(strings.TrimSpace(secret)) < 32 || secret == "dev-secret-change-me" {
		return nil, fmt.Errorf("capture admission requires server mTLS, signature v2, and a configured ingest secret")
	}
	f, err := os.Open(path)
	if err != nil {
		return nil, fmt.Errorf("open capture source registry: %w", err)
	}
	defer f.Close()

	const maxConfig = 1024 * 1024
	raw, err := io.ReadAll(io.LimitReader(f, maxConfig+1))
	if err != nil || len(raw) > maxConfig {
		return nil, fmt.Errorf("unreadable or oversized capture source registry")
	}
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.DisallowUnknownFields()
	var entries []captureSourceConfig
	if err := decoder.Decode(&entries); err != nil || len(entries) == 0 || len(entries) > 256 {
		return nil, fmt.Errorf("invalid capture source registry")
	}
	if decoder.Decode(new(any)) != io.EOF {
		return nil, fmt.Errorf("unexpected data after capture source registry")
	}

	result := make(captureSources, len(entries))
	ids := make(map[string]bool, len(entries))
	for i, entry := range entries {
		fingerprint, err := hex.DecodeString(entry.ClientCertSHA256)
		if err != nil || len(fingerprint) != sha256.Size || !captureToken.MatchString(entry.SourceID) ||
			!validCaptureIdentity(entry.TenantID) || len(entry.TelemetryTypes) == 0 {
			return nil, fmt.Errorf("invalid capture source entry %d", i+1)
		}
		key := [sha256.Size]byte(fingerprint)
		if _, exists := result[key]; exists || ids[entry.SourceID] {
			return nil, fmt.Errorf("duplicate capture source or certificate in entry %d", i+1)
		}
		allowed := make(map[string]bool)
		for _, kind := range entry.TelemetryTypes {
			if !captureToken.MatchString(kind) || allowed[kind] {
				return nil, fmt.Errorf("invalid or duplicate telemetry type in capture source entry %d", i+1)
			}
			allowed[kind] = true
		}
		ids[entry.SourceID] = true
		result[key] = captureSource{id: entry.SourceID, tenant: entry.TenantID, types: allowed}
	}
	return result, nil
}

func validCaptureIdentity(value string) bool {
	return value != "" && len(value) <= 255 && strings.TrimSpace(value) == value && !strings.ContainsRune(value, 0)
}

func (sources captureSources) verifiedClient(r *http.Request) (captureSource, bool) {
	if len(sources) == 0 || r.TLS == nil {
		return captureSource{}, false
	}
	for _, chain := range r.TLS.VerifiedChains {
		if len(chain) == 0 || chain[0] == nil {
			continue
		}
		if source, found := sources[sha256.Sum256(chain[0].Raw)]; found {
			return source, true
		}
	}
	return captureSource{}, false
}

func (sources captureSources) apply(r *http.Request, events []map[string]any, at time.Time) (bool, string) {
	// The reserved field can only come from this gateway, even when admission is disabled.
	for _, event := range events {
		delete(event, captureAdmissionField)
	}
	source, found := sources.verifiedClient(r)
	if !found {
		return false, ""
	}
	for _, event := range events {
		if tenant, _ := event["tenant_id"].(string); tenant != source.tenant {
			return false, "capture_tenant_mismatch"
		}
		if kind, _ := event["telemetry_type"].(string); !source.types[kind] {
			return false, "capture_telemetry_out_of_scope"
		}
		if id, _ := event["event_id"].(string); !validCaptureIdentity(id) {
			return false, "capture_event_id_required"
		}
	}
	stamp := captureAdmission{Method: "verified_mtls", SourceID: source.id, ReceivedAt: at.UTC().Format(time.RFC3339Nano)}
	for _, event := range events {
		event[captureAdmissionField] = stamp
	}
	return true, ""
}
