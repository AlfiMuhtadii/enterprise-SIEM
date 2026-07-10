// Command log-connector-cloudtrail is a CONNECTOR-FRAMEWORK phase-4
// ingestion bridge: it watches a local directory for AWS CloudTrail log
// export files (the stable "{"Records": [...]}" JSON format CloudTrail
// writes to S3, gzip-compressed by default) and forwards every record
// through the existing HMAC-signed ingestion-gateway /v1/ingest endpoint.
//
// This is deliberately scoped as FILE-based ingestion of already-exported
// CloudTrail logs (an operator points this at a local sync of an S3
// bucket — e.g. via "aws s3 sync" or an existing log-shipper drop
// directory) — NOT live AWS S3 API polling, which would require SigV4
// request signing and AWS credentials this environment cannot exercise or
// verify. Live S3 API polling remains explicitly open scope.
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

	"detector-xdr-log-connector-cloudtrail/internal/boundedfile"
	"detector-xdr-log-connector-cloudtrail/internal/cloudtrail"
	"detector-xdr-log-connector-cloudtrail/internal/deliver"
	"detector-xdr-log-connector-cloudtrail/internal/mtls"
)

// Connector watches a directory for CloudTrail export files, maps each
// record into the platform's generic telemetry.raw event shape, and
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
	recordsParsed           atomic.Int64
	oversizedRecordsSkipped atomic.Int64
	forwarded               atomic.Int64
	forwardErrors           atomic.Int64
	parseErrors             atomic.Int64
	deliveryFailedFiles     atomic.Int64
}

func main() {
	watchDir := flag.String("watch-dir", env("XDR_CLOUDTRAIL_WATCH_DIR", "./cloudtrail-logs"), "directory to scan for CloudTrail export files")
	metricsAddr := flag.String("metrics-addr", env("XDR_CLOUDTRAIL_METRICS_ADDR", ":8097"), "health/metrics listen address")
	flag.Parse()

	tenantID := env("XDR_CLOUDTRAIL_TENANT_ID", "")
	if err := validateTenantConfig(tenantID, envBool("XDR_CLOUDTRAIL_REQUIRE_TENANT", false)); err != nil {
		log.Fatalf("[log-connector-cloudtrail] %v", err)
	}

	c := &Connector{
		ingestURL:         env("XDR_INGEST_URL", "http://127.0.0.1:8091/v1/ingest"),
		secret:            env("XDR_INGEST_SECRET", "dev-secret-change-me"),
		tenantID:          tenantID,
		batchSize:         envInt("XDR_CLOUDTRAIL_BATCH_SIZE", 100),
		watchDir:          *watchDir,
		httpClient:        &http.Client{Timeout: 10 * time.Second, Transport: &http.Transport{}},
		processedFiles:    map[string]bool{},
		quarantinedFiles:  map[string]bool{},
		forwardMaxRetries: envInt("XDR_CLOUDTRAIL_FORWARD_MAX_RETRIES", 3),
		forwardRetryBase:  time.Duration(envInt("XDR_CLOUDTRAIL_FORWARD_RETRY_BASE_MS", 200)) * time.Millisecond,
		forwardRetryMax:   time.Duration(envInt("XDR_CLOUDTRAIL_FORWARD_RETRY_MAX_MS", 2000)) * time.Millisecond,
		maxFileBytes:      int64(envInt("XDR_CLOUDTRAIL_MAX_FILE_BYTES", 100*1024*1024)),
		maxExpandedBytes:  int64(envInt("XDR_CLOUDTRAIL_MAX_EXPANDED_BYTES", 500*1024*1024)),
		maxRecordBytes:    int64(envInt("XDR_CLOUDTRAIL_MAX_RECORD_BYTES", 1024*1024)),
	}
	c.stateFile = filepath.Join(c.watchDir, ".cloudtrail-connector-state.json")
	c.quarantineLogPath = filepath.Join(c.watchDir, ".cloudtrail-connector-quarantine.jsonl")
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
		log.Fatalf("[log-connector-cloudtrail] internal mTLS server config error: %v", err)
	}
	clientTLSCfg, err := mtls.ClientConfig(mtlsEnabled,
		env("XDR_INTERNAL_MTLS_CLIENT_CERT", ""),
		env("XDR_INTERNAL_MTLS_CLIENT_KEY", ""),
		mtlsCA,
	)
	if err != nil {
		log.Fatalf("[log-connector-cloudtrail] internal mTLS client config error: %v", err)
	}
	if clientTLSCfg != nil {
		if t, ok := c.httpClient.Transport.(*http.Transport); ok {
			t.TLSClientConfig = clientTLSCfg
		}
	}

	stop := make(chan struct{})
	c.startPoller(time.Duration(envInt("XDR_CLOUDTRAIL_POLL_SECONDS", 30))*time.Second, stop)

	mux := http.NewServeMux()
	mux.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "service": "log-connector-cloudtrail"})
	})
	mux.HandleFunc("/metrics", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, map[string]any{
			"files_scanned":             c.filesScanned.Load(),
			"files_skipped":             c.filesSkipped.Load(),
			"files_quarantined":         c.filesQuarantined.Load(),
			"records_parsed":            c.recordsParsed.Load(),
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
		log.Printf("[log-connector-cloudtrail] shutting down gracefully")
		close(stop)
		_ = server.Close()
	}()

	log.Printf("[log-connector-cloudtrail] watching dir=%s metrics=%s ingest=%s internal_mtls=%v", c.watchDir, *metricsAddr, c.ingestURL, mtlsEnabled)
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

// startPoller runs scanOnce on a fixed interval until stop is closed.
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

// scanOnce walks watchDir recursively (CloudTrail's real S3 layout nests
// files under AWSLogs/<account>/CloudTrail/<region>/<year>/<month>/<day>/),
// processing every .json/.json.gz file not already recorded in
// processedFiles.
func (c *Connector) scanOnce() {
	_ = filepath.WalkDir(c.watchDir, func(path string, d fs.DirEntry, err error) error {
		if err != nil || d.IsDir() {
			return nil
		}
		if !strings.HasSuffix(path, ".json") && !strings.HasSuffix(path, ".json.gz") {
			return nil
		}
		// The connector's own state file lives inside watchDir (so a single
		// -watch-dir flag is enough to configure) and is named *.json, so it
		// would otherwise be picked up as a candidate CloudTrail export on
		// every scan and fail to parse forever. Skip it and its .tmp sibling
		// (see saveState's atomic-write-then-rename).
		if path == c.stateFile || path == c.stateFile+".tmp" {
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

// processFile parses one CloudTrail export file and forwards its records.
//
// CONN-DELIVERY-LOSS: this file's records are batched and delivered
// independently of any other file's — never appended to a shared
// cross-file buffer — so "all derived batches accepted" can be evaluated
// per file, not per arbitrary batch boundary. The checkpoint
// (processedFiles[path]=true + saveState) is committed ONLY after every
// batch derived from this file has been successfully delivered (with
// bounded retry via deliver.WithRetry). If any batch fails after retries
// are exhausted, the file is deliberately left unprocessed — it is
// retried in full on the next scan (this connector's restart-safety
// comes from the pre-existing processedFiles/stateFile mechanism; the bug
// was writing that checkpoint before delivery was confirmed, not the
// mechanism itself). Records from an earlier, already-delivered batch of
// the same file may be forwarded again on a retry of the whole file —
// downstream deduplication is ingestion-gateway/alert-writer's job via
// their own idempotency keys, not this connector's.
func (c *Connector) processFile(path string) {
	c.filesScanned.Add(1)
	data, err := boundedfile.Read(path, c.maxFileBytes)
	if errors.Is(err, boundedfile.ErrTooLarge) {
		c.quarantine(path, "file_exceeds_max_file_bytes")
		return
	}
	if err != nil {
		c.parseErrors.Add(1)
		log.Printf("[log-connector-cloudtrail] read error path=%s: %v", path, err)
		return
	}
	records, oversized, err := cloudtrail.ParseBounded(data, cloudtrail.Limits{
		MaxExpandedBytes: c.maxExpandedBytes,
		MaxRecordBytes:   c.maxRecordBytes,
	})
	if errors.Is(err, cloudtrail.ErrExpandedTooLarge) {
		c.quarantine(path, "decompressed_content_exceeds_max_expanded_bytes")
		return
	}
	if err != nil {
		c.parseErrors.Add(1)
		log.Printf("[log-connector-cloudtrail] parse error path=%s: %v", path, err)
		return
	}
	if oversized > 0 {
		c.oversizedRecordsSkipped.Add(int64(oversized))
		log.Printf("[log-connector-cloudtrail] WARN: skipped %d oversized record(s) (over XDR_CLOUDTRAIL_MAX_RECORD_BYTES) in path=%s — other records in the file were still processed", oversized, path)
	}
	c.recordsParsed.Add(int64(len(records)))

	events := make([]map[string]any, 0, len(records))
	for _, rec := range records {
		events = append(events, mapRecordToEvent(rec, c.tenantID))
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
			log.Printf("[log-connector-cloudtrail] WARN: forward failed after retries for path=%s (records %d-%d of %d) — file left unprocessed, will retry on next scan: %v", path, start, end, len(events), deliverErr)
			return
		}
	}

	c.mu.Lock()
	c.processedFiles[path] = true
	c.mu.Unlock()
	c.saveState()
}

// mapRecordToEvent maps one CloudTrail record into the generic
// telemetry.raw event shape, using the SAME canonical field names
// (cloud_account/user/action/result/source_ip/event_source) the
// normalizer's existing generic fallback envelope already recognizes —
// so no normalizer-worker change is needed for this connector, matching
// the config-driven registry connector's design (services/log-connector-syslog).
func mapRecordToEvent(rec cloudtrail.Record, tenantID string) map[string]any {
	result := "success"
	if rec.ErrorCode != "" {
		result = rec.ErrorCode
	}
	event := map[string]any{
		"ts":                rec.EventTime,
		"telemetry_type":    "cloudtrail",
		"event_type":        rec.EventName,
		"event_source":      rec.EventSource,
		"event_id":          rec.EventID,
		"source_ip":         rec.SourceIPAddress,
		"user":              firstNonEmpty(rec.UserIdentity.UserName, rec.UserIdentity.ARN, rec.UserIdentity.PrincipalID),
		"cloud_account":     firstNonEmpty(rec.RecipientAccountID, rec.UserIdentity.AccountID),
		"action":            rec.EventName,
		"result":            result,
		"aws_region":        rec.AWSRegion,
		"cloudtrail_record": rec.Raw,
	}
	if rec.ErrorMessage != "" {
		event["error_message"] = rec.ErrorMessage
	}
	if tenantID != "" {
		event["tenant_id"] = tenantID
	}
	return event
}

func firstNonEmpty(values ...string) string {
	for _, v := range values {
		if v != "" {
			return v
		}
	}
	return ""
}

// forward sends a batch of events to ingestion-gateway's /v1/ingest, signed
// with the same HMAC-SHA256 sigv2 scheme ("ts.body") ingestion-gateway
// itself verifies — no separate trust path, same pattern as
// services/log-connector-syslog.
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

// loadState restores the processed-files set from a prior run so a restart
// doesn't re-ingest every file already forwarded. Best-effort: a
// missing/corrupt state file just starts from an empty set, not a crash.
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

// saveState persists the processed-files set, writing to a temp file and
// renaming atomically so a crash mid-write never leaves a truncated state
// file that would cause file reprocessing to be silently lost.
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
// untouched (never deleted/moved) so an operator can inspect, split, or
// manually re-import it after investigating.
type quarantineRecord struct {
	Path          string `json:"path"`
	Reason        string `json:"reason"`
	QuarantinedAt string `json:"quarantined_at"`
}

// quarantine marks path as permanently skipped (so a multi-GB file isn't
// re-read and re-rejected on every 30s scan — the "retry policy" the
// finding requires) and appends a durable, auditable rejection record.
// Unlike processedFiles, quarantining is a one-way decision: an operator
// who wants to reconsider a quarantined file must resolve it out-of-band
// (fix/split the file, raise the limit, or manually forward it) and clear
// the entry from the quarantine log themselves.
func (c *Connector) quarantine(path, reason string) {
	c.filesQuarantined.Add(1)
	c.mu.Lock()
	c.quarantinedFiles[path] = true
	c.mu.Unlock()

	log.Printf("[log-connector-cloudtrail] WARN: quarantined path=%s reason=%s — left in place, not retried; see %s", path, reason, c.quarantineLogPath)

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
// requirement: in strict/production mode (XDR_CLOUDTRAIL_REQUIRE_TENANT=true),
// a connector with no assigned tenant refuses to start rather than silently
// forwarding unattributed telemetry that ingestion-gateway's own
// tenantAllowed("") would otherwise accept by default. Disabled by default
// (requireTenant=false) preserves the existing behavior byte-for-byte.
func validateTenantConfig(tenantID string, requireTenant bool) error {
	if requireTenant && tenantID == "" {
		return fmt.Errorf("XDR_CLOUDTRAIL_REQUIRE_TENANT=true but XDR_CLOUDTRAIL_TENANT_ID is not set — refusing to start untenanted")
	}
	return nil
}
