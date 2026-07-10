// Command log-connector-syslog is a CONNECTOR-FRAMEWORK ingestion bridge: it
// accepts syslog (UDP/TCP), parses ArcSight CEF and IBM LEEF payloads (the
// two formats most network/security appliances and SIEM-integrated systems
// speak), and forwards every event through the existing HMAC-signed
// ingestion-gateway /v1/ingest endpoint — so onboarding a new syslog/CEF/LEEF
// source requires no change to the Go pipeline itself, only pointing the
// appliance at this connector.
//
// All events still flow through the existing normalize -> correlate shadow
// path; this connector adds no new active alert domain and performs no
// outbound/blocking action of any kind.
package main

import (
	"bufio"
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"flag"
	"fmt"
	"log"
	"net"
	"net/http"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"syscall"
	"time"

	"detector-xdr-log-connector-syslog/internal/cef"
	"detector-xdr-log-connector-syslog/internal/leef"
	"detector-xdr-log-connector-syslog/internal/mtls"
	"detector-xdr-log-connector-syslog/internal/registry"
)

// Connector receives syslog lines over UDP/TCP, maps them into the
// platform's generic telemetry.raw event shape, and forwards batches to
// ingestion-gateway.
type Connector struct {
	ingestURL  string
	secret     string
	tenantID   string
	batchSize  int
	httpClient *http.Client
	registry   *registry.Registry

	mu     sync.Mutex
	buffer []map[string]any

	received       atomic.Int64
	parsedCEF      atomic.Int64
	parsedLEEF     atomic.Int64
	parsedRegistry atomic.Int64
	parsedRaw      atomic.Int64
	forwarded      atomic.Int64
	forwardErrors  atomic.Int64
}

func main() {
	udpAddr := flag.String("udp-addr", env("XDR_SYSLOG_UDP_ADDR", ":5140"), "syslog UDP listen address")
	tcpAddr := flag.String("tcp-addr", env("XDR_SYSLOG_TCP_ADDR", ":5140"), "syslog TCP listen address")
	metricsAddr := flag.String("metrics-addr", env("XDR_SYSLOG_METRICS_ADDR", ":8095"), "health/metrics listen address")
	flag.Parse()

	reg, err := registry.Load(env("XDR_SYSLOG_PARSER_REGISTRY", ""))
	if err != nil {
		log.Fatalf("[log-connector-syslog] failed to load parser registry: %v", err)
	}

	c := &Connector{
		ingestURL:  env("XDR_INGEST_URL", "http://127.0.0.1:8091/v1/ingest"),
		secret:     env("XDR_INGEST_SECRET", "dev-secret-change-me"),
		tenantID:   env("XDR_SYSLOG_TENANT_ID", ""),
		batchSize:  envInt("XDR_SYSLOG_BATCH_SIZE", 50),
		httpClient: &http.Client{Timeout: 10 * time.Second, Transport: &http.Transport{}},
		registry:   reg,
	}

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
		log.Fatalf("[log-connector-syslog] internal mTLS server config error: %v", err)
	}
	clientTLSCfg, err := mtls.ClientConfig(mtlsEnabled,
		env("XDR_INTERNAL_MTLS_CLIENT_CERT", ""),
		env("XDR_INTERNAL_MTLS_CLIENT_KEY", ""),
		mtlsCA,
	)
	if err != nil {
		log.Fatalf("[log-connector-syslog] internal mTLS client config error: %v", err)
	}
	if clientTLSCfg != nil {
		if t, ok := c.httpClient.Transport.(*http.Transport); ok {
			t.TLSClientConfig = clientTLSCfg
		}
	}

	stop := make(chan struct{})
	c.startFlusher(time.Duration(envInt("XDR_SYSLOG_FLUSH_MS", 500))*time.Millisecond, stop)

	if err := c.serveUDP(*udpAddr); err != nil {
		log.Fatalf("[log-connector-syslog] udp listen failed: %v", err)
	}
	if err := c.serveTCP(*tcpAddr); err != nil {
		log.Fatalf("[log-connector-syslog] tcp listen failed: %v", err)
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "service": "log-connector-syslog"})
	})
	mux.HandleFunc("/metrics", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, map[string]any{
			"received":        c.received.Load(),
			"parsed_cef":      c.parsedCEF.Load(),
			"parsed_leef":     c.parsedLEEF.Load(),
			"parsed_registry": c.parsedRegistry.Load(),
			"parsed_raw":      c.parsedRaw.Load(),
			"forwarded":       c.forwarded.Load(),
			"forward_errors":  c.forwardErrors.Load(),
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
		log.Printf("[log-connector-syslog] shutting down gracefully")
		close(stop)
		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		_ = server.Shutdown(ctx)
	}()

	log.Printf("[log-connector-syslog] listening udp=%s tcp=%s metrics=%s ingest=%s internal_mtls=%v", *udpAddr, *tcpAddr, *metricsAddr, c.ingestURL, mtlsEnabled)
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

// ingestLine maps and buffers one received syslog line, flushing immediately
// if the buffer has reached batchSize.
func (c *Connector) ingestLine(line string) {
	c.received.Add(1)
	event := processLine(line, c.tenantID, time.Now(), c.registry)
	if event == nil {
		return
	}
	switch event["telemetry_type"] {
	case "syslog_cef":
		c.parsedCEF.Add(1)
	case "syslog_leef":
		c.parsedLEEF.Add(1)
	case "syslog_raw":
		c.parsedRaw.Add(1)
	default:
		c.parsedRegistry.Add(1)
	}
	c.mu.Lock()
	c.buffer = append(c.buffer, event)
	full := len(c.buffer) >= c.batchSize
	c.mu.Unlock()
	if full {
		c.flush()
	}
}

// flush forwards and clears the current buffer. Safe to call from multiple
// goroutines (the periodic ticker and a batch-size trigger can race; the
// mutex-guarded swap ensures each buffered event is forwarded exactly once).
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
		log.Printf("[log-connector-syslog] forward error: %v", err)
	}
}

func (c *Connector) startFlusher(interval time.Duration, stop <-chan struct{}) {
	go func() {
		ticker := time.NewTicker(interval)
		defer ticker.Stop()
		for {
			select {
			case <-ticker.C:
				c.flush()
			case <-stop:
				c.flush()
				return
			}
		}
	}()
}

// forward sends a batch of events to ingestion-gateway's /v1/ingest, signed
// with the same HMAC-SHA256 sigv2 scheme ("ts.body") ingestion-gateway
// itself verifies, so no separate trust path is introduced.
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

// sign computes the ingestion-gateway sigv2 signature: sha256=HMAC(secret, ts + "." + body).
func sign(secret string, ts int64, body []byte) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(strconv.FormatInt(ts, 10)))
	mac.Write([]byte("."))
	mac.Write(body)
	return "sha256=" + hex.EncodeToString(mac.Sum(nil))
}

// processLine maps one raw syslog line into the generic telemetry.raw event
// shape. A successfully parsed CEF payload becomes telemetry_type=syslog_cef,
// a successfully parsed LEEF payload becomes telemetry_type=syslog_leef; a
// line matched against reg (a config-driven CONNECTOR-FRAMEWORK parser
// registry — see internal/registry) becomes whatever telemetry_type that
// source definition names; anything else becomes telemetry_type=syslog_raw
// so no line is silently dropped — an analyst can still see and search it
// even if this connector doesn't understand its format. Returns nil for a
// blank line. reg may be nil or empty (the zero-config default); CEF/LEEF
// are tried first since their markers are reserved and mutually exclusive
// by construction.
func processLine(line string, tenantID string, now time.Time, reg *registry.Registry) map[string]any {
	line = strings.TrimRight(line, "\r\n")
	if strings.TrimSpace(line) == "" {
		return nil
	}
	if msg, err := cef.Parse(line); err == nil {
		return mapCEFToEvent(msg, tenantID, now)
	}
	if msg, err := leef.Parse(line); err == nil {
		return mapLEEFToEvent(msg, tenantID, now)
	}
	if def := reg.Match(line); def != nil {
		return mapRegistryToEvent(def.Parse(line), tenantID, now)
	}
	return mapRawToEvent(line, tenantID, now)
}

// mapCEFToEvent promotes common CEF extension fields (src/dst/spt/dpt/proto/
// act/suser) to top-level aliases the normalizer's generic fallback envelope
// already recognizes, while preserving every extension field verbatim under
// cef_extension so no vendor-specific detail is lost.
func mapCEFToEvent(msg *cef.Message, tenantID string, now time.Time) map[string]any {
	ext := msg.Extension
	eventType := strings.ToLower(strings.ReplaceAll(strings.TrimSpace(msg.Name), " ", "_"))
	if eventType == "" {
		eventType = "cef_event"
	}
	event := map[string]any{
		"ts":               now.UTC().Format(time.RFC3339),
		"telemetry_type":   "syslog_cef",
		"event_type":       eventType,
		"event_source":     "syslog-cef",
		"cef_version":      msg.Version,
		"device_vendor":    msg.DeviceVendor,
		"device_product":   msg.DeviceProduct,
		"device_version":   msg.DeviceVersion,
		"signature_id":     msg.SignatureID,
		"name":             msg.Name,
		"severity":         msg.Severity,
		"cef_extension":    ext,
		"source_ip":        firstNonEmpty(ext["src"], ext["sourceAddress"]),
		"destination_ip":   firstNonEmpty(ext["dst"], ext["destinationAddress"]),
		"source_port":      firstNonEmpty(ext["spt"], ext["sourcePort"]),
		"destination_port": firstNonEmpty(ext["dpt"], ext["destinationPort"]),
		"protocol":         strings.ToLower(firstNonEmpty(ext["proto"], ext["transportProtocol"])),
		"action":           firstNonEmpty(ext["act"], ext["deviceAction"]),
		"user":             firstNonEmpty(ext["suser"], ext["sourceUserName"], ext["duser"]),
		"message":          ext["msg"],
	}
	if tenantID != "" {
		event["tenant_id"] = tenantID
	}
	return event
}

// mapLEEFToEvent promotes common LEEF extension fields (src/dst/srcPort/
// dstPort/proto/usrName/cat) to the same top-level aliases mapCEFToEvent
// uses, while preserving every extension field verbatim under
// leef_extension so no vendor-specific detail is lost.
func mapLEEFToEvent(msg *leef.Message, tenantID string, now time.Time) map[string]any {
	ext := msg.Extension
	eventType := strings.ToLower(strings.ReplaceAll(strings.TrimSpace(msg.EventID), " ", "_"))
	if eventType == "" {
		eventType = "leef_event"
	}
	event := map[string]any{
		"ts":               now.UTC().Format(time.RFC3339),
		"telemetry_type":   "syslog_leef",
		"event_type":       eventType,
		"event_source":     "syslog-leef",
		"leef_version":     msg.Version,
		"device_vendor":    msg.Vendor,
		"device_product":   msg.Product,
		"device_version":   msg.ProductVersion,
		"signature_id":     msg.EventID,
		"leef_extension":   ext,
		"source_ip":        firstNonEmpty(ext["src"], ext["srcIP"]),
		"destination_ip":   firstNonEmpty(ext["dst"], ext["dstIP"]),
		"source_port":      firstNonEmpty(ext["srcPort"], ext["spt"]),
		"destination_port": firstNonEmpty(ext["dstPort"], ext["dpt"]),
		"protocol":         strings.ToLower(firstNonEmpty(ext["proto"], ext["protocol"])),
		"action":           firstNonEmpty(ext["action"], ext["cat"]),
		"user":             firstNonEmpty(ext["usrName"], ext["srcUser"], ext["duser"]),
		"message":          ext["msg"],
	}
	if tenantID != "" {
		event["tenant_id"] = tenantID
	}
	return event
}

// mapRegistryToEvent builds the telemetry.raw envelope for a line matched by
// a config-driven internal/registry source definition. FieldMap-promoted
// fields are written directly using their configured canonical output names
// (e.g. "source_ip", "action") so a newly onboarded source needs zero
// normalizer-worker changes — it flows through the same generic fallback
// envelope any other unrecognized telemetry_type already uses. The full
// key=value extension is preserved verbatim under generic_extension.
func mapRegistryToEvent(msg *registry.Message, tenantID string, now time.Time) map[string]any {
	eventType := strings.ToLower(strings.ReplaceAll(strings.TrimSpace(msg.EventType), " ", "_"))
	if eventType == "" {
		eventType = msg.SourceName + "_event"
	}
	telemetryType := msg.TelemetryType
	if telemetryType == "" {
		telemetryType = "syslog_generic_kv"
	}
	event := map[string]any{
		"ts":                now.UTC().Format(time.RFC3339),
		"telemetry_type":    telemetryType,
		"event_type":        eventType,
		"event_source":      "syslog-registry-" + msg.SourceName,
		"source_type":       msg.SourceName,
		"generic_extension": msg.Extension,
	}
	for field, value := range msg.Fields {
		event[field] = value
	}
	if tenantID != "" {
		event["tenant_id"] = tenantID
	}
	return event
}

// mapRawToEvent is the fallback envelope for a syslog line that is not valid
// CEF/LEEF (plain-text device logs, etc.) — the raw line is preserved
// verbatim rather than dropped.
func mapRawToEvent(line string, tenantID string, now time.Time) map[string]any {
	event := map[string]any{
		"ts":             now.UTC().Format(time.RFC3339),
		"telemetry_type": "syslog_raw",
		"event_type":     "unparsed",
		"event_source":   "syslog-raw",
		"raw_message":    line,
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

// serveUDP starts a background goroutine reading syslog datagrams. Each UDP
// packet is treated as one or more newline-separated messages (some senders
// batch multiple lines per datagram).
func (c *Connector) serveUDP(addr string) error {
	conn, err := net.ListenPacket("udp", addr)
	if err != nil {
		return err
	}
	go func() {
		defer func() { _ = conn.Close() }()
		buf := make([]byte, 64*1024)
		for {
			n, _, err := conn.ReadFrom(buf)
			if err != nil {
				log.Printf("[log-connector-syslog] udp read stopped: %v", err)
				return
			}
			for _, line := range strings.Split(string(buf[:n]), "\n") {
				if strings.TrimSpace(line) != "" {
					c.ingestLine(line)
				}
			}
		}
	}()
	log.Printf("[log-connector-syslog] udp listening on %s", addr)
	return nil
}

// serveTCP starts a background goroutine accepting syslog connections.
// Framing is newline-delimited (RFC6587 non-transparent framing); RFC6587
// octet-counting framing is not supported by this phase-1 connector.
func (c *Connector) serveTCP(addr string) error {
	ln, err := net.Listen("tcp", addr)
	if err != nil {
		return err
	}
	go func() {
		for {
			conn, err := ln.Accept()
			if err != nil {
				log.Printf("[log-connector-syslog] tcp accept stopped: %v", err)
				return
			}
			go c.handleTCPConn(conn)
		}
	}()
	log.Printf("[log-connector-syslog] tcp listening on %s", addr)
	return nil
}

func (c *Connector) handleTCPConn(conn net.Conn) {
	defer func() { _ = conn.Close() }()
	scanner := bufio.NewScanner(conn)
	scanner.Buffer(make([]byte, 64*1024), 1024*1024)
	for scanner.Scan() {
		c.ingestLine(scanner.Text())
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
