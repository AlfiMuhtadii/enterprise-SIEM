package main

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"log"
	"net/http"
	_ "net/http/pprof"
	"os"
	"runtime"
	"sort"
	"strings"
	"sync/atomic"
	"time"
)

type Event struct {
	Ts             string  `json:"ts"`
	EventID        string  `json:"event_id"`
	TelemetryType  string  `json:"telemetry_type"`
	EventType      string  `json:"event_type"`
	User           string  `json:"user"`
	Host           string  `json:"host"`
	SourceIP       string  `json:"source_ip"`
	DestinationIP  string  `json:"destination_ip"`
	Domain         string  `json:"domain"`
	FileHash       string  `json:"file_hash"`
	EmailSender    string  `json:"email_sender"`
	EmailRecipient string  `json:"email_recipient"`
	CloudAccount   string  `json:"cloud_account"`
	Action         string  `json:"action"`
	Result         string  `json:"result"`
	RiskScore      float64 `json:"risk_score"`
	EventSource    string  `json:"event_source"`
}

type Alert struct {
	AlertID     string   `json:"alert_id"`
	AlertType   string   `json:"alert_type"`
	Actor       string   `json:"actor"`
	Severity    string   `json:"severity"`
	Score       float64  `json:"score"`
	Domains     []string `json:"domains"`
	EvidenceIDs []string `json:"evidence_ids"`
	ShadowMode  bool     `json:"shadow_mode"`
}

type Worker struct {
	processed atomic.Int64
	alerts    atomic.Int64
	latencyMS atomic.Int64
}

func main() {
	addr := flag.String("addr", env("XDR_CORRELATION_ADDR", ":8093"), "listen address")
	flag.Parse()
	w := &Worker{}
	mux := http.NewServeMux()
	mux.HandleFunc("/health", w.health)
	mux.HandleFunc("/metrics", w.metrics)
	mux.HandleFunc("/v1/correlate", w.correlateHTTP)
	log.Printf("xdr correlation worker listening on %s shadow_mode=true", *addr)
	log.Fatal(http.ListenAndServe(*addr, mux))
}

func (w *Worker) health(rw http.ResponseWriter, r *http.Request) {
	writeJSON(rw, http.StatusOK, map[string]any{"status": "ok", "service": "xdr-correlation", "mode": "shadow"})
}

func (w *Worker) metrics(rw http.ResponseWriter, r *http.Request) {
	var mem runtime.MemStats
	runtime.ReadMemStats(&mem)
	writeJSON(rw, http.StatusOK, map[string]any{
		"processed":     w.processed.Load(),
		"alerts":        w.alerts.Load(),
		"last_latency_ms": w.latencyMS.Load(),
		"goroutines":    runtime.NumGoroutine(),
		"heap_alloc_mb": float64(mem.HeapAlloc) / 1024.0 / 1024.0,
	})
}

func (w *Worker) correlateHTTP(rw http.ResponseWriter, r *http.Request) {
	started := time.Now()
	if r.Method != http.MethodPost {
		http.Error(rw, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	body, err := io.ReadAll(io.LimitReader(r.Body, 32*1024*1024))
	if err != nil {
		http.Error(rw, "read_failed", http.StatusBadRequest)
		return
	}
	var events []Event
	if err := json.Unmarshal(body, &events); err != nil {
		http.Error(rw, "invalid_json", http.StatusBadRequest)
		return
	}
	alerts := correlate(events)
	scope := r.URL.Query().Get("scope")
	if scope == "identity-cloud" {
		alerts = correlateIdentityCloud(events)
	}
	elapsed := time.Since(started).Milliseconds()
	w.processed.Add(int64(len(events)))
	w.alerts.Add(int64(len(alerts)))
	w.latencyMS.Store(elapsed)
	writeJSON(rw, http.StatusOK, map[string]any{
		"events": len(events),
		"alerts": alerts,
		"alert_count": len(alerts),
		"latency_ms": elapsed,
		"shadow_mode": true,
	})
}

func correlate(events []Event) []Alert {
	byUser := map[string][]Event{}
	byDomain := map[string][]Event{}
	byHost := map[string][]Event{}
	byIP := map[string][]Event{}
	for _, ev := range events {
		ev.TelemetryType = strings.ToLower(ev.TelemetryType)
		ev.EventType = strings.ToLower(ev.EventType)
		if ev.User != "" {
			byUser[ev.User] = append(byUser[ev.User], ev)
		}
		if ev.Domain != "" {
			byDomain[strings.ToLower(ev.Domain)] = append(byDomain[strings.ToLower(ev.Domain)], ev)
		}
		if ev.Host != "" {
			byHost[ev.Host] = append(byHost[ev.Host], ev)
		}
		if ev.TelemetryType == "identity" && ev.SourceIP != "" {
			byIP[ev.SourceIP] = append(byIP[ev.SourceIP], ev)
		}
	}
	alerts := make([]Alert, 0)
	for user, group := range byUser {
		alerts = append(alerts, identityAlerts(user, group)...)
		alerts = append(alerts, cloudSaasAlerts(user, group)...)
		alerts = append(alerts, crossUserAlerts(user, group)...)
	}
	for domain, group := range byDomain {
		var ioc, dns, endpoint []Event
		for _, ev := range group {
			if ev.RiskScore >= 0.7 || strings.Contains(ev.EventType, "ioc") {
				ioc = append(ioc, ev)
			}
			if ev.TelemetryType == "dns" {
				dns = append(dns, ev)
			}
			if ev.TelemetryType == "endpoint" {
				endpoint = append(endpoint, ev)
			}
		}
		if len(ioc) > 0 && len(dns) > 0 && len(endpoint) > 0 {
			alerts = append(alerts, makeAlert("XDR_IOC_DNS_ENDPOINT_CHAIN", domain, []Event{ioc[0], dns[len(dns)-1], endpoint[len(endpoint)-1]}, 0.86))
		}
	}
	for host, group := range byHost {
		var proxy, process []Event
		for _, ev := range group {
			if (ev.TelemetryType == "proxy" || ev.TelemetryType == "firewall") && ev.RiskScore >= 0.6 {
				proxy = append(proxy, ev)
			}
			if ev.TelemetryType == "endpoint" && ev.EventType == "process_created" {
				process = append(process, ev)
			}
		}
		if len(proxy) > 0 && len(process) > 0 {
			alerts = append(alerts, makeAlert("XDR_PROXY_ENDPOINT_ESCALATION", host, []Event{proxy[len(proxy)-1], process[len(process)-1]}, 0.76))
		}
	}
	for ip, group := range byIP {
		users := map[string]bool{}
		for _, ev := range group {
			if ev.User != "" {
				users[ev.User] = true
			}
		}
		if len(users) >= 3 {
			alerts = append(alerts, makeAlert("IDENTITY_UNUSUAL_LOGIN_SOURCE", ip, tail(group, 12), 0.71))
		}
	}
	sort.Slice(alerts, func(i, j int) bool {
		if alerts[i].AlertType == alerts[j].AlertType {
			return alerts[i].Actor < alerts[j].Actor
		}
		return alerts[i].AlertType < alerts[j].AlertType
	})
	return dedupe(alerts)
}

func correlateIdentityCloud(events []Event) []Alert {
	byUser := make(map[string][]Event, 1024)
	byIP := make(map[string][]Event, 1024)
	for _, ev := range events {
		ev.TelemetryType = strings.ToLower(ev.TelemetryType)
		ev.EventType = strings.ToLower(ev.EventType)
		if ev.TelemetryType != "identity" && ev.TelemetryType != "cloud" && ev.TelemetryType != "saas" {
			continue
		}
		if ev.User != "" {
			byUser[ev.User] = append(byUser[ev.User], ev)
		}
		if ev.TelemetryType == "identity" && ev.SourceIP != "" {
			byIP[ev.SourceIP] = append(byIP[ev.SourceIP], ev)
		}
	}

	userAlerts := parallelUserCorrelation(byUser)
	ipAlerts := make([]Alert, 0, len(byIP))
	for ip, group := range byIP {
		users := map[string]bool{}
		for _, ev := range group {
			if ev.User != "" {
				users[ev.User] = true
			}
		}
		if len(users) >= 3 {
			ipAlerts = append(ipAlerts, makeAlert("IDENTITY_UNUSUAL_LOGIN_SOURCE", ip, tail(group, 12), 0.71))
		}
	}
	alerts := append(userAlerts, ipAlerts...)
	sort.Slice(alerts, func(i, j int) bool {
		if alerts[i].AlertType == alerts[j].AlertType {
			return alerts[i].Actor < alerts[j].Actor
		}
		return alerts[i].AlertType < alerts[j].AlertType
	})
	return dedupe(alerts)
}

func parallelUserCorrelation(byUser map[string][]Event) []Alert {
	type item struct {
		user  string
		group []Event
	}
	workers := runtime.NumCPU()
	if workers < 2 {
		workers = 2
	}
	jobs := make(chan item, len(byUser))
	results := make(chan []Alert, len(byUser))
	for i := 0; i < workers; i++ {
		go func() {
			for job := range jobs {
				alerts := make([]Alert, 0, 4)
				alerts = append(alerts, identityAlerts(job.user, job.group)...)
				alerts = append(alerts, cloudSaasAlerts(job.user, job.group)...)
				results <- alerts
			}
		}()
	}
	for user, group := range byUser {
		jobs <- item{user: user, group: group}
	}
	close(jobs)
	merged := make([]Alert, 0, len(byUser))
	for i := 0; i < len(byUser); i++ {
		merged = append(merged, <-results...)
	}
	return merged
}

func identityAlerts(actor string, group []Event) []Alert {
	var failed, risky, success, priv []Event
	services := map[string]bool{}
	sources := map[string]bool{}
	for _, ev := range group {
		if ev.TelemetryType != "identity" {
			continue
		}
		if ev.EventType == "login_failed" || ev.EventType == "mfa_failed" || ev.Result == "failure" {
			failed = append(failed, ev)
			services[ev.EventSource] = true
		}
		if ev.EventType == "login_success" {
			success = append(success, ev)
			if ev.SourceIP != "" {
				sources[ev.SourceIP] = true
			}
			if ev.RiskScore >= 0.7 {
				risky = append(risky, ev)
			}
		}
		if strings.Contains(ev.EventType, "privilege") || strings.Contains(ev.Action, "role") || strings.Contains(ev.Action, "admin") {
			priv = append(priv, ev)
		}
	}
	alerts := []Alert{}
	if len(failed) >= 5 {
		alerts = append(alerts, makeAlert("IDENTITY_MFA_FAILURE_BURST", actor, tail(failed, 10), 0.71))
	}
	if len(failed) >= 4 && len(services) >= 2 {
		alerts = append(alerts, makeAlert("IDENTITY_FAILED_LOGIN_ACROSS_SERVICES", actor, tail(failed, 10), 0.76))
	}
	if len(risky) > 0 {
		alerts = append(alerts, makeAlert("IDENTITY_RISKY_IP_LOGIN", actor, tail(risky, 5), 0.76))
	}
	if len(sources) >= 2 {
		alerts = append(alerts, makeAlert("IDENTITY_IMPOSSIBLE_TRAVEL", actor, tail(success, 6), 0.66))
	}
	if len(priv) > 0 {
		alerts = append(alerts, makeAlert("IDENTITY_PRIVILEGE_ESCALATION", actor, tail(priv, 5), 0.78))
	}
	return alerts
}

func cloudSaasAlerts(actor string, group []Event) []Alert {
	var high, downloads, objectAccess, keys, settings, admin []Event
	for _, ev := range group {
		if ev.TelemetryType != "cloud" && ev.TelemetryType != "saas" {
			continue
		}
		text := strings.ToLower(ev.EventType + " " + ev.Action)
		if ev.RiskScore >= 0.7 {
			high = append(high, ev)
		}
		if strings.Contains(text, "download") {
			downloads = append(downloads, ev)
		}
		if strings.Contains(text, "object") || strings.Contains(strings.ToLower(ev.Action), "getobject") {
			objectAccess = append(objectAccess, ev)
		}
		if strings.Contains(text, "access_key") || ev.Action == "CreateAccessKey" {
			keys = append(keys, ev)
		}
		if strings.Contains(text, "security_setting") || strings.Contains(strings.ToLower(ev.Action), "policy") {
			settings = append(settings, ev)
		}
		if strings.Contains(text, "admin") {
			admin = append(admin, ev)
		}
	}
	alerts := []Alert{}
	if len(high) >= 3 {
		alerts = append(alerts, makeAlert("CLOUD_UNUSUAL_API_ACTIVITY", actor, tail(high, 10), 0.71))
	}
	if len(objectAccess) >= 5 {
		alerts = append(alerts, makeAlert("CLOUD_SUSPICIOUS_OBJECT_ACCESS", actor, tail(objectAccess, 10), 0.71))
	}
	if len(downloads) >= 10 {
		alerts = append(alerts, makeAlert("CLOUD_MASS_DOWNLOAD", actor, tail(downloads, 20), 0.76))
	}
	if len(keys) > 0 {
		alerts = append(alerts, makeAlert("CLOUD_NEW_ACCESS_KEY", actor, tail(keys, 5), 0.73))
	}
	if len(settings) > 0 {
		alerts = append(alerts, makeAlert("CLOUD_SECURITY_SETTING_MODIFIED", actor, tail(settings, 5), 0.73))
	}
	if len(admin) > 0 && anyTelemetry(admin, "saas") {
		alerts = append(alerts, makeAlert("SAAS_UNUSUAL_ADMIN_ACTIVITY", actor, tail(admin, 8), 0.76))
	}
	return alerts
}

func crossUserAlerts(actor string, group []Event) []Alert {
	var email, login, endpoint, priv, cloud []Event
	for _, ev := range group {
		if ev.TelemetryType == "email" && (strings.Contains(ev.EventType, "phish") || ev.RiskScore >= 0.7) {
			email = append(email, ev)
		}
		if ev.TelemetryType == "identity" && (ev.EventType == "login_success" || ev.EventType == "suspicious_login") {
			login = append(login, ev)
		}
		if ev.TelemetryType == "endpoint" && (ev.EventType == "process_created" || ev.EventType == "scheduled_task_created") {
			endpoint = append(endpoint, ev)
		}
		if strings.Contains(ev.EventType, "privilege") || strings.Contains(ev.Action, "role") {
			priv = append(priv, ev)
		}
		if ev.TelemetryType == "cloud" {
			cloud = append(cloud, ev)
		}
	}
	alerts := []Alert{}
	if len(email) > 0 && len(login) > 0 && len(endpoint) > 0 {
		alerts = append(alerts, makeAlert("XDR_PHISHING_TO_ENDPOINT_EXECUTION", actor, []Event{email[0], login[0], endpoint[0]}, 0.86))
	}
	if len(login) > 0 && len(priv) > 0 && len(cloud) > 0 {
		alerts = append(alerts, makeAlert("XDR_IMPOSSIBLE_LOGIN_PRIVILEGE_CLOUD", actor, []Event{login[len(login)-1], priv[len(priv)-1], cloud[len(cloud)-1]}, 0.91))
	}
	return alerts
}

func makeAlert(alertType, actor string, events []Event, score float64) Alert {
	domains := map[string]bool{}
	ids := make([]string, 0, len(events))
	for _, ev := range events {
		if ev.TelemetryType != "" {
			domains[ev.TelemetryType] = true
		}
		if ev.EventID != "" {
			ids = append(ids, ev.EventID)
		}
	}
	domainList := make([]string, 0, len(domains))
	for domain := range domains {
		domainList = append(domainList, domain)
	}
	sort.Strings(domainList)
	sort.Strings(ids)
	severity := "medium"
	if score >= 0.85 {
		severity = "critical"
	} else if score >= 0.65 {
		severity = "high"
	}
	sum := sha256.Sum256([]byte(alertType + "|" + actor + "|" + strings.Join(ids, ",")))
	return Alert{AlertID: hex.EncodeToString(sum[:])[:40], AlertType: alertType, Actor: actor, Severity: severity, Score: score, Domains: domainList, EvidenceIDs: ids, ShadowMode: true}
}

func hasEvent(events []Event, telemetryType, eventType string) bool {
	for _, ev := range events {
		if ev.TelemetryType == telemetryType && ev.EventType == eventType {
			return true
		}
	}
	return false
}

func anyTelemetry(events []Event, telemetryType string) bool {
	for _, ev := range events {
		if ev.TelemetryType == telemetryType {
			return true
		}
	}
	return false
}

func tail(events []Event, n int) []Event {
	if len(events) == 0 {
		return events
	}
	if len(events) <= n {
		return events
	}
	return events[len(events)-n:]
}

func dedupe(alerts []Alert) []Alert {
	seen := map[string]bool{}
	out := make([]Alert, 0, len(alerts))
	for _, alert := range alerts {
		key := alert.AlertType + "|" + alert.Actor + "|" + strings.Join(alert.EvidenceIDs, ",")
		if seen[key] {
			continue
		}
		seen[key] = true
		out = append(out, alert)
	}
	return out
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

var _ = fmt.Sprintf
