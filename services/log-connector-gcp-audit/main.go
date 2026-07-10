// Command log-connector-gcp-audit is a CONNECTOR-FRAMEWORK phase-6
// ingestion bridge: it watches a local directory for GCP Cloud Audit Log
// export files (written by a GCP log sink to Cloud Storage — NDJSON, one
// LogEntry object per line) and forwards every entry through the existing
// HMAC-signed ingestion-gateway /v1/ingest endpoint.
//
// Like log-connector-cloudtrail and log-connector-guardduty, this is
// deliberately scoped as FILE-based ingestion of already-exported logs (an
// operator points this at a local sync of the GCS bucket a log sink writes
// to, e.g. via "gsutil rsync" or "gcloud storage rsync" on a cron) — NOT
// live GCP Logging API polling, which would require GCP credentials this
// environment cannot exercise or verify.
//
// All events still flow through the existing normalize -> correlate
// shadow path; this connector adds no new active alert domain.
package main

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"flag"
	"fmt"
	"io/fs"
	"log"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"syscall"
	"time"

	"detector-xdr-log-connector-gcp-audit/internal/gcpaudit"
	"detector-xdr-log-connector-gcp-audit/internal/mtls"
)

// Connector watches a directory for GCP Cloud Audit Log export files, maps
// each entry into the platform's generic telemetry.raw event shape, and
// forwards batches to ingestion-gateway.
type Connector struct {
	ingestURL  string
	secret     string
	tenantID   string
	batchSize  int
	watchDir   string
	stateFile  string
	httpClient *http.Client

	mu             sync.Mutex
	buffer         []map[string]any
	processedFiles map[string]bool

	filesScanned  atomic.Int64
	filesSkipped  atomic.Int64
	entriesParsed atomic.Int64
	forwarded     atomic.Int64
	forwardErrors atomic.Int64
	parseErrors   atomic.Int64
}

func main() {
	watchDir := flag.String("watch-dir", env("XDR_GCP_AUDIT_WATCH_DIR", "./gcp-audit-logs"), "directory to scan for GCP Cloud Audit Log export files")
	metricsAddr := flag.String("metrics-addr", env("XDR_GCP_AUDIT_METRICS_ADDR", ":8099"), "health/metrics listen address")
	flag.Parse()

	tenantID := env("XDR_GCP_AUDIT_TENANT_ID", "")
	if err := validateTenantConfig(tenantID, envBool("XDR_GCP_AUDIT_REQUIRE_TENANT", false)); err != nil {
		log.Fatalf("[log-connector-gcp-audit] %v", err)
	}

	c := &Connector{
		ingestURL:      env("XDR_INGEST_URL", "http://127.0.0.1:8091/v1/ingest"),
		secret:         env("XDR_INGEST_SECRET", "dev-secret-change-me"),
		tenantID:       tenantID,
		batchSize:      envInt("XDR_GCP_AUDIT_BATCH_SIZE", 100),
		watchDir:       *watchDir,
		httpClient:     &http.Client{Timeout: 10 * time.Second, Transport: &http.Transport{}},
		processedFiles: map[string]bool{},
	}
	c.stateFile = filepath.Join(c.watchDir, ".gcp-audit-connector-state.json")
	c.loadState()

	// ENT-SEC-NO-TLS-INTERNAL: internal mTLS, disabled by default. Same
	// mechanism proven on ingestion-gateway/normalizer-worker/correlation-worker.
	mtlsEnabled := envBool("XDR_INTERNAL_MTLS_ENABLED", false)
	mtlsCA := env("XDR_INTERNAL_MTLS_CA", "")
	serverTLSCfg, err := mtls.ServerConfig(mtlsEnabled,
		env("XDR_INTERNAL_MTLS_SERVER_CERT", ""),
		env("XDR_INTERNAL_MTLS_SERVER_KEY", ""),
		mtlsCA,
	)
	if err != nil {
		log.Fatalf("[log-connector-gcp-audit] internal mTLS server config error: %v", err)
	}
	clientTLSCfg, err := mtls.ClientConfig(mtlsEnabled,
		env("XDR_INTERNAL_MTLS_CLIENT_CERT", ""),
		env("XDR_INTERNAL_MTLS_CLIENT_KEY", ""),
		mtlsCA,
	)
	if err != nil {
		log.Fatalf("[log-connector-gcp-audit] internal mTLS client config error: %v", err)
	}
	if clientTLSCfg != nil {
		if t, ok := c.httpClient.Transport.(*http.Transport); ok {
			t.TLSClientConfig = clientTLSCfg
		}
	}

	stop := make(chan struct{})
	c.startPoller(time.Duration(envInt("XDR_GCP_AUDIT_POLL_SECONDS", 30))*time.Second, stop)

	mux := http.NewServeMux()
	mux.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "service": "log-connector-gcp-audit"})
	})
	mux.HandleFunc("/metrics", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, map[string]any{
			"files_scanned":  c.filesScanned.Load(),
			"files_skipped":  c.filesSkipped.Load(),
			"entries_parsed": c.entriesParsed.Load(),
			"forwarded":      c.forwarded.Load(),
			"forward_errors": c.forwardErrors.Load(),
			"parse_errors":   c.parseErrors.Load(),
		})
	})

	server := &http.Server{
		Addr:              *metricsAddr,
		Handler:           mux,
		ReadHeaderTimeout: 10 * time.Second,
		TLSConfig:         serverTLSCfg,
	}
	go func() {
		sigCh := make(chan os.Signal, 1)
		signal.Notify(sigCh, os.Interrupt, syscall.SIGTERM)
		<-sigCh
		log.Printf("[log-connector-gcp-audit] shutting down gracefully")
		close(stop)
		_ = server.Close()
	}()

	log.Printf("[log-connector-gcp-audit] watching dir=%s metrics=%s ingest=%s internal_mtls=%v", c.watchDir, *metricsAddr, c.ingestURL, mtlsEnabled)
	var serveErr error
	if serverTLSCfg != nil {
		serveErr = server.ListenAndServeTLS("", "")
	} else {
		serveErr = server.ListenAndServe()
	}
	if serveErr != nil && serveErr != http.ErrServerClosed {
		log.Fatal(serveErr)
	}
}

func (c *Connector) startPoller(interval time.Duration, stop <-chan struct{}) {
	c.scanOnce()
	go func() {
		ticker := time.NewTicker(interval)
		defer ticker.Stop()
		for {
			select {
			case <-ticker.C:
				c.scanOnce()
			case <-stop:
				return
			}
		}
	}()
}

// scanOnce walks watchDir recursively (a GCS-synced log sink layout nests
// files under <log-id>/<year>/<month>/<day>/...), processing every
// .json/.json.gz/.jsonl/.jsonl.gz file not already recorded in
// processedFiles. The connector's own state file is explicitly excluded
// (same fix already applied to log-connector-cloudtrail/-guardduty).
func (c *Connector) scanOnce() {
	_ = filepath.WalkDir(c.watchDir, func(path string, d fs.DirEntry, err error) error {
		if err != nil || d.IsDir() {
			return nil
		}
		if !hasAuditLogExtension(path) {
			return nil
		}
		if path == c.stateFile || path == c.stateFile+".tmp" {
			return nil
		}
		c.mu.Lock()
		already := c.processedFiles[path]
		c.mu.Unlock()
		if already {
			c.filesSkipped.Add(1)
			return nil
		}
		c.processFile(path)
		return nil
	})
	c.flush()
}

func hasAuditLogExtension(path string) bool {
	for _, suffix := range []string{".json", ".json.gz", ".jsonl", ".jsonl.gz"} {
		if strings.HasSuffix(path, suffix) {
			return true
		}
	}
	return false
}

func (c *Connector) processFile(path string) {
	c.filesScanned.Add(1)
	data, err := os.ReadFile(path)
	if err != nil {
		c.parseErrors.Add(1)
		log.Printf("[log-connector-gcp-audit] read error path=%s: %v", path, err)
		return
	}
	entries, err := gcpaudit.Parse(data)
	if err != nil {
		c.parseErrors.Add(1)
		log.Printf("[log-connector-gcp-audit] parse error path=%s: %v", path, err)
		return
	}
	c.mu.Lock()
	for _, entry := range entries {
		c.buffer = append(c.buffer, mapEntryToEvent(entry, c.tenantID))
	}
	c.processedFiles[path] = true
	full := len(c.buffer) >= c.batchSize
	c.mu.Unlock()
	c.entriesParsed.Add(int64(len(entries)))
	c.saveState()
	if full {
		c.flush()
	}
}

// mapEntryToEvent maps one GCP Cloud Audit Log entry into the generic
// telemetry.raw event shape, using the same canonical field names the
// normalizer's existing generic fallback envelope already recognizes
// (cloud_account/user/source_ip/action/result/event_source) — no
// normalizer-worker change is needed, matching every other connector in
// this framework.
func mapEntryToEvent(e gcpaudit.LogEntry, tenantID string) map[string]any {
	result := "success"
	if e.HasErrorStatus() {
		result = "error"
	}
	event := map[string]any{
		"ts":              e.Timestamp,
		"telemetry_type":  "gcp_audit",
		"event_type":      e.MethodName(),
		"event_source":    e.ServiceName(),
		"event_id":        e.InsertID,
		"cloud_account":   e.ProjectID(),
		"action":          e.MethodName(),
		"user":            e.PrincipalEmail(),
		"source_ip":       e.CallerIP(),
		"result":          result,
		"severity":        e.Severity,
		"gcp_audit_entry": e.Raw,
	}
	if tenantID != "" {
		event["tenant_id"] = tenantID
	}
	return event
}

func (c *Connector) flush() {
	c.mu.Lock()
	if len(c.buffer) == 0 {
		c.mu.Unlock()
		return
	}
	batch := c.buffer
	c.buffer = nil
	c.mu.Unlock()
	if err := c.forward(batch); err != nil {
		log.Printf("[log-connector-gcp-audit] forward error: %v", err)
	}
}

// forward sends a batch of events to ingestion-gateway's /v1/ingest, signed
// with the same HMAC-SHA256 sigv2 scheme ("ts.body") ingestion-gateway
// itself verifies — no separate trust path, same pattern as every other
// log-connector-* service.
func (c *Connector) forward(events []map[string]any) error {
	if len(events) == 0 {
		return nil
	}
	body, err := json.Marshal(events)
	if err != nil {
		return err
	}
	ts := time.Now().Unix()
	req, err := http.NewRequest(http.MethodPost, c.ingestURL, bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-XDR-Timestamp", strconv.FormatInt(ts, 10))
	if c.secret != "" {
		req.Header.Set("X-XDR-Signature", sign(c.secret, ts, body))
	}
	resp, err := c.httpClient.Do(req)
	if err != nil {
		c.forwardErrors.Add(1)
		return err
	}
	defer func() { _ = resp.Body.Close() }()
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		c.forwardErrors.Add(1)
		return fmt.Errorf("ingest_status=%d", resp.StatusCode)
	}
	c.forwarded.Add(int64(len(events)))
	return nil
}

func sign(secret string, ts int64, body []byte) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(strconv.FormatInt(ts, 10)))
	mac.Write([]byte("."))
	mac.Write(body)
	return "sha256=" + hex.EncodeToString(mac.Sum(nil))
}

func (c *Connector) loadState() {
	data, err := os.ReadFile(c.stateFile)
	if err != nil {
		return
	}
	var files []string
	if err := json.Unmarshal(data, &files); err != nil {
		return
	}
	c.mu.Lock()
	for _, f := range files {
		c.processedFiles[f] = true
	}
	c.mu.Unlock()
}

func (c *Connector) saveState() {
	c.mu.Lock()
	files := make([]string, 0, len(c.processedFiles))
	for f := range c.processedFiles {
		files = append(files, f)
	}
	c.mu.Unlock()

	data, err := json.Marshal(files)
	if err != nil {
		return
	}
	tmp := c.stateFile + ".tmp"
	if err := os.WriteFile(tmp, data, 0o644); err != nil {
		return
	}
	_ = os.Rename(tmp, c.stateFile)
}

func writeJSON(w http.ResponseWriter, status int, value any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(value)
}

func env(name, fallback string) string {
	if value := os.Getenv(name); value != "" {
		return value
	}
	return fallback
}

func envInt(name string, fallback int) int {
	value, err := strconv.Atoi(env(name, ""))
	if err != nil {
		return fallback
	}
	return value
}

func envBool(name string, fallback bool) bool {
	value := os.Getenv(name)
	if value == "" {
		return fallback
	}
	return value == "1" || value == "true" || value == "yes"
}

// validateTenantConfig enforces CONN-UNTENANTED-INGEST's startup-refusal
// requirement: in strict/production mode (XDR_GCP_AUDIT_REQUIRE_TENANT=true),
// a connector with no assigned tenant refuses to start rather than silently
// forwarding unattributed telemetry that ingestion-gateway's own
// tenantAllowed("") would otherwise accept by default. Disabled by default
// (requireTenant=false) preserves the existing behavior byte-for-byte.
func validateTenantConfig(tenantID string, requireTenant bool) error {
	if requireTenant && tenantID == "" {
		return fmt.Errorf("XDR_GCP_AUDIT_REQUIRE_TENANT=true but XDR_GCP_AUDIT_TENANT_ID is not set — refusing to start untenanted")
	}
	return nil
}
