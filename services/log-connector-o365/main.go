// Command log-connector-o365 is a CONNECTOR-FRAMEWORK phase-7 ingestion
// bridge: it polls the Office 365 Management Activity API
// (https://manage.office.com/api/v1.0/{tenant}/activity/feed/...) for new
// audit content and forwards every record through the existing HMAC-signed
// ingestion-gateway /v1/ingest endpoint.
//
// Unlike log-connector-{cloudtrail,guardduty,gcp-audit} (file-based
// ingestion of already-exported logs), the Management Activity API is a
// live pull-only API — there is no file-export fallback for O365 audit
// data. This connector needs a real Azure AD app registration (client ID/
// secret, tenant ID, Activity Feed API permissions granted) and an active
// subscription before it can list any content, none of which this
// environment has. The OAuth2/polling/parsing logic (internal/o365) is
// built and unit-tested against a local mock OAuth token endpoint + mock
// Activity API server — proven correct in isolation, never exercised
// against a real Microsoft tenant.
//
// Events use telemetry_type=o365_audit — deliberately NOT "saas_audit"
// (which normalizer-worker/correlation-worker remap to the ALREADY-ACTIVE
// "saas" correlation domain) — matching the same "connector adds no new
// active alert domain" precedent as cloudtrail/guardduty/gcp-audit's own
// distinct telemetry_type strings. All events still flow through the
// existing normalize -> correlate shadow path.
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

	"detector-xdr-log-connector-o365/internal/deliver"
	"detector-xdr-log-connector-o365/internal/mtls"
	"detector-xdr-log-connector-o365/internal/o365"
)

// defaultContentTypes are the standard Management Activity API content
// types covering Azure AD sign-in/audit, Exchange, SharePoint, general
// workload activity, and DLP events.
var defaultContentTypes = []string{
	"Audit.AzureActiveDirectory",
	"Audit.Exchange",
	"Audit.SharePoint",
	"Audit.General",
	"DLP.All",
}

// Connector polls the O365 Management Activity API, maps each audit record
// into the platform's generic telemetry.raw event shape, and forwards
// batches to ingestion-gateway.
type Connector struct {
	ingestURL  string
	secret     string
	tenantID   string
	batchSize  int
	httpClient *http.Client

	client       *o365.Client
	contentTypes []string

	forwardMaxRetries int
	forwardRetryBase  time.Duration
	forwardRetryMax   time.Duration

	// CONN-UNBOUNDED-FILE: size ceilings. 0 disables the corresponding bound.
	maxContentBytes int64
	maxRecordBytes  int64

	stateFile string
	mu        sync.Mutex
	// processedContent mirrors the file connectors' processedFiles — a
	// content ID once fully delivered is never re-fetched. Restart-safe via
	// stateFile, same atomic write-then-rename mechanism.
	processedContent map[string]bool

	contentListed           atomic.Int64
	contentSkipped          atomic.Int64
	contentFetched          atomic.Int64
	contentTooLarge         atomic.Int64
	fetchErrors             atomic.Int64
	parseErrors             atomic.Int64
	recordsParsed           atomic.Int64
	oversizedRecordsSkipped atomic.Int64
	forwarded               atomic.Int64
	forwardErrors           atomic.Int64
	deliveryFailedContent   atomic.Int64
}

func main() {
	metricsAddr := flag.String("metrics-addr", env("XDR_O365_METRICS_ADDR", ":8100"), "health/metrics listen address")
	flag.Parse()

	tenantID := env("XDR_O365_TENANT_ID", "")
	if err := validateTenantConfig(tenantID, envBool("XDR_O365_REQUIRE_TENANT", false)); err != nil {
		log.Fatalf("[log-connector-o365] %v", err)
	}

	azureTenantID := env("XDR_O365_AZURE_TENANT_ID", "")
	clientID := env("XDR_O365_CLIENT_ID", "")
	clientSecret := env("XDR_O365_CLIENT_SECRET", "")
	if azureTenantID == "" || clientID == "" || clientSecret == "" {
		log.Printf("[log-connector-o365] WARNING: XDR_O365_AZURE_TENANT_ID/XDR_O365_CLIENT_ID/XDR_O365_CLIENT_SECRET not fully configured — polling will fail auth until a real Azure AD app registration is provided")
	}

	tokenURL := env("XDR_O365_TOKEN_URL", fmt.Sprintf("https://login.microsoftonline.com/%s/oauth2/v2.0/token", azureTenantID))
	activityBaseURL := env("XDR_O365_ACTIVITY_BASE_URL", "https://manage.office.com")

	contentTypes := defaultContentTypes
	if raw := env("XDR_O365_CONTENT_TYPES", ""); raw != "" {
		contentTypes = strings.Split(raw, ",")
		for i := range contentTypes {
			contentTypes[i] = strings.TrimSpace(contentTypes[i])
		}
	}

	httpClient := &http.Client{Timeout: 20 * time.Second, Transport: &http.Transport{}}

	c := &Connector{
		ingestURL:  env("XDR_INGEST_URL", "http://127.0.0.1:8091/v1/ingest"),
		secret:     env("XDR_INGEST_SECRET", "dev-secret-change-me"),
		tenantID:   tenantID,
		batchSize:  envInt("XDR_O365_BATCH_SIZE", 100),
		httpClient: httpClient,
		client: &o365.Client{
			BaseURL:  activityBaseURL,
			TenantID: azureTenantID,
			Tokens: &o365.TokenSource{
				TokenURL:     tokenURL,
				ClientID:     clientID,
				ClientSecret: clientSecret,
				Resource:     activityBaseURL,
				HTTPClient:   httpClient,
			},
			HTTPClient: httpClient,
		},
		contentTypes:      contentTypes,
		processedContent:  map[string]bool{},
		forwardMaxRetries: envInt("XDR_O365_FORWARD_MAX_RETRIES", 3),
		forwardRetryBase:  time.Duration(envInt("XDR_O365_FORWARD_RETRY_BASE_MS", 200)) * time.Millisecond,
		forwardRetryMax:   time.Duration(envInt("XDR_O365_FORWARD_RETRY_MAX_MS", 2000)) * time.Millisecond,
		maxContentBytes:   int64(envInt("XDR_O365_MAX_CONTENT_BYTES", 100*1024*1024)),
		maxRecordBytes:    int64(envInt("XDR_O365_MAX_RECORD_BYTES", 1024*1024)),
	}
	stateDir := env("XDR_O365_STATE_DIR", "./o365-state")
	if err := os.MkdirAll(stateDir, 0o755); err != nil {
		log.Fatalf("[log-connector-o365] failed to create state dir=%s: %v", stateDir, err)
	}
	c.stateFile = filepath.Join(stateDir, ".o365-connector-state.json")
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
		log.Fatalf("[log-connector-o365] internal mTLS server config error: %v", err)
	}
	clientTLSCfg, err := mtls.ClientConfig(mtlsEnabled,
		env("XDR_INTERNAL_MTLS_CLIENT_CERT", ""),
		env("XDR_INTERNAL_MTLS_CLIENT_KEY", ""),
		mtlsCA,
	)
	if err != nil {
		log.Fatalf("[log-connector-o365] internal mTLS client config error: %v", err)
	}
	if clientTLSCfg != nil {
		if t, ok := c.httpClient.Transport.(*http.Transport); ok {
			t.TLSClientConfig = clientTLSCfg
		}
	}

	stop := make(chan struct{})
	c.startPoller(time.Duration(envInt("XDR_O365_POLL_SECONDS", 300))*time.Second, stop)

	mux := http.NewServeMux()
	mux.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "service": "log-connector-o365"})
	})
	mux.HandleFunc("/metrics", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, map[string]any{
			"content_listed":            c.contentListed.Load(),
			"content_skipped":           c.contentSkipped.Load(),
			"content_fetched":           c.contentFetched.Load(),
			"content_too_large":         c.contentTooLarge.Load(),
			"fetch_errors":              c.fetchErrors.Load(),
			"parse_errors":              c.parseErrors.Load(),
			"records_parsed":            c.recordsParsed.Load(),
			"oversized_records_skipped": c.oversizedRecordsSkipped.Load(),
			"forwarded":                 c.forwarded.Load(),
			"forward_errors":            c.forwardErrors.Load(),
			"delivery_failed_content":   c.deliveryFailedContent.Load(),
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
		log.Printf("[log-connector-o365] shutting down gracefully")
		close(stop)
		_ = server.Close()
	}()

	log.Printf("[log-connector-o365] polling content_types=%v metrics=%s ingest=%s internal_mtls=%v", c.contentTypes, *metricsAddr, c.ingestURL, mtlsEnabled)
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
	c.pollOnce()
	go func() {
		ticker := time.NewTicker(interval)
		defer ticker.Stop()
		for {
			select {
			case <-ticker.C:
				c.pollOnce()
			case <-stop:
				return
			}
		}
	}()
}

// pollOnce lists available content for every configured content type and
// processes anything not already recorded in processedContent.
func (c *Connector) pollOnce() {
	for _, ct := range c.contentTypes {
		pointers, err := c.client.ListAvailableContent(ct, "", "")
		if err != nil {
			c.fetchErrors.Add(1)
			log.Printf("[log-connector-o365] list content error content_type=%s: %v", ct, err)
			continue
		}
		c.contentListed.Add(int64(len(pointers)))
		for _, p := range pointers {
			c.mu.Lock()
			already := c.processedContent[p.ContentID]
			c.mu.Unlock()
			if already {
				c.contentSkipped.Add(1)
				continue
			}
			c.processContent(p)
		}
	}
}

// processContent fetches and forwards one content blob's records.
//
// CONN-DELIVERY-LOSS: this content's records are batched and delivered
// independently of any other content's — the checkpoint
// (processedContent[id]=true + saveState) is committed ONLY after every
// batch derived from this content has been successfully delivered (with
// bounded retry via deliver.WithRetry). If any batch fails after retries
// are exhausted, the content is deliberately left unprocessed — retried in
// full on the next poll, matching the identical design in
// log-connector-cloudtrail/-guardduty/-gcp-audit.
//
// CONN-UNBOUNDED-FILE: a content blob whose response body exceeds
// maxContentBytes is rejected by Client.FetchContent without ever being
// fully read; it is marked processed (so it isn't re-fetched forever) and
// counted in content_too_large, but — unlike the file connectors' full
// durable quarantine log — no separate audit-trail file is written, since a
// live API content blob is far less attacker-controllable than a file drop
// directory (see README's "Size ceilings" section for the full rationale).
func (c *Connector) processContent(p o365.ContentPointer) {
	c.contentFetched.Add(1)
	data, err := c.client.FetchContent(p.ContentURI, c.maxContentBytes)
	if errors.Is(err, o365.ErrContentTooLarge) {
		c.contentTooLarge.Add(1)
		log.Printf("[log-connector-o365] WARN: content_id=%s exceeds XDR_O365_MAX_CONTENT_BYTES — skipping and marking processed, will not be retried", p.ContentID)
		c.mu.Lock()
		c.processedContent[p.ContentID] = true
		c.mu.Unlock()
		c.saveState()
		return
	}
	if err != nil {
		c.fetchErrors.Add(1)
		log.Printf("[log-connector-o365] fetch error content_id=%s: %v", p.ContentID, err)
		return
	}

	records, oversized, err := o365.ParseBounded(data, o365.Limits{MaxRecordBytes: c.maxRecordBytes})
	if err != nil {
		c.parseErrors.Add(1)
		log.Printf("[log-connector-o365] parse error content_id=%s: %v", p.ContentID, err)
		return
	}
	if oversized > 0 {
		c.oversizedRecordsSkipped.Add(int64(oversized))
		log.Printf("[log-connector-o365] WARN: skipped %d oversized record(s) (over XDR_O365_MAX_RECORD_BYTES) in content_id=%s — other records still processed", oversized, p.ContentID)
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
			c.deliveryFailedContent.Add(1)
			log.Printf("[log-connector-o365] WARN: forward failed after retries for content_id=%s (records %d-%d of %d) — content left unprocessed, will retry on next poll: %v", p.ContentID, start, end, len(events), deliverErr)
			return
		}
	}

	c.mu.Lock()
	c.processedContent[p.ContentID] = true
	c.mu.Unlock()
	c.saveState()
}

// mapRecordToEvent maps one O365 Management Activity audit record into the
// generic telemetry.raw event shape, using the same canonical field names
// the normalizer's existing generic fallback envelope already recognizes
// (source_ip/user/action/result/event_source) — no normalizer-worker
// change is needed, matching every other connector in this framework.
func mapRecordToEvent(rec o365.AuditRecord, tenantID string) map[string]any {
	result := strings.ToLower(rec.ResultStatus)
	if result == "" {
		result = "success"
	}
	event := map[string]any{
		"ts":             rec.CreationTime,
		"telemetry_type": "o365_audit",
		"event_type":     rec.Operation,
		"event_source":   rec.Workload,
		"event_id":       rec.ID,
		"user":           rec.UserID,
		"source_ip":      rec.ClientIP,
		"action":         rec.Operation,
		"result":         result,
		"cloud_account":  rec.OrganizationID,
		"o365_record":    rec.Raw,
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

// loadState restores the processed-content set from a prior run so a
// restart doesn't re-fetch/re-forward every content ID already delivered.
// Best-effort: a missing/corrupt state file just starts from an empty set.
func (c *Connector) loadState() {
	data, err := os.ReadFile(c.stateFile)
	if err != nil {
		return
	}
	var ids []string
	if err := json.Unmarshal(data, &ids); err != nil {
		return
	}
	c.mu.Lock()
	for _, id := range ids {
		c.processedContent[id] = true
	}
	c.mu.Unlock()
}

// saveState persists the processed-content set, writing to a temp file and
// renaming atomically so a crash mid-write never leaves a truncated state
// file that would cause content reprocessing to be silently lost.
func (c *Connector) saveState() {
	c.mu.Lock()
	ids := make([]string, 0, len(c.processedContent))
	for id := range c.processedContent {
		ids = append(ids, id)
	}
	c.mu.Unlock()

	data, err := json.Marshal(ids)
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
// requirement: in strict/production mode (XDR_O365_REQUIRE_TENANT=true), a
// connector with no assigned tenant refuses to start rather than silently
// forwarding unattributed telemetry that ingestion-gateway's own
// tenantAllowed("") would otherwise accept by default. Disabled by default
// (requireTenant=false) preserves the same default posture as every other
// connector in this framework.
func validateTenantConfig(tenantID string, requireTenant bool) error {
	if requireTenant && tenantID == "" {
		return fmt.Errorf("XDR_O365_REQUIRE_TENANT=true but XDR_O365_TENANT_ID is not set — refusing to start untenanted")
	}
	return nil
}
