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

	"detector-xdr-log-connector-guardduty/internal/guardduty"
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

	mu             sync.Mutex
	buffer         []map[string]any
	processedFiles map[string]bool

	filesScanned   atomic.Int64
	filesSkipped   atomic.Int64
	findingsParsed atomic.Int64
	forwarded      atomic.Int64
	forwardErrors  atomic.Int64
	parseErrors    atomic.Int64
}

func main() {
	watchDir := flag.String("watch-dir", env("XDR_GUARDDUTY_WATCH_DIR", "./guardduty-findings"), "directory to scan for GuardDuty finding export files")
	metricsAddr := flag.String("metrics-addr", env("XDR_GUARDDUTY_METRICS_ADDR", ":8098"), "health/metrics listen address")
	flag.Parse()

	c := &Connector{
		ingestURL:      env("XDR_INGEST_URL", "http://127.0.0.1:8091/v1/ingest"),
		secret:         env("XDR_INGEST_SECRET", "dev-secret-change-me"),
		tenantID:       env("XDR_GUARDDUTY_TENANT_ID", ""),
		batchSize:      envInt("XDR_GUARDDUTY_BATCH_SIZE", 100),
		watchDir:       *watchDir,
		httpClient:     &http.Client{Timeout: 10 * time.Second},
		processedFiles: map[string]bool{},
	}
	c.stateFile = filepath.Join(c.watchDir, ".guardduty-connector-state.json")
	c.loadState()

	stop := make(chan struct{})
	c.startPoller(time.Duration(envInt("XDR_GUARDDUTY_POLL_SECONDS", 30))*time.Second, stop)

	mux := http.NewServeMux()
	mux.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "service": "log-connector-guardduty"})
	})
	mux.HandleFunc("/metrics", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, map[string]any{
			"files_scanned":   c.filesScanned.Load(),
			"files_skipped":   c.filesSkipped.Load(),
			"findings_parsed": c.findingsParsed.Load(),
			"forwarded":       c.forwarded.Load(),
			"forward_errors":  c.forwardErrors.Load(),
			"parse_errors":    c.parseErrors.Load(),
		})
	})

	server := &http.Server{
		Addr:              *metricsAddr,
		Handler:           mux,
		ReadHeaderTimeout: 10 * time.Second,
	}
	go func() {
		sigCh := make(chan os.Signal, 1)
		signal.Notify(sigCh, os.Interrupt, syscall.SIGTERM)
		<-sigCh
		log.Printf("[log-connector-guardduty] shutting down gracefully")
		close(stop)
		_ = server.Close()
	}()

	log.Printf("[log-connector-guardduty] watching dir=%s metrics=%s ingest=%s", c.watchDir, *metricsAddr, c.ingestURL)
	if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		log.Fatal(err)
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
		// see the identical fix in log-connector-cloudtrail.
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

func hasFindingsExtension(path string) bool {
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
		log.Printf("[log-connector-guardduty] read error path=%s: %v", path, err)
		return
	}
	findings, err := guardduty.Parse(data)
	if err != nil {
		c.parseErrors.Add(1)
		log.Printf("[log-connector-guardduty] parse error path=%s: %v", path, err)
		return
	}
	c.mu.Lock()
	for _, finding := range findings {
		c.buffer = append(c.buffer, mapFindingToEvent(finding, c.tenantID))
	}
	c.processedFiles[path] = true
	full := len(c.buffer) >= c.batchSize
	c.mu.Unlock()
	c.findingsParsed.Add(int64(len(findings)))
	c.saveState()
	if full {
		c.flush()
	}
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
		log.Printf("[log-connector-guardduty] forward error: %v", err)
	}
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
