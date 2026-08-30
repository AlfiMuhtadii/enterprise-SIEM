// Command log-connector-guardduty is a CONNECTOR-FRAMEWORK phase-5
// ingestion bridge: it watches a local directory for AWS GuardDuty finding
// export files (GuardDuty's native "export findings" feature — NDJSON, one
// finding object per line, gzip-compressed by default) and forwards every
// finding through the existing HMAC-signed ingestion-gateway /v1/ingest
// endpoint.
//
// Like log-connector-cloudtrail, this is deliberately scoped as FILE-based
// ingestion of already-exported findings (an operator points this at a
// local sync of the S3 bucket GuardDuty's export feature writes to) — NOT
// live GuardDuty GetFindings API polling, which would require AWS
// credentials this environment cannot exercise or verify.
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

	"detector-xdr-log-connector-guardduty/internal/boundedfile"
	"detector-xdr-log-connector-guardduty/internal/deliver"
	"detector-xdr-log-connector-guardduty/internal/guardduty"
	"detector-xdr-log-connector-guardduty/internal/mtls"
)

// Connector watches a directory for GuardDuty finding export files, maps
// each finding into the platform's generic telemetry.raw event shape, and
// forwards batches to ingestion-gateway.
type Connector struct {
	ingestURL  string
	secret     string
	tenantID   string
	batchSize  int
	watchDir   string
	stateFile  string
	httpClient *http.Client

	mu               sync.Mutex
	processedFiles   map[string]bool
	quarantinedFiles map[string]bool

	// CONN-DELIVERY-LOSS: bounded retry before a file is left unprocessed.
	forwardMaxRetries int
	forwardRetryBase  time.Duration
	forwardRetryMax   time.Duration

	// CONN-UNBOUNDED-FILE: size ceilings. 0 disables the corresponding bound.
	maxFileBytes      int64
	maxExpandedBytes  int64
	maxRecordBytes    int64
	quarantineLogPath string

	filesScanned            atomic.Int64
	filesSkipped            atomic.Int64
	filesQuarantined        atomic.Int64
	findingsParsed          atomic.Int64
	oversizedRecordsSkipped atomic.Int64
	forwarded               atomic.Int64
	forwardErrors           atomic.Int64
	parseErrors             atomic.Int64
	deliveryFailedFiles     atomic.Int64
}

func main() {
	watchDir := flag.String("watch-dir", env("XDR_GUARDDUTY_WATCH_DIR", "./guardduty-findings"), "directory to scan for GuardDuty finding export files")
	metricsAddr := flag.String("metrics-addr", env("XDR_GUARDDUTY_METRICS_ADDR", ":8098"), "health/metrics listen address")
	flag.Parse()

	tenantID := env("XDR_GUARDDUTY_TENANT_ID", "")
	if err := validateTenantConfig(tenantID, envBool("XDR_GUARDDUTY_REQUIRE_TENANT", false)); err != nil {
		log.Fatalf("[log-connector-guardduty] %v", err)
	}

	c := &Connector{
		ingestURL:         env("XDR_INGEST_URL", "http://127.0.0.1:8091/v1/ingest"),
		secret:            env("XDR_INGEST_SECRET", "dev-secret-change-me"),
		tenantID:          tenantID,
		batchSize:         envInt("XDR_GUARDDUTY_BATCH_SIZE", 100),
		watchDir:          *watchDir,
		httpClient:        &http.Client{Timeout: 10 * time.Second, Transport: &http.Transport{}},
		processedFiles:    map[string]bool{},
		quarantinedFiles:  map[string]bool{},
		forwardMaxRetries: envInt("XDR_GUARDDUTY_FORWARD_MAX_RETRIES", 3),
		forwardRetryBase:  time.Duration(envInt("XDR_GUARDDUTY_FORWARD_RETRY_BASE_MS", 200)) * time.Millisecond,
		forwardRetryMax:   time.Duration(envInt("XDR_GUARDDUTY_FORWARD_RETRY_MAX_MS", 2000)) * time.Millisecond,
		maxFileBytes:      int64(envInt("XDR_GUARDDUTY_MAX_FILE_BYTES", 100*1024*1024)),
		maxExpandedBytes:  int64(envInt("XDR_GUARDDUTY_MAX_EXPANDED_BYTES", 500*1024*1024)),
		maxRecordBytes:    int64(envInt("XDR_GUARDDUTY_MAX_RECORD_BYTES", 1024*1024)),
	}
	c.stateFile = filepath.Join(c.watchDir, ".guardduty-connector-state.json")
	c.quarantineLogPath = filepath.Join(c.watchDir, ".guardduty-connector-quarantine.jsonl")
	c.loadState()
	c.loadQuarantineLog()

	// ENT-SEC-NO-TLS-INTERNAL: internal mTLS, disabled by default. Same
	// mechanism proven on ingestion-gateway/normalizer-worker/correlation-worker.
	serverMtlsEnabled, clientMtlsEnabled := internalMtlsModes()
	mtlsCA := env("XDR_INTERNAL_MTLS_CA", "")
	serverTLSCfg, err := mtls.ServerConfig(serverMtlsEnabled,
		env("XDR_INTERNAL_MTLS_SERVER_CERT", ""),
		env("XDR_INTERNAL_MTLS_SERVER_KEY", ""),
		mtlsCA,
	)
	if err != nil {
		log.Fatalf("[log-connector-guardduty] internal mTLS server config error: %v", err)
	}
	clientTLSCfg, err := mtls.ClientConfig(clientMtlsEnabled,
		env("XDR_INTERNAL_MTLS_CLIENT_CERT", ""),
		env("XDR_INTERNAL_MTLS_CLIENT_KEY", ""),
		mtlsCA,
	)
	if err != nil {
		log.Fatalf("[log-connector-guardduty] internal mTLS client config error: %v", err)
	}
	if clientTLSCfg != nil {
		if t, ok := c.httpClient.Transport.(*http.Transport); ok {
			t.TLSClientConfig = clientTLSCfg
		}
	}

	stop := make(chan struct{})
	c.startPoller(time.Duration(envInt("XDR_GUARDDUTY_POLL_SECONDS", 30))*time.Second, stop)

	mux := http.NewServeMux()
	mux.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "service": "log-connector-guardduty"})
	})
	mux.HandleFunc("/metrics", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, map[string]any{
			"files_scanned":             c.filesScanned.Load(),
			"files_skipped":             c.filesSkipped.Load(),
			"files_quarantined":         c.filesQuarantined.Load(),
			"findings_parsed":           c.findingsParsed.Load(),
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
		log.Printf("[log-connector-guardduty] shutting down gracefully")
		close(stop)
		_ = server.Close()
	}()

	log.Printf("[log-connector-guardduty] watching dir=%s metrics=%s ingest=%s server_mtls=%v client_mtls=%v", c.watchDir, *metricsAddr, c.ingestURL, serverMtlsEnabled, clientMtlsEnabled)
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

// scanOnce walks watchDir recursively (GuardDuty's real S3 export layout
// nests files under AWSLogs/<account>/GuardDuty/<region>/<year>/<month>/
// <day>/...), processing every .json/.json.gz/.jsonl/.jsonl.gz file not
// already recorded in processedFiles.
func (c *Connector) scanOnce() {
	_ = filepath.WalkDir(c.watchDir, func(path string, d fs.DirEntry, err error) error {
		if err != nil || d.IsDir() {
			return nil
		}
		if !hasFindingsExtension(path) {
			return nil
		}
		// The connector's own state file lives inside watchDir and would
		// otherwise be re-scanned as a candidate export file every poll —
		// see the identical fix in log-connector-cloudtrail. The quarantine
		// log (.jsonl) needs the same exclusion here since it matches this
		// connector's own hasFindingsExtension check, unlike cloudtrail's
		// narrower .json/.json.gz-only filter.
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

func hasFindingsExtension(path string) bool {
	for _, suffix := range []string{".json", ".json.gz", ".jsonl", ".jsonl.gz"} {
		if strings.HasSuffix(path, suffix) {
			return true
		}
	}
	return false
}

// processFile parses one GuardDuty findings export file and forwards its
// findings.
//
// CONN-DELIVERY-LOSS: this file's findings are batched and delivered
// independently of any other file's — never appended to a shared
// cross-file buffer — so "all derived batches accepted" can be evaluated
// per file. The checkpoint (processedFiles[path]=true + saveState) is
// committed ONLY after every batch derived from this file has been
// successfully delivered (with bounded retry via deliver.WithRetry). If
// any batch fails after retries are exhausted, the file is deliberately
// left unprocessed — retried in full on the next scan. See the identical
// design note in log-connector-cloudtrail.
func (c *Connector) processFile(path string) {
	c.filesScanned.Add(1)
	data, err := boundedfile.Read(path, c.maxFileBytes)
	if errors.Is(err, boundedfile.ErrTooLarge) {
		c.quarantine(path, "file_exceeds_max_file_bytes")
		return
	}
	if err != nil {
		c.parseErrors.Add(1)
		log.Printf("[log-connector-guardduty] read error path=%s: %v", path, err)
		return
	}
	findings, oversized, err := guardduty.ParseBounded(data, guardduty.Limits{
		MaxExpandedBytes: c.maxExpandedBytes,
		MaxRecordBytes:   c.maxRecordBytes,
	})
	if errors.Is(err, guardduty.ErrExpandedTooLarge) {
		c.quarantine(path, "decompressed_content_exceeds_max_expanded_bytes")
		return
	}
	if err != nil {
		c.parseErrors.Add(1)
		log.Printf("[log-connector-guardduty] parse error path=%s: %v", path, err)
		return
	}
	if oversized > 0 {
		c.oversizedRecordsSkipped.Add(int64(oversized))
		log.Printf("[log-connector-guardduty] WARN: skipped %d oversized record(s) (over XDR_GUARDDUTY_MAX_RECORD_BYTES) in path=%s — other records in the file were still processed", oversized, path)
	}
	c.findingsParsed.Add(int64(len(findings)))

	events := make([]map[string]any, 0, len(findings))
	for _, finding := range findings {
		events = append(events, mapFindingToEvent(finding, c.tenantID))
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
			log.Printf("[log-connector-guardduty] WARN: forward failed after retries for path=%s (findings %d-%d of %d) — file left unprocessed, will retry on next scan: %v", path, start, end, len(events), deliverErr)
			return
		}
	}

	c.mu.Lock()
	c.processedFiles[path] = true
	c.mu.Unlock()
	c.saveState()
}

// mapFindingToEvent maps one GuardDuty finding into the generic
// telemetry.raw event shape, using the same canonical field names the
// normalizer's existing generic fallback envelope already recognizes
// (cloud_account/source_ip/event_source) — no normalizer-worker change is
// needed for this connector, matching the CloudTrail connector's design.
func mapFindingToEvent(f guardduty.Finding, tenantID string) map[string]any {
	event := map[string]any{
		"ts":                f.CreatedAt,
		"telemetry_type":    "guardduty",
		"event_type":        f.Type,
		"event_source":      "aws-guardduty",
		"event_id":          f.ID,
		"cloud_account":     f.AccountID,
		"action":            f.Type,
		"source_ip":         f.RemoteIPAddress(),
		"aws_region":        f.Region,
		"risk_score":        f.Severity,
		"message":           f.Title,
		"guardduty_finding": f.Raw,
	}
	if tenantID != "" {
		event["tenant_id"] = tenantID
	}
	return event
}

// forward sends a batch of events to ingestion-gateway's /v1/ingest, signed
// with the same HMAC-SHA256 sigv2 scheme ("ts.body") ingestion-gateway
// itself verifies — no separate trust path, same pattern as the other
// log-connector-* services.
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

	log.Printf("[log-connector-guardduty] WARN: quarantined path=%s reason=%s — left in place, not retried; see %s", path, reason, c.quarantineLogPath)

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

func internalMtlsModes() (serverEnabled bool, clientEnabled bool) {
	serverEnabled = envBool("XDR_INTERNAL_MTLS_ENABLED", false)
	clientEnabled = envBool("XDR_INTERNAL_MTLS_CLIENT_ENABLED", serverEnabled)
	return serverEnabled, clientEnabled
}

// validateTenantConfig enforces CONN-UNTENANTED-INGEST's startup-refusal
// requirement: in strict/production mode (XDR_GUARDDUTY_REQUIRE_TENANT=true),
// a connector with no assigned tenant refuses to start rather than silently
// forwarding unattributed telemetry that ingestion-gateway's own
// tenantAllowed("") would otherwise accept by default. Disabled by default
// (requireTenant=false) preserves the existing behavior byte-for-byte.
func validateTenantConfig(tenantID string, requireTenant bool) error {
	if requireTenant && tenantID == "" {
		return fmt.Errorf("XDR_GUARDDUTY_REQUIRE_TENANT=true but XDR_GUARDDUTY_TENANT_ID is not set — refusing to start untenanted")
	}
	return nil
}
