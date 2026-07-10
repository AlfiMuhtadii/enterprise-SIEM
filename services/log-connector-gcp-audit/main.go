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
	"errors"
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

	"detector-xdr-log-connector-gcp-audit/internal/boundedfile"
	"detector-xdr-log-connector-gcp-audit/internal/deliver"
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

	forwardMaxRetries int
	forwardRetryBase  time.Duration
	forwardRetryMax   time.Duration

	// CONN-UNBOUNDED-FILE: size ceilings. 0 disables the corresponding bound.
	maxFileBytes      int64
	maxExpandedBytes  int64
	maxRecordBytes    int64
	quarantineLogPath string

	mu               sync.Mutex
	processedFiles   map[string]bool
	quarantinedFiles map[string]bool

	filesScanned            atomic.Int64
	filesSkipped            atomic.Int64
	filesQuarantined        atomic.Int64
	entriesParsed           atomic.Int64
	oversizedRecordsSkipped atomic.Int64
	forwarded               atomic.Int64
	forwardErrors           atomic.Int64
	parseErrors             atomic.Int64
	deliveryFailedFiles     atomic.Int64
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
		ingestURL:         env("XDR_INGEST_URL", "http://127.0.0.1:8091/v1/ingest"),
		secret:            env("XDR_INGEST_SECRET", "dev-secret-change-me"),
		tenantID:          tenantID,
		batchSize:         envInt("XDR_GCP_AUDIT_BATCH_SIZE", 100),
		watchDir:          *watchDir,
		httpClient:        &http.Client{Timeout: 10 * time.Second, Transport: &http.Transport{}},
		processedFiles:    map[string]bool{},
		quarantinedFiles:  map[string]bool{},
		forwardMaxRetries: envInt("XDR_GCP_AUDIT_FORWARD_MAX_RETRIES", 3),
		forwardRetryBase:  time.Duration(envInt("XDR_GCP_AUDIT_FORWARD_RETRY_BASE_MS", 200)) * time.Millisecond,
		forwardRetryMax:   time.Duration(envInt("XDR_GCP_AUDIT_FORWARD_RETRY_MAX_MS", 2000)) * time.Millisecond,
		maxFileBytes:      int64(envInt("XDR_GCP_AUDIT_MAX_FILE_BYTES", 100*1024*1024)),
		maxExpandedBytes:  int64(envInt("XDR_GCP_AUDIT_MAX_EXPANDED_BYTES", 500*1024*1024)),
		maxRecordBytes:    int64(envInt("XDR_GCP_AUDIT_MAX_RECORD_BYTES", 1024*1024)),
	}
	c.stateFile = filepath.Join(c.watchDir, ".gcp-audit-connector-state.json")
	c.quarantineLogPath = filepath.Join(c.watchDir, ".gcp-audit-connector-quarantine.jsonl")
	c.loadState()
	c.loadQuarantineLog()

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
			"files_scanned":             c.filesScanned.Load(),
			"files_skipped":             c.filesSkipped.Load(),
			"files_quarantined":         c.filesQuarantined.Load(),
			"entries_parsed":            c.entriesParsed.Load(),
			"oversized_records_skipped": c.oversizedRecordsSkipped.Load(),
			"forwarded":                 c.forwarded.Load(),
			"forward_errors":            c.forwardErrors.Load(),
			"parse_errors":              c.parseErrors.Load(),
			"delivery_failed_files":     c.deliveryFailedFiles.Load(),
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
		// The quarantine log (.jsonl) needs its own exclusion here since it
		// matches hasAuditLogExtension, unlike cloudtrail's narrower
		// .json/.json.gz-only filter.
		if path == c.stateFile || path == c.stateFile+".tmp" || path == c.quarantineLogPath {
			return nil
		}
		c.mu.Lock()
		already := c.processedFiles[path] || c.quarantinedFiles[path]
		c.mu.Unlock()
		if already {
			c.filesSkipped.Add(1)
			return nil
		}
		c.processFile(path)
		return nil
	})
}

func hasAuditLogExtension(path string) bool {
	for _, suffix := range []string{".json", ".json.gz", ".jsonl", ".jsonl.gz"} {
		if strings.HasSuffix(path, suffix) {
			return true
		}
	}
	return false
}

// processFile parses one GCP Cloud Audit Log export file and forwards its
// entries.
//
// CONN-DELIVERY-LOSS: this file's entries are batched and delivered
// independently of any other file's — never appended to a shared
// cross-file buffer — so "all derived batches accepted" can be evaluated
// per file. The file is only marked processed (and the state file only
// saved) after every batch derived from it has been forwarded
// successfully (with bounded retry). If any batch exhausts its retries,
// the file is left unprocessed so the next scan cycle retries it from
// scratch — this connector's pre-existing processedFiles/stateFile
// mechanism already gives that restart-safety for free once the
// checkpoint-write ordering is correct.
func (c *Connector) processFile(path string) {
	c.filesScanned.Add(1)
	data, err := boundedfile.Read(path, c.maxFileBytes)
	if errors.Is(err, boundedfile.ErrTooLarge) {
		c.quarantine(path, "file_exceeds_max_file_bytes")
		return
	}
	if err != nil {
		c.parseErrors.Add(1)
		log.Printf("[log-connector-gcp-audit] read error path=%s: %v", path, err)
		return
	}
	entries, oversized, err := gcpaudit.ParseBounded(data, gcpaudit.Limits{
		MaxExpandedBytes: c.maxExpandedBytes,
		MaxRecordBytes:   c.maxRecordBytes,
	})
	if errors.Is(err, gcpaudit.ErrExpandedTooLarge) {
		c.quarantine(path, "decompressed_content_exceeds_max_expanded_bytes")
		return
	}
	if err != nil {
		c.parseErrors.Add(1)
		log.Printf("[log-connector-gcp-audit] parse error path=%s: %v", path, err)
		return
	}
	if oversized > 0 {
		c.oversizedRecordsSkipped.Add(int64(oversized))
		log.Printf("[log-connector-gcp-audit] WARN: skipped %d oversized record(s) (over XDR_GCP_AUDIT_MAX_RECORD_BYTES) in path=%s — other records in the file were still processed", oversized, path)
	}
	c.entriesParsed.Add(int64(len(entries)))

	events := make([]map[string]any, 0, len(entries))
	for _, entry := range entries {
		events = append(events, mapEntryToEvent(entry, c.tenantID))
	}

	for start := 0; start < len(events); start += c.batchSize {
		end := start + c.batchSize
		if end > len(events) {
			end = len(events)
		}
		batch := events[start:end]
		deliverErr := deliver.WithRetry(c.forwardMaxRetries, c.forwardRetryBase, c.forwardRetryMax, func() error {
			return c.forward(batch)
		})
		if deliverErr != nil {
			c.deliveryFailedFiles.Add(1)
			log.Printf("[log-connector-gcp-audit] WARN: forward failed after retries for path=%s (entries %d-%d of %d) — file left unprocessed, will retry on next scan: %v", path, start, end, len(events), deliverErr)
			return
		}
	}

	c.mu.Lock()
	c.processedFiles[path] = true
	c.mu.Unlock()
	c.saveState()
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

// quarantineRecord is one durable, human-readable line in the append-only
// quarantine log — CONN-UNBOUNDED-FILE's constraint that a rejected file
// must leave "a durable rejection record suitable for operator recovery",
// not just a metric bump. The rejected file itself is left in place
// untouched (never deleted/moved) so an operator can inspect it later.
type quarantineRecord struct {
	Path          string `json:"path"`
	Reason        string `json:"reason"`
	QuarantinedAt string `json:"quarantined_at"`
}

// quarantine marks path as permanently skipped (so a multi-GB file isn't
// re-read and re-rejected on every scan) and appends a durable, auditable
// rejection record. See the identical design note in log-connector-cloudtrail.
func (c *Connector) quarantine(path, reason string) {
	c.filesQuarantined.Add(1)
	c.mu.Lock()
	c.quarantinedFiles[path] = true
	c.mu.Unlock()

	log.Printf("[log-connector-gcp-audit] WARN: quarantined path=%s reason=%s — left in place, not retried; see %s", path, reason, c.quarantineLogPath)

	rec := quarantineRecord{Path: path, Reason: reason, QuarantinedAt: time.Now().UTC().Format(time.RFC3339)}
	encoded, err := json.Marshal(rec)
	if err != nil {
		return
	}
	f, err := os.OpenFile(c.quarantineLogPath, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o644)
	if err != nil {
		return
	}
	defer func() { _ = f.Close() }()
	_, _ = f.Write(append(encoded, '\n'))
}

// loadQuarantineLog restores the quarantined-paths set from a prior run's
// append-only log so a restart doesn't re-attempt (and re-reject) the same
// oversized file. Best-effort, matching loadState.
func (c *Connector) loadQuarantineLog() {
	data, err := os.ReadFile(c.quarantineLogPath)
	if err != nil {
		return
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	for _, line := range bytes.Split(data, []byte("\n")) {
		line = bytes.TrimSpace(line)
		if len(line) == 0 {
			continue
		}
		var rec quarantineRecord
		if err := json.Unmarshal(line, &rec); err != nil {
			continue
		}
		c.quarantinedFiles[rec.Path] = true
	}
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
