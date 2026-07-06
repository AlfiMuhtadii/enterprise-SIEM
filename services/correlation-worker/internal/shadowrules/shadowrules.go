// Package shadowrules implements the endpoint/network shadow-correlation
// rule engine (advisory-only, publishes only to xdr.alerts.shadow.* topics).
// Extracted from correlation-worker/main.go (CODE-STRUCT-DECOMPOSE, seam 2) —
// pure code movement, no behavior change.
package shadowrules

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"sort"
	"strings"
)

var LinuxShellNames = map[string]bool{
	"bash": true, "sh": true, "zsh": true, "dash": true, "ksh": true, "tcsh": true, "fish": true,
	"python": true, "python3": true, "python2": true, "perl": true, "ruby": true,
	"curl": true, "wget": true,
}

var DownloaderNames = map[string]bool{
	"curl": true, "wget": true,
}

var LolbinNames = map[string]bool{
	"curl": true, "wget": true, "bash": true, "sh": true,
	"python": true, "python3": true, "python2": true, "perl": true,
	"nc": true, "netcat": true, "ncat": true, "base64": true,
	"systemctl": true, "crontab": true, "dd": true, "awk": true,
}


type EndpointAlert struct {
	AlertID     string         `json:"alert_id"`
	RuleID      string         `json:"rule_id"`
	Version     string         `json:"version"`
	Title       string         `json:"title"`
	Severity    string         `json:"severity"`
	Confidence  float64        `json:"confidence"`
	Host        string         `json:"host"`
	User        string         `json:"user"`
	TraceID     string         `json:"trace_id"`
	ShadowMode  bool           `json:"shadow_mode"`
	EvidenceIDs []string       `json:"evidence_ids"`
	EventType   string         `json:"event_type"`
	Evidence    map[string]any `json:"evidence"`
}

func EpStr(event map[string]any, path ...string) string {
	var current any = event
	for _, key := range path {
		m, ok := current.(map[string]any)
		if !ok {
			return ""
		}
		current = m[key]
	}
	if current == nil {
		return ""
	}
	return fmt.Sprint(current)
}

func EpInt64(event map[string]any, path ...string) int64 {
	var current any = event
	for _, key := range path {
		m, ok := current.(map[string]any)
		if !ok {
			return 0
		}
		current = m[key]
	}
	if current == nil {
		return 0
	}
	switch v := current.(type) {
	case float64:
		return int64(v)
	case int64:
		return v
	case int:
		return int64(v)
	default:
		return 0
	}
}

func MakeEndpointAlert(ruleID, version, title, severity string, confidence float64, event map[string]any) EndpointAlert {
	eventID := EpStr(event, "normalized_event_id")
	if eventID == "" {
		eventID = EpStr(event, "raw_event_id")
	}
	sum := sha256.Sum256([]byte(ruleID + "|" + EpStr(event, "host") + "|" + eventID))
	return EndpointAlert{
		AlertID:     hex.EncodeToString(sum[:])[:40],
		RuleID:      ruleID,
		Version:     version,
		Title:       title,
		Severity:    severity,
		Confidence:  confidence,
		Host:        EpStr(event, "host"),
		User:        EpStr(event, "user"),
		TraceID:     EpStr(event, "trace_id"),
		ShadowMode:  true,
		EvidenceIDs: []string{eventID},
		EventType:   EpStr(event, "event_type"),
		Evidence:    map[string]any{},
	}
}

func DedupeEndpointAlerts(alerts []EndpointAlert) []EndpointAlert {
	seen := map[string]bool{}
	out := make([]EndpointAlert, 0, len(alerts))
	for _, a := range alerts {
		if seen[a.AlertID] {
			continue
		}
		seen[a.AlertID] = true
		out = append(out, a)
	}
	return out
}

// ---------------------------------------------------------------------------
// Network shadow rules — DNS/proxy/firewall Phase 1
// Advisory-only. Publishes to xdr.alerts.shadow.network only.
// No active blocking. No firewall rule push. No IP/domain blocking.
// No packet sniffing. No DPI inspection. No autonomous response.
// ---------------------------------------------------------------------------

func CorrelateNetworkShadowAll(events []map[string]any) []EndpointAlert {
	if len(events) == 0 {
		return nil
	}
	var alerts []EndpointAlert
	alerts = append(alerts, ruleNetworkSuspiciousDnsTld(events)...)
	alerts = append(alerts, ruleNetworkDnsTxtQueryPattern(events)...)
	alerts = append(alerts, ruleNetworkRepeatedNxdomain(events)...)
	alerts = append(alerts, ruleNetworkSuspiciousUserAgent(events)...)
	alerts = append(alerts, ruleNetworkHighVolumeProxyEgress(events)...)
	alerts = append(alerts, ruleNetworkRepeatedDeniedOutbound(events)...)
	alerts = append(alerts, ruleNetworkUnusualDestinationPort(events)...)
	alerts = append(alerts, ruleNetworkRareExternalDestination(events)...)
	alerts = append(alerts, ruleNetworkSuspiciousAllowAfterDeny(events)...)
	return alerts
}

func ruleNetworkSuspiciousDnsTld(events []map[string]any) []EndpointAlert {
	// Detect DNS queries to suspicious TLDs. Advisory-only, no blocking.
	suspiciousTlds := map[string]bool{
		".ru": true, ".cn": true, ".tk": true, ".ml": true, ".ga": true,
		".cf": true, ".gq": true, ".pw": true, ".xyz": true, ".top": true,
	}
	seen := map[string]bool{}
	var alerts []EndpointAlert
	for _, ev := range events {
		if EpStr(ev, "telemetry_type") != "dns" {
			continue
		}
		domain := strings.ToLower(EpStr(ev, "queried_domain"))
		if domain == "" {
			continue
		}
		parts := strings.Split(domain, ".")
		if len(parts) < 2 {
			continue
		}
		tld := "." + parts[len(parts)-1]
		if !suspiciousTlds[tld] {
			continue
		}
		host := EpStr(ev, "host_id")
		key  := host + "|" + tld
		if seen[key] {
			continue
		}
		seen[key] = true
		a := MakeEndpointAlert("suspicious_dns_tld", "v1", "Suspicious DNS TLD Query Detected", "medium", 0.72, ev)
		a.Evidence["host"]         = host
		a.Evidence["tld"]          = tld
		a.Evidence["domain"]       = domain
		a.Evidence["advisory"]     = "network_shadow_only"
		a.Evidence["no_blocking"]  = true
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleNetworkDnsTxtQueryPattern(events []map[string]any) []EndpointAlert {
	// Detect ≥2 TXT DNS queries from same host in batch. Advisory-only.
	hostTxtCount := map[string]int{}
	for _, ev := range events {
		if EpStr(ev, "telemetry_type") != "dns" || strings.ToUpper(EpStr(ev, "query_type")) != "TXT" {
			continue
		}
		host := EpStr(ev, "host_id")
		if host != "" {
			hostTxtCount[host]++
		}
	}
	var alerts []EndpointAlert
	for host, count := range hostTxtCount {
		if count < 2 {
			continue
		}
		a := MakeEndpointAlert("dns_txt_query_pattern", "v1", "Repeated DNS TXT Queries Detected", "medium", 0.68, map[string]any{})
		a.Evidence["host"]        = host
		a.Evidence["txt_count"]   = count
		a.Evidence["advisory"]    = "network_shadow_only"
		a.Evidence["no_blocking"] = true
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleNetworkRepeatedNxdomain(events []map[string]any) []EndpointAlert {
	// Detect ≥5 NXDOMAIN responses for same host in batch. Advisory-only.
	hostNxCount := map[string]int{}
	for _, ev := range events {
		if EpStr(ev, "telemetry_type") != "dns" {
			continue
		}
		if strings.ToUpper(EpStr(ev, "response_code")) != "NXDOMAIN" {
			continue
		}
		host := EpStr(ev, "host_id")
		if host != "" {
			hostNxCount[host]++
		}
	}
	var alerts []EndpointAlert
	for host, count := range hostNxCount {
		if count < 5 {
			continue
		}
		a := MakeEndpointAlert("repeated_nxdomain", "v1", "Repeated NXDOMAIN Responses Detected", "medium", 0.74, map[string]any{})
		a.Evidence["host"]         = host
		a.Evidence["nxdomain_count"] = count
		a.Evidence["advisory"]     = "network_shadow_only"
		a.Evidence["no_blocking"]  = true
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleNetworkSuspiciousUserAgent(events []map[string]any) []EndpointAlert {
	// Detect suspicious user-agent strings in proxy events. Advisory-only.
	suspiciousUA := []string{"curl/", "python-requests/", "go-http-client/", "wget/", "sqlmap", "nikto", "masscan", "zgrab"}
	seen := map[string]bool{}
	var alerts []EndpointAlert
	for _, ev := range events {
		if EpStr(ev, "telemetry_type") != "proxy" {
			continue
		}
		ua   := strings.ToLower(EpStr(ev, "user_agent"))
		host := EpStr(ev, "host_id")
		for _, pat := range suspiciousUA {
			if strings.Contains(ua, pat) && !seen[host+"|"+pat] {
				seen[host+"|"+pat] = true
				a := MakeEndpointAlert("suspicious_user_agent", "v1", "Suspicious Proxy User-Agent Detected", "high", 0.80, ev)
				a.Evidence["host"]        = host
				a.Evidence["ua_pattern"]  = pat
				a.Evidence["advisory"]    = "network_shadow_only"
				a.Evidence["no_blocking"] = true
				alerts = append(alerts, a)
				break
			}
		}
	}
	return alerts
}

func ruleNetworkHighVolumeProxyEgress(events []map[string]any) []EndpointAlert {
	// Detect high total bytes_out via proxy per host in batch. Advisory-only.
	hostBytes := map[string]int64{}
	for _, ev := range events {
		if EpStr(ev, "telemetry_type") != "proxy" {
			continue
		}
		host := EpStr(ev, "host_id")
		if host == "" {
			continue
		}
		if b, ok := ev["bytes_out"].(float64); ok {
			hostBytes[host] += int64(b)
		}
	}
	const threshold int64 = 50 * 1024 * 1024
	var alerts []EndpointAlert
	for host, total := range hostBytes {
		if total < threshold {
			continue
		}
		a := MakeEndpointAlert("high_volume_proxy_egress", "v1", "High Volume Proxy Egress Detected", "high", 0.76, map[string]any{})
		a.Evidence["host"]        = host
		a.Evidence["bytes_out"]   = total
		a.Evidence["advisory"]    = "network_shadow_only"
		a.Evidence["no_blocking"] = true
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleNetworkRepeatedDeniedOutbound(events []map[string]any) []EndpointAlert {
	// Detect ≥5 denied firewall flows per host. Advisory-only.
	hostDenyCount := map[string]int{}
	for _, ev := range events {
		if EpStr(ev, "telemetry_type") != "firewall" {
			continue
		}
		action := strings.ToLower(EpStr(ev, "action"))
		if action != "deny" && action != "drop" {
			continue
		}
		host := EpStr(ev, "host_id")
		if host != "" {
			hostDenyCount[host]++
		}
	}
	var alerts []EndpointAlert
	for host, count := range hostDenyCount {
		if count < 5 {
			continue
		}
		a := MakeEndpointAlert("repeated_denied_outbound", "v1", "Repeated Denied Outbound Firewall Flows Detected", "high", 0.80, map[string]any{})
		a.Evidence["host"]        = host
		a.Evidence["deny_count"]  = count
		a.Evidence["advisory"]    = "network_shadow_only"
		a.Evidence["no_blocking"] = true
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleNetworkUnusualDestinationPort(events []map[string]any) []EndpointAlert {
	// Detect connections to unusual/suspicious ports. Advisory-only.
	unusualPorts := map[int]bool{4444: true, 1337: true, 31337: true, 6666: true, 9999: true, 8888: true, 4321: true}
	seen := map[string]bool{}
	var alerts []EndpointAlert
	for _, ev := range events {
		if EpStr(ev, "telemetry_type") != "firewall" {
			continue
		}
		port := 0
		if p, ok := ev["destination_port"].(float64); ok {
			port = int(p)
		}
		if !unusualPorts[port] {
			continue
		}
		host := EpStr(ev, "host_id")
		key  := fmt.Sprintf("%s|%d", host, port)
		if seen[key] {
			continue
		}
		seen[key] = true
		a := MakeEndpointAlert("unusual_destination_port", "v1", "Unusual Firewall Destination Port Detected", "high", 0.82, ev)
		a.Evidence["host"]        = host
		a.Evidence["port"]        = port
		a.Evidence["advisory"]    = "network_shadow_only"
		a.Evidence["no_blocking"] = true
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleNetworkRareExternalDestination(events []map[string]any) []EndpointAlert {
	// Detect first-seen external destinations per host in batch.
	// Advisory-only — does NOT block the destination.
	type hostDest struct{ host, dest string }
	seen := map[hostDest]bool{}
	var alerts []EndpointAlert
	for _, ev := range events {
		if EpStr(ev, "telemetry_type") != "firewall" {
			continue
		}
		dest := EpStr(ev, "destination_ip")
		host := EpStr(ev, "host_id")
		if dest == "" || host == "" {
			continue
		}
		// Only flag RFC-1918 external destinations (simplified: non-private)
		if strings.HasPrefix(dest, "10.") || strings.HasPrefix(dest, "192.168.") ||
			strings.HasPrefix(dest, "172.16.") || strings.HasPrefix(dest, "127.") {
			continue
		}
		key := hostDest{host, dest}
		if seen[key] {
			continue
		}
		seen[key] = true
		a := MakeEndpointAlert("rare_external_destination", "v1", "Rare External Firewall Destination Detected", "medium", 0.62, ev)
		a.Evidence["host"]        = host
		a.Evidence["destination"] = dest
		a.Evidence["advisory"]    = "network_shadow_only"
		a.Evidence["no_blocking"] = true
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleNetworkSuspiciousAllowAfterDeny(events []map[string]any) []EndpointAlert {
	// Detect destination that was denied then allowed in the same batch. Advisory-only.
	deniedDests := map[string]bool{}
	for _, ev := range events {
		if EpStr(ev, "telemetry_type") != "firewall" {
			continue
		}
		action := strings.ToLower(EpStr(ev, "action"))
		if action == "deny" || action == "drop" {
			if d := EpStr(ev, "destination_ip"); d != "" {
				deniedDests[d] = true
			}
		}
	}
	if len(deniedDests) == 0 {
		return nil
	}
	seen := map[string]bool{}
	var alerts []EndpointAlert
	for _, ev := range events {
		if EpStr(ev, "telemetry_type") != "firewall" {
			continue
		}
		if strings.ToLower(EpStr(ev, "action")) != "allow" {
			continue
		}
		dest := EpStr(ev, "destination_ip")
		host := EpStr(ev, "host_id")
		if !deniedDests[dest] || seen[host+"|"+dest] {
			continue
		}
		seen[host+"|"+dest] = true
		a := MakeEndpointAlert("suspicious_allow_after_deny", "v1", "Suspicious Allow After Prior Deny Detected", "high", 0.85, ev)
		a.Evidence["host"]        = host
		a.Evidence["destination"] = dest
		a.Evidence["advisory"]    = "network_shadow_only"
		a.Evidence["no_blocking"] = true
		alerts = append(alerts, a)
	}
	return alerts
}

// ---------------------------------------------------------------------------
// Cross-domain shadow correlation — Phase 1 (2026-05-18)
// Correlates endpoint events with identity/cloud/SaaS events in the same batch.
// All output → xdr.alerts.shadow.endpoint only. Advisory-only. No containment.
// ---------------------------------------------------------------------------

func CorrelateEndpointShadowCrossDomain(events []map[string]any) []EndpointAlert {
	if len(events) == 0 {
		return nil
	}
	var alerts []EndpointAlert
	alerts = append(alerts, ruleCrossDomainIdentityEndpoint(events)...)
	alerts = append(alerts, ruleCrossDomainIdentityPersistence(events)...)
	alerts = append(alerts, ruleCrossDomainSaaSBeacon(events)...)
	alerts = append(alerts, ruleCrossHostSharedDestinationLolbin(events)...)
	alerts = append(alerts, ruleCrossDomainAttackProgression(events)...)
	return alerts
}

func ruleCrossDomainIdentityEndpoint(events []map[string]any) []EndpointAlert {
	// Detect identity auth failure followed by endpoint shell execution for the same user.
	// Advisory-only: emits to shadow topic only.
	identityFailureUsers := map[string]map[string]any{}
	for _, ev := range events {
		if EpStr(ev, "telemetry_type") != "identity" {
			continue
		}
		evType := EpStr(ev, "event_type")
		if evType != "login_failed" && evType != "mfa_failed" {
			continue
		}
		user := EpStr(ev, "user")
		if user != "" {
			identityFailureUsers[user] = ev
		}
	}
	if len(identityFailureUsers) == 0 {
		return nil
	}
	var alerts []EndpointAlert
	for _, ev := range events {
		if EpStr(ev, "telemetry_type") != "endpoint" || EpStr(ev, "event_type") != "process_start" {
			continue
		}
		user    := EpStr(ev, "user")
		process := strings.ToLower(EpStr(ev, "process_name"))
		if user == "" || (!LinuxShellNames[process] && !DownloaderNames[process]) {
			continue
		}
		if identityEv, ok := identityFailureUsers[user]; ok {
			a := MakeEndpointAlert("identity_endpoint_execution_chain", "v1",
				"Identity Failure Followed by Endpoint Shell Execution", "critical", 0.85, ev)
			a.Evidence["actor"]            = user
			a.Evidence["identity_event"]   = EpStr(identityEv, "event_type")
			a.Evidence["endpoint_process"] = process
			a.Evidence["advisory"]         = "cross_domain_shadow_only"
			alerts = append(alerts, a)
		}
	}
	return alerts
}

func ruleCrossDomainIdentityPersistence(events []map[string]any) []EndpointAlert {
	// Detect identity privilege escalation followed by endpoint persistence entry.
	privEscUsers := map[string]map[string]any{}
	for _, ev := range events {
		if EpStr(ev, "telemetry_type") != "identity" {
			continue
		}
		action := EpStr(ev, "action")
		evType := EpStr(ev, "event_type")
		if action != "privilege_escalation" && evType != "privilege_escalation" {
			continue
		}
		user := EpStr(ev, "user")
		if user != "" {
			privEscUsers[user] = ev
		}
	}
	if len(privEscUsers) == 0 {
		return nil
	}
	var alerts []EndpointAlert
	for _, ev := range events {
		if EpStr(ev, "telemetry_type") != "endpoint" {
			continue
		}
		evType := EpStr(ev, "event_type")
		if evType != "service_install" && evType != "scheduled_task_create" {
			continue
		}
		user := EpStr(ev, "user")
		if user == "" {
			continue
		}
		if identityEv, ok := privEscUsers[user]; ok {
			a := MakeEndpointAlert("identity_persistence_correlation", "v1",
				"Identity Privilege Escalation Correlated with Endpoint Persistence", "high", 0.80, ev)
			a.Evidence["actor"]             = user
			a.Evidence["identity_action"]   = EpStr(identityEv, "action")
			a.Evidence["persistence_event"] = evType
			a.Evidence["advisory"]          = "cross_domain_shadow_only"
			alerts = append(alerts, a)
		}
	}
	return alerts
}

func ruleCrossDomainSaaSBeacon(events []map[string]any) []EndpointAlert {
	// Detect SaaS anomaly activity correlated with endpoint outbound beacon from the same source IP.
	saasSourceIPs := map[string]map[string]any{}
	for _, ev := range events {
		if EpStr(ev, "telemetry_type") != "saas" {
			continue
		}
		ip := EpStr(ev, "source_ip")
		if ip != "" {
			saasSourceIPs[ip] = ev
		}
	}
	if len(saasSourceIPs) == 0 {
		return nil
	}
	var alerts []EndpointAlert
	for _, ev := range events {
		if EpStr(ev, "telemetry_type") != "endpoint" || EpStr(ev, "event_type") != "network_connection" {
			continue
		}
		srcIP := EpStr(ev, "source_ip")
		if srcIP == "" {
			srcIP = EpStr(ev, "host")
		}
		if saasEv, ok := saasSourceIPs[srcIP]; ok {
			a := MakeEndpointAlert("saas_endpoint_beacon_chain", "v1",
				"SaaS Activity Correlated with Endpoint Beacon Pattern", "high", 0.77, ev)
			a.Evidence["source_ip"]         = srcIP
			a.Evidence["saas_event_type"]   = EpStr(saasEv, "event_type")
			a.Evidence["endpoint_remote_ip"]= EpStr(ev, "remote_ip")
			a.Evidence["advisory"]          = "cross_domain_shadow_only"
			alerts = append(alerts, a)
		}
	}
	return alerts
}

func ruleCrossHostSharedDestinationLolbin(events []map[string]any) []EndpointAlert {
	// Detect multiple hosts using LOLBin processes to connect to the same destination.
	hostLolbins := map[string]bool{}
	for _, ev := range events {
		if EpStr(ev, "telemetry_type") != "endpoint" || EpStr(ev, "event_type") != "process_start" {
			continue
		}
		name := strings.ToLower(EpStr(ev, "process_name"))
		host := EpStr(ev, "host")
		if LolbinNames[name] && host != "" {
			hostLolbins[host] = true
		}
	}
	if len(hostLolbins) == 0 {
		return nil
	}
	type destKey struct {
		ip   string
		port int64
	}
	destHosts := map[destKey]map[string]bool{}
	destEvs   := map[destKey]map[string]any{}
	for _, ev := range events {
		if EpStr(ev, "telemetry_type") != "endpoint" || EpStr(ev, "event_type") != "network_connection" {
			continue
		}
		host := EpStr(ev, "host")
		if !hostLolbins[host] {
			continue
		}
		ip   := EpStr(ev, "remote_ip")
		port := EpInt64(ev, "remote_port")
		if ip == "" {
			continue
		}
		k := destKey{ip, port}
		if destHosts[k] == nil {
			destHosts[k] = map[string]bool{}
		}
		destHosts[k][host] = true
		if _, exists := destEvs[k]; !exists {
			destEvs[k] = ev
		}
	}
	var alerts []EndpointAlert
	for k, hosts := range destHosts {
		if len(hosts) < 2 {
			continue
		}
		a := MakeEndpointAlert("multi_host_shared_destination", "v1",
			"Multiple Hosts LOLBin Activity to Shared Destination", "critical", 0.88, destEvs[k])
		a.Evidence["destination"] = fmt.Sprintf("%s:%d", k.ip, k.port)
		a.Evidence["host_count"]  = len(hosts)
		a.Evidence["advisory"]    = "cross_domain_shadow_only"
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleCrossDomainAttackProgression(events []map[string]any) []EndpointAlert {
	// Detect same actor touching multiple telemetry domains including endpoint.
	// Requires endpoint involvement + at least one other domain.
	actorDomains := map[string]map[string]bool{}
	actorEvs     := map[string]map[string]any{}
	for _, ev := range events {
		user    := EpStr(ev, "user")
		telType := EpStr(ev, "telemetry_type")
		if user == "" || telType == "" {
			continue
		}
		if actorDomains[user] == nil {
			actorDomains[user] = map[string]bool{}
		}
		actorDomains[user][telType] = true
		if _, exists := actorEvs[user]; !exists {
			actorEvs[user] = ev
		}
	}
	var alerts []EndpointAlert
	for actor, domains := range actorDomains {
		if len(domains) < 2 || !domains["endpoint"] {
			continue
		}
		domainList := make([]string, 0, len(domains))
		for d := range domains {
			domainList = append(domainList, d)
		}
		sort.Strings(domainList)
		a := MakeEndpointAlert("cross_domain_attack_progression", "v1",
			"Cross-Domain Attack Progression Detected", "critical", 0.82, actorEvs[actor])
		a.Evidence["actor"]        = actor
		a.Evidence["domains"]      = strings.Join(domainList, ",")
		a.Evidence["domain_count"] = len(domains)
		a.Evidence["advisory"]     = "cross_domain_shadow_only"
		alerts = append(alerts, a)
	}
	return alerts
}

// ---------------------------------------------------------------------------
// Streaming endpoint shadow rules — Phase 1
// Advisory-only. Publishes to xdr.alerts.shadow.endpoint only.
// No autonomous containment. No kernel telemetry.
// ---------------------------------------------------------------------------

func CorrelateEndpointShadowStreaming(events []map[string]any) []EndpointAlert {
	if len(events) == 0 {
		return nil
	}
	var alerts []EndpointAlert
	alerts = append(alerts, ruleStreamRapidShellChain(events)...)
	alerts = append(alerts, ruleStreamRapidPersistenceCreation(events)...)
	alerts = append(alerts, ruleStreamBurstOutboundActivity(events)...)
	alerts = append(alerts, ruleStreamShortLivedSuspiciousProcess(events)...)
	alerts = append(alerts, ruleStreamRapidExecutionBeaconPattern(events)...)
	return alerts
}

func ruleStreamRapidShellChain(events []map[string]any) []EndpointAlert {
	// Detect ≥3 shell execution events within a single batch for the same host.
	// Advisory-only — no autonomous response.
	hostShellCounts := map[string][]map[string]any{}
	shellNames := map[string]bool{"bash": true, "sh": true, "zsh": true, "cmd": true, "powershell": true,
		"python": true, "python3": true, "perl": true, "ruby": true, "wscript": true, "cscript": true}

	for _, ev := range events {
		if EpStr(ev, "telemetry_type") != "endpoint" {
			continue
		}
		evType := EpStr(ev, "event_type")
		if evType != "process_started" && evType != "shell_execution_detected" {
			continue
		}
		proc := strings.ToLower(EpStr(ev, "process_name"))
		if !shellNames[proc] && evType != "shell_execution_detected" {
			continue
		}
		host := EpStr(ev, "host_id")
		if host == "" {
			host = EpStr(ev, "hostname")
		}
		if host != "" {
			hostShellCounts[host] = append(hostShellCounts[host], ev)
		}
	}

	var alerts []EndpointAlert
	for host, evs := range hostShellCounts {
		if len(evs) < 3 {
			continue
		}
		a := MakeEndpointAlert("stream_rapid_shell_chain", "v1",
			"Rapid Shell Execution Chain Detected in Streaming Telemetry", "high", 0.78, evs[0])
		a.Evidence["host"]          = host
		a.Evidence["shell_count"]   = len(evs)
		a.Evidence["advisory"]      = "streaming_shadow_only"
		a.Evidence["no_autonomous"] = true
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleStreamRapidPersistenceCreation(events []map[string]any) []EndpointAlert {
	// Detect ≥3 persistence creation/modification events in one batch.
	hostPersistCounts := map[string][]map[string]any{}

	for _, ev := range events {
		if EpStr(ev, "telemetry_type") != "endpoint" {
			continue
		}
		evType := EpStr(ev, "event_type")
		if evType != "persistence_item_created" && evType != "persistence_item_modified" {
			continue
		}
		host := EpStr(ev, "host_id")
		if host == "" {
			host = EpStr(ev, "hostname")
		}
		if host != "" {
			hostPersistCounts[host] = append(hostPersistCounts[host], ev)
		}
	}

	var alerts []EndpointAlert
	for host, evs := range hostPersistCounts {
		if len(evs) < 3 {
			continue
		}
		a := MakeEndpointAlert("stream_rapid_persistence_creation", "v1",
			"Rapid Persistence Creation Detected in Streaming Telemetry", "high", 0.80, evs[0])
		a.Evidence["host"]            = host
		a.Evidence["persistence_count"] = len(evs)
		a.Evidence["advisory"]        = "streaming_shadow_only"
		a.Evidence["no_autonomous"]   = true
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleStreamBurstOutboundActivity(events []map[string]any) []EndpointAlert {
	// Detect ≥10 outbound connection events from the same host in one batch.
	hostConnCounts := map[string][]map[string]any{}

	for _, ev := range events {
		if EpStr(ev, "telemetry_type") != "endpoint" {
			continue
		}
		if EpStr(ev, "event_type") != "outbound_connection_opened" {
			continue
		}
		host := EpStr(ev, "host_id")
		if host == "" {
			host = EpStr(ev, "hostname")
		}
		if host != "" {
			hostConnCounts[host] = append(hostConnCounts[host], ev)
		}
	}

	var alerts []EndpointAlert
	for host, evs := range hostConnCounts {
		if len(evs) < 10 {
			continue
		}
		destSet := map[string]bool{}
		for _, ev := range evs {
			if d := EpStr(ev, "connection_dest"); d != "" {
				destSet[d] = true
			}
		}
		a := MakeEndpointAlert("stream_burst_outbound_activity", "v1",
			"Burst Outbound Connection Activity Detected in Streaming Telemetry", "medium", 0.70, evs[0])
		a.Evidence["host"]              = host
		a.Evidence["connection_count"]  = len(evs)
		a.Evidence["unique_dests"]      = len(destSet)
		a.Evidence["advisory"]          = "streaming_shadow_only"
		a.Evidence["no_autonomous"]     = true
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleStreamShortLivedSuspiciousProcess(events []map[string]any) []EndpointAlert {
	// Detect processes that start AND terminate in the same batch with suspicious names.
	suspiciousNames := map[string]bool{
		"nc": true, "ncat": true, "nmap": true, "wget": true, "curl": true,
		"certutil": true, "bitsadmin": true, "mshta": true, "wscript": true, "cscript": true,
	}

	// Map pid@host → started event
	startedProcs := map[string]map[string]any{}
	for _, ev := range events {
		if EpStr(ev, "telemetry_type") != "endpoint" || EpStr(ev, "event_type") != "process_started" {
			continue
		}
		name := strings.ToLower(EpStr(ev, "process_name"))
		if !suspiciousNames[name] {
			continue
		}
		pid  := EpStr(ev, "process_pid")
		host := EpStr(ev, "host_id")
		if pid != "" && host != "" {
			startedProcs[pid+"@"+host] = ev
		}
	}
	if len(startedProcs) == 0 {
		return nil
	}

	var alerts []EndpointAlert
	for _, ev := range events {
		if EpStr(ev, "telemetry_type") != "endpoint" || EpStr(ev, "event_type") != "process_terminated" {
			continue
		}
		pid  := EpStr(ev, "process_pid")
		host := EpStr(ev, "host_id")
		key  := pid + "@" + host
		if startEv, ok := startedProcs[key]; ok {
			a := MakeEndpointAlert("stream_short_lived_suspicious_process", "v1",
				"Short-Lived Suspicious Process Detected in Streaming Telemetry", "medium", 0.72, startEv)
			a.Evidence["host"]         = host
			a.Evidence["process_name"] = EpStr(startEv, "process_name")
			a.Evidence["process_pid"]  = pid
			a.Evidence["advisory"]     = "streaming_shadow_only"
			a.Evidence["no_autonomous"]= true
			alerts = append(alerts, a)
		}
	}
	return alerts
}

func ruleStreamRapidExecutionBeaconPattern(events []map[string]any) []EndpointAlert {
	// Detect ≥4 process_started events for the same process name from the same host in one batch.
	// Consistent count suggests beaconing pattern.
	type hostProc struct{ host, proc string }
	counts := map[hostProc]int{}

	for _, ev := range events {
		if EpStr(ev, "telemetry_type") != "endpoint" || EpStr(ev, "event_type") != "process_started" {
			continue
		}
		host := EpStr(ev, "host_id")
		proc := strings.ToLower(EpStr(ev, "process_name"))
		if host != "" && proc != "" {
			counts[hostProc{host, proc}]++
		}
	}

	var alerts []EndpointAlert
	for key, count := range counts {
		if count < 4 {
			continue
		}
		a := MakeEndpointAlert("stream_rapid_execution_beacon_pattern", "v1",
			"Rapid Execution Beacon Pattern Detected in Streaming Telemetry", "medium", 0.68, map[string]any{})
		a.Evidence["host"]           = key.host
		a.Evidence["process_name"]   = key.proc
		a.Evidence["execution_count"]= count
		a.Evidence["advisory"]       = "streaming_shadow_only"
		a.Evidence["no_autonomous"]  = true
		alerts = append(alerts, a)
	}
	return alerts
}

// EndpointAlert, epStr, epInt64, makeEndpointAlert moved to
// internal/shadowrules (CODE-STRUCT-DECOMPOSE, seam 2).


var suspiciousParentNames = map[string]bool{
	"winword.exe": true, "excel.exe": true, "powerpnt.exe": true,
	"outlook.exe": true, "msdt.exe": true, "mspub.exe": true,
}

var suspiciousChildNames = map[string]bool{
	"cmd.exe": true, "powershell.exe": true, "wscript.exe": true,
	"cscript.exe": true, "mshta.exe": true, "regsvr32.exe": true,
	"rundll32.exe": true, "certutil.exe": true,
}

var powershellEncodedIndicators = []string{" -enc ", " -e ", "-encodedcommand", "/enc ", "/e "}

var executableExtensions = []string{".exe", ".dll", ".bat", ".ps1", ".vbs", ".js", ".hta", ".scr"}

var tempPathPatterns = []string{`\temp\`, `\tmp\`, `\appdata\local\temp\`, "/tmp/", "/temp/"}

var internalIPPrefixes = []string{
	"10.", "172.16.", "172.17.", "172.18.", "172.19.", "172.20.", "172.21.",
	"172.22.", "172.23.", "172.24.", "172.25.", "172.26.", "172.27.", "172.28.",
	"172.29.", "172.30.", "172.31.", "192.168.", "127.", "169.254.",
}

var commonServicePorts = map[int64]bool{
	22: true, 25: true, 53: true, 80: true, 110: true, 143: true,
	443: true, 587: true, 993: true, 995: true, 3389: true, 8080: true, 8443: true,
}

const failedLoginBurstThreshold = 3
const dnsDomainLengthThreshold = 40
const c2BeaconMinConnections = 3

var scheduledTaskProcessNames = map[string]bool{
	"schtasks.exe": true, "at.exe": true, "taskeng.exe": true,
}

var serviceCreateProcessNames = map[string]bool{
	"sc.exe": true,
}

func RuleParentChildProcess(events []map[string]any) []EndpointAlert {
	pidToName := map[int64]string{}
	for _, ev := range events {
		if EpStr(ev, "event_type") != "process_start" {
			continue
		}
		pid := EpInt64(ev, "process", "pid")
		name := strings.ToLower(EpStr(ev, "process", "name"))
		if pid > 0 && name != "" {
			pidToName[pid] = name
		}
	}
	var alerts []EndpointAlert
	for _, ev := range events {
		if EpStr(ev, "event_type") != "process_start" {
			continue
		}
		child := strings.ToLower(EpStr(ev, "process", "name"))
		if !suspiciousChildNames[child] {
			continue
		}
		ppid := EpInt64(ev, "process", "ppid")
		parentName := pidToName[ppid]
		if !suspiciousParentNames[parentName] {
			continue
		}
		a := MakeEndpointAlert("suspicious_parent_child_process", "v1", "Suspicious Parent-Child Process", "high", 0.80, ev)
		a.Evidence["parent_process"] = parentName
		a.Evidence["child_process"] = child
		a.Evidence["ppid"] = ppid
		alerts = append(alerts, a)
	}
	return alerts
}

func RulePowershellEncoded(events []map[string]any) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		if EpStr(ev, "event_type") != "process_start" {
			continue
		}
		name := strings.ToLower(EpStr(ev, "process", "name"))
		if name != "powershell.exe" && name != "pwsh.exe" {
			continue
		}
		cmdLine := strings.ToLower(EpStr(ev, "process", "command_line"))
		matched := false
		for _, indicator := range powershellEncodedIndicators {
			if strings.Contains(cmdLine, indicator) {
				matched = true
				break
			}
		}
		if !matched {
			continue
		}
		a := MakeEndpointAlert("powershell_encoded_command", "v1", "PowerShell Encoded Command Execution", "high", 0.85, ev)
		a.Evidence["command_line"] = EpStr(ev, "process", "command_line")
		alerts = append(alerts, a)
	}
	return alerts
}

func RuleSuspiciousTempFile(events []map[string]any) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		if EpStr(ev, "event_type") != "file_write" {
			continue
		}
		filePath := strings.ToLower(EpStr(ev, "file", "path"))
		op := strings.ToLower(EpStr(ev, "file", "operation"))
		if op != "create" && op != "modify" && op != "overwrite" {
			continue
		}
		inTemp := false
		for _, pat := range tempPathPatterns {
			if strings.Contains(filePath, pat) {
				inTemp = true
				break
			}
		}
		if !inTemp {
			continue
		}
		hasExec := false
		for _, ext := range executableExtensions {
			if strings.HasSuffix(filePath, ext) {
				hasExec = true
				break
			}
		}
		if !hasExec {
			continue
		}
		a := MakeEndpointAlert("suspicious_temp_file_write", "v1", "Executable Written to Temporary Directory", "high", 0.78, ev)
		a.Evidence["file_path"] = EpStr(ev, "file", "path")
		a.Evidence["operation"] = op
		alerts = append(alerts, a)
	}
	return alerts
}

func RuleFailedLoginBurst(events []map[string]any) []EndpointAlert {
	type loginKey struct{ host, user string }
	failedByKey := map[loginKey][]map[string]any{}
	for _, ev := range events {
		if EpStr(ev, "event_type") != "login_event" {
			continue
		}
		action := strings.ToLower(EpStr(ev, "auth", "action"))
		if action != "login_failed" && action != "mfa_failed" {
			continue
		}
		key := loginKey{EpStr(ev, "host"), EpStr(ev, "user")}
		failedByKey[key] = append(failedByKey[key], ev)
	}
	var alerts []EndpointAlert
	for key, failedEvents := range failedByKey {
		if len(failedEvents) < failedLoginBurstThreshold {
			continue
		}
		a := MakeEndpointAlert("failed_login_burst", "v1", "Failed Login Burst", "medium", 0.72, failedEvents[0])
		a.Host = key.host
		a.User = key.user
		a.Evidence["failed_count"] = len(failedEvents)
		a.Evidence["threshold"] = failedLoginBurstThreshold
		ids := make([]string, 0, len(failedEvents))
		for _, ev := range failedEvents {
			if id := EpStr(ev, "normalized_event_id"); id != "" {
				ids = append(ids, id)
			}
		}
		a.EvidenceIDs = ids
		alerts = append(alerts, a)
	}
	return alerts
}

func RuleSuspiciousDNS(events []map[string]any) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		if EpStr(ev, "event_type") != "dns_query" {
			continue
		}
		domain := strings.ToLower(EpStr(ev, "dns", "domain"))
		if domain == "" {
			continue
		}
		reason := ""
		if len(domain) > dnsDomainLengthThreshold {
			reason = "high_length_possible_dga"
		} else {
			digits := 0
			for _, c := range domain {
				if c >= '0' && c <= '9' {
					digits++
				}
			}
			if len(domain) > 0 && float64(digits)/float64(len(domain)) > 0.40 {
				reason = "high_numeric_density"
			}
		}
		if reason == "" {
			continue
		}
		a := MakeEndpointAlert("suspicious_dns_query", "v1", "Suspicious DNS Query", "medium", 0.68, ev)
		a.Evidence["domain"] = domain
		a.Evidence["reason"] = reason
		a.Evidence["domain_length"] = len(domain)
		alerts = append(alerts, a)
	}
	return alerts
}

func RuleSuspiciousOutbound(events []map[string]any) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		if EpStr(ev, "event_type") != "network_connection" {
			continue
		}
		dstIP := EpStr(ev, "network", "destination_ip")
		dstPort := EpInt64(ev, "network", "destination_port")
		if dstIP == "" || dstPort == 0 {
			continue
		}
		isInternal := false
		for _, prefix := range internalIPPrefixes {
			if strings.HasPrefix(dstIP, prefix) {
				isInternal = true
				break
			}
		}
		if isInternal {
			continue
		}
		if commonServicePorts[dstPort] {
			continue
		}
		a := MakeEndpointAlert("suspicious_outbound_connection", "v1", "Suspicious Outbound Network Connection", "medium", 0.65, ev)
		a.Evidence["destination_ip"] = dstIP
		a.Evidence["destination_port"] = dstPort
		a.Evidence["protocol"] = EpStr(ev, "network", "protocol")
		alerts = append(alerts, a)
	}
	return alerts
}

func RuleScheduledTaskPersistence(events []map[string]any) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		evType := EpStr(ev, "event_type")
		if evType != "process_start" && evType != "scheduled_task_create" {
			continue
		}
		name := strings.ToLower(EpStr(ev, "process", "name"))
		cmdLine := strings.ToLower(EpStr(ev, "process", "command_line"))
		isScheduled := scheduledTaskProcessNames[name] ||
			(name == "schtasks.exe" && (strings.Contains(cmdLine, "/create") || strings.Contains(cmdLine, "-create"))) ||
			evType == "scheduled_task_create"
		if !isScheduled {
			continue
		}
		a := MakeEndpointAlert("scheduled_task_persistence", "v1", "Scheduled Task Persistence", "high", 0.75, ev)
		a.Evidence["process_name"] = EpStr(ev, "process", "name")
		a.Evidence["command_line"] = EpStr(ev, "process", "command_line")
		a.Evidence["task_name"] = EpStr(ev, "scheduled_task", "name")
		alerts = append(alerts, a)
	}
	return alerts
}

func RuleNewServicePersistence(events []map[string]any) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		evType := EpStr(ev, "event_type")
		if evType != "process_start" && evType != "service_install" {
			continue
		}
		name := strings.ToLower(EpStr(ev, "process", "name"))
		cmdLine := strings.ToLower(EpStr(ev, "process", "command_line"))
		isServiceCreate := (serviceCreateProcessNames[name] && (strings.Contains(cmdLine, "create") || strings.Contains(cmdLine, "config"))) ||
			evType == "service_install"
		if !isServiceCreate {
			continue
		}
		a := MakeEndpointAlert("new_service_persistence", "v1", "New Service Installed", "high", 0.78, ev)
		a.Evidence["process_name"] = EpStr(ev, "process", "name")
		a.Evidence["command_line"] = EpStr(ev, "process", "command_line")
		a.Evidence["service_name"] = EpStr(ev, "service", "name")
		alerts = append(alerts, a)
	}
	return alerts
}

func RuleC2BeaconPattern(events []map[string]any) []EndpointAlert {
	type connKey struct{ host, dst string; port int64 }
	byKey := map[connKey][]map[string]any{}
	for _, ev := range events {
		if EpStr(ev, "event_type") != "network_connection" {
			continue
		}
		dstIP := EpStr(ev, "network", "destination_ip")
		dstPort := EpInt64(ev, "network", "destination_port")
		if dstIP == "" || dstPort == 0 {
			continue
		}
		internal := false
		for _, prefix := range internalIPPrefixes {
			if strings.HasPrefix(dstIP, prefix) {
				internal = true
				break
			}
		}
		if internal {
			continue
		}
		key := connKey{EpStr(ev, "host"), dstIP, dstPort}
		byKey[key] = append(byKey[key], ev)
	}
	var alerts []EndpointAlert
	for key, evs := range byKey {
		if len(evs) < c2BeaconMinConnections {
			continue
		}
		a := MakeEndpointAlert("c2_beacon_pattern", "v1", "Possible C2 Beacon Pattern", "high", 0.72, evs[0])
		a.Host = key.host
		a.Evidence["destination_ip"] = key.dst
		a.Evidence["destination_port"] = key.port
		a.Evidence["connection_count"] = len(evs)
		a.Evidence["threshold"] = c2BeaconMinConnections
		ids := make([]string, 0, len(evs))
		for _, ev := range evs {
			if id := EpStr(ev, "normalized_event_id"); id != "" {
				ids = append(ids, id)
			}
		}
		a.EvidenceIDs = ids
		alerts = append(alerts, a)
	}
	return alerts
}

// ---------------------------------------------------------------------------
// Behavioral visibility rules — Phase 1 (2026-05-18)
// Shadow-only; consume enriched endpoint telemetry from behavioral agent.
// ---------------------------------------------------------------------------

var webServerProcessNames = map[string]bool{
	"nginx": true, "apache": true, "apache2": true, "httpd": true,
	"gunicorn": true, "uwsgi": true, "php-fpm": true, "tomcat": true,
	"mysqld": true, "postgres": true, "mongod": true, "redis-server": true,
}

// linuxShellNames moved to internal/LinuxShellNames (CODE-STRUCT-DECOMPOSE, seam 3).

const longLivedThresholdSeconds = 3600 // 1 hour

func ruleParentChildChain(events []map[string]any) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		if EpStr(ev, "event_type") != "process_start" {
			continue
		}
		childName := strings.ToLower(EpStr(ev, "process_name"))
		parentName := strings.ToLower(EpStr(ev, "parent_process_name"))
		if !LinuxShellNames[childName] {
			continue
		}
		if !webServerProcessNames[parentName] {
			continue
		}
		a := MakeEndpointAlert("suspicious_parent_child_chain", "v1",
			"Suspicious Parent-Child Process Chain (Behavioral)", "high", 0.78, ev)
		a.Evidence["parent_process_name"] = parentName
		a.Evidence["child_process"] = childName
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleShellChain(events []map[string]any) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		if EpStr(ev, "event_type") != "process_start" {
			continue
		}
		childName := strings.ToLower(EpStr(ev, "process_name"))
		parentName := strings.ToLower(EpStr(ev, "parent_process_name"))
		if !LinuxShellNames[childName] {
			continue
		}
		if !LinuxShellNames[parentName] {
			continue
		}
		if childName == parentName {
			continue // ignore same-shell (e.g. sh → sh in scripts)
		}
		a := MakeEndpointAlert("suspicious_shell_chain", "v1",
			"Suspicious Shell-to-Shell Execution Chain", "high", 0.75, ev)
		a.Evidence["parent_shell"] = parentName
		a.Evidence["child_shell"] = childName
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleLongLivedProcess(events []map[string]any) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		if EpStr(ev, "event_type") != "process_start" {
			continue
		}
		processName := strings.ToLower(EpStr(ev, "process_name"))
		if !LinuxShellNames[processName] {
			continue
		}
		dur := EpInt64(ev, "duration_seconds")
		if dur < longLivedThresholdSeconds {
			continue
		}
		a := MakeEndpointAlert("suspicious_long_lived_process", "v1",
			"Suspicious Long-Lived Interactive Process", "medium", 0.65, ev)
		a.Evidence["process_name"] = processName
		a.Evidence["duration_seconds"] = dur
		a.Evidence["threshold_seconds"] = longLivedThresholdSeconds
		alerts = append(alerts, a)
	}
	return alerts
}

func rulePersistenceEntry(events []map[string]any) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		evType := EpStr(ev, "event_type")
		if evType != "service_install" && evType != "scheduled_task_create" {
			continue
		}
		itemKey := EpStr(ev, "persistence_item_key")
		if itemKey == "" {
			itemKey = EpStr(ev, "service_name")
		}
		if itemKey == "" {
			itemKey = EpStr(ev, "task_name")
		}
		a := MakeEndpointAlert("suspicious_persistence_entry", "v1",
			"New or Unexpected Persistence Entry", "high", 0.72, ev)
		a.Evidence["event_type"] = evType
		a.Evidence["item_key"] = itemKey
		alerts = append(alerts, a)
	}
	return alerts
}

// ---------------------------------------------------------------------------
// Behavioral analytics rules — Phase 1 (2026-05-18)
// Shadow-only; advisory findings only. No active containment.
// ---------------------------------------------------------------------------

// downloaderNames, lolbinNames moved to internal/shadowrules (CODE-STRUCT-DECOMPOSE, seam 3).

const chainScoreThreshold = 0.50

func ruleExecutionChain(events []map[string]any) []EndpointAlert {
	// Build pid → name map
	pidToNameAnalytics := map[int64]string{}
	for _, ev := range events {
		if EpStr(ev, "event_type") != "process_start" {
			continue
		}
		pid := EpInt64(ev, "pid")
		if pid > 0 {
			pidToNameAnalytics[pid] = strings.ToLower(EpStr(ev, "process_name"))
		}
	}

	var alerts []EndpointAlert
	for _, ev := range events {
		if EpStr(ev, "event_type") != "process_start" {
			continue
		}
		name := strings.ToLower(EpStr(ev, "process_name"))
		if !LinuxShellNames[name] {
			continue
		}
		// Check if ancestry includes a downloader
		ppid := EpInt64(ev, "ppid")
		parentName := pidToNameAnalytics[ppid]
		if !DownloaderNames[parentName] && !LinuxShellNames[parentName] {
			continue
		}

		score := 0.60
		if DownloaderNames[parentName] {
			score += 0.20
		}
		if score < chainScoreThreshold {
			continue
		}
		a := MakeEndpointAlert("suspicious_execution_chain", "v1",
			"Suspicious Process Execution Chain", "high", score, ev)
		a.Evidence["chain"] = parentName + " → " + name
		a.Evidence["score"] = score
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleBeaconPatternAnalytics(events []map[string]any) []EndpointAlert {
	type destKey struct{ ip string; port int64 }
	procDests := map[string]map[destKey]int{}
	for _, ev := range events {
		if EpStr(ev, "event_type") != "network_connection" {
			continue
		}
		proc := strings.ToLower(EpStr(ev, "process_name"))
		ip   := EpStr(ev, "remote_ip")
		port := EpInt64(ev, "remote_port")
		if ip == "" || proc == "" {
			continue
		}
		if procDests[proc] == nil {
			procDests[proc] = map[destKey]int{}
		}
		procDests[proc][destKey{ip, port}]++
	}

	var alerts []EndpointAlert
	for proc, dests := range procDests {
		for dk, cnt := range dests {
			if cnt < 3 {
				continue
			}
			// Find a representative event
			var repEv map[string]any
			for _, ev := range events {
				if EpStr(ev, "event_type") == "network_connection" &&
					strings.ToLower(EpStr(ev, "process_name")) == proc &&
					EpStr(ev, "remote_ip") == dk.ip {
					repEv = ev
					break
				}
			}
			if repEv == nil {
				continue
			}
			a := MakeEndpointAlert("suspicious_beacon_pattern", "v1",
				"Suspicious Beacon-like Outbound Pattern", "high", 0.75, repEv)
			a.Evidence["process"]          = proc
			a.Evidence["destination"]      = fmt.Sprintf("%s:%d", dk.ip, dk.port)
			a.Evidence["connection_count"] = cnt
			alerts = append(alerts, a)
		}
	}
	return alerts
}

func ruleLolbinUsage(events []map[string]any) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		if EpStr(ev, "event_type") != "process_start" {
			continue
		}
		name := strings.ToLower(EpStr(ev, "process_name"))
		if !LolbinNames[name] {
			continue
		}
		parentName := strings.ToLower(EpStr(ev, "parent_process_name"))

		confidence := 0.60
		if webServerProcessNames[parentName] {
			confidence += 0.15
		}
		cmdLine := strings.ToLower(EpStr(ev, "command_line"))
		if strings.Contains(cmdLine, "base64") {
			confidence += 0.15
		}
		a := MakeEndpointAlert("suspicious_lolbin_usage", "v1",
			"Living-off-the-Land Binary (LOLBin) Usage", "medium", confidence, ev)
		a.Evidence["lolbin"]         = name
		a.Evidence["parent_process"] = parentName
		a.Evidence["confidence"]     = confidence
		alerts = append(alerts, a)
	}
	return alerts
}

func rulePersistenceCorrelationAnalytics(events []map[string]any) []EndpointAlert {
	var persistEvents, shellEvents, networkEvents []map[string]any
	for _, ev := range events {
		evType := EpStr(ev, "event_type")
		if evType == "service_install" || evType == "scheduled_task_create" {
			persistEvents = append(persistEvents, ev)
		} else if evType == "process_start" && LinuxShellNames[strings.ToLower(EpStr(ev, "process_name"))] {
			shellEvents = append(shellEvents, ev)
		} else if evType == "network_connection" {
			networkEvents = append(networkEvents, ev)
		}
	}
	if len(persistEvents) == 0 || len(shellEvents) == 0 || len(networkEvents) == 0 {
		return nil
	}
	// Emit one finding for the combination
	a := MakeEndpointAlert("suspicious_persistence_correlation", "v1",
		"Persistence + Active Shell + Outbound Correlation", "high", 0.72, persistEvents[0])
	a.Evidence["persistence_events"] = len(persistEvents)
	a.Evidence["shell_events"]       = len(shellEvents)
	a.Evidence["network_events"]     = len(networkEvents)
	return []EndpointAlert{a}
}

func ruleRareParentChildAnalytics(events []map[string]any) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		if EpStr(ev, "event_type") != "process_start" {
			continue
		}
		parent := strings.ToLower(EpStr(ev, "parent_process_name"))
		child  := strings.ToLower(EpStr(ev, "process_name"))
		if !webServerProcessNames[parent] {
			continue
		}
		if !LinuxShellNames[child] {
			continue
		}
		a := MakeEndpointAlert("rare_parent_child_process", "v1",
			"Rare Parent-Child Process Relationship", "high", 0.82, ev)
		a.Evidence["parent"] = parent
		a.Evidence["child"]  = child
		alerts = append(alerts, a)
	}
	return alerts
}

// ---------------------------------------------------------------------------
// Threat hunting behavioral rules — Phase 1 (2026-05-18)
// ---------------------------------------------------------------------------

func ruleRepeatedBehavioralChain(events []map[string]any) []EndpointAlert {
	// Detect same parent→child chain appearing multiple times in the batch
	type chainKey struct{ parent, child string }
	chainCounts := map[chainKey]int{}
	chainEvs    := map[chainKey]map[string]any{}
	for _, ev := range events {
		if EpStr(ev, "event_type") != "process_start" {
			continue
		}
		parent := strings.ToLower(EpStr(ev, "parent_process_name"))
		child  := strings.ToLower(EpStr(ev, "process_name"))
		if !LinuxShellNames[child] && !DownloaderNames[child] {
			continue
		}
		k := chainKey{parent, child}
		chainCounts[k]++
		if chainCounts[k] == 1 {
			chainEvs[k] = ev
		}
	}
	var alerts []EndpointAlert
	for k, cnt := range chainCounts {
		if cnt < 2 {
			continue
		}
		a := MakeEndpointAlert("repeated_behavioral_chain", "v1",
			"Repeated Behavioral Execution Chain Pattern", "high", 0.75, chainEvs[k])
		a.Evidence["parent"]       = k.parent
		a.Evidence["child"]        = k.child
		a.Evidence["repeat_count"] = cnt
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleMultiHostBeaconPattern(events []map[string]any) []EndpointAlert {
	// Detect same destination targeted by multiple hosts
	type destKey struct{ ip string; port int64 }
	destHosts := map[destKey]map[string]bool{}
	destEvs   := map[destKey]map[string]any{}
	for _, ev := range events {
		if EpStr(ev, "event_type") != "network_connection" {
			continue
		}
		ip   := EpStr(ev, "remote_ip")
		port := EpInt64(ev, "remote_port")
		host := EpStr(ev, "host")
		if ip == "" || host == "" {
			continue
		}
		k := destKey{ip, port}
		if destHosts[k] == nil {
			destHosts[k] = map[string]bool{}
		}
		destHosts[k][host] = true
		if _, exists := destEvs[k]; !exists {
			destEvs[k] = ev
		}
	}
	var alerts []EndpointAlert
	for k, hosts := range destHosts {
		if len(hosts) < 2 {
			continue
		}
		a := MakeEndpointAlert("multi_host_beacon_pattern", "v1",
			"Multi-Host Beacon Pattern to Same Destination", "critical", 0.82, destEvs[k])
		a.Evidence["destination"] = fmt.Sprintf("%s:%d", k.ip, k.port)
		a.Evidence["host_count"]  = len(hosts)
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleRepeatedLolbinSequence(events []map[string]any) []EndpointAlert {
	lolbinCounts := map[string]int{}
	lolbinEvs    := map[string]map[string]any{}
	for _, ev := range events {
		if EpStr(ev, "event_type") != "process_start" {
			continue
		}
		name := strings.ToLower(EpStr(ev, "process_name"))
		if !LolbinNames[name] {
			continue
		}
		lolbinCounts[name]++
		if lolbinCounts[name] == 1 {
			lolbinEvs[name] = ev
		}
	}
	var alerts []EndpointAlert
	for name, cnt := range lolbinCounts {
		if cnt < 3 {
			continue
		}
		a := MakeEndpointAlert("repeated_lolbin_sequence", "v1",
			"Repeated LOLBin Execution Sequence", "high", 0.72, lolbinEvs[name])
		a.Evidence["lolbin"]       = name
		a.Evidence["repeat_count"] = cnt
		alerts = append(alerts, a)
	}
	return alerts
}

func rulePersistenceReactivation(events []map[string]any) []EndpointAlert {
	// Detect persistence item key appearing in both service_install and a later snapshot's service_install
	seenPersist := map[string]int{}
	var alerts []EndpointAlert
	for _, ev := range events {
		evType := EpStr(ev, "event_type")
		if evType != "service_install" && evType != "scheduled_task_create" {
			continue
		}
		key := EpStr(ev, "service_name")
		if key == "" {
			key = EpStr(ev, "task_name")
		}
		if key == "" {
			continue
		}
		seenPersist[key]++
		if seenPersist[key] == 2 {
			a := MakeEndpointAlert("persistence_reactivation_pattern", "v1",
				"Persistence Item Reactivation Pattern", "high", 0.70, ev)
			a.Evidence["item_key"]     = key
			a.Evidence["event_type"]   = evType
			a.Evidence["repeat_count"] = seenPersist[key]
			alerts = append(alerts, a)
		}
	}
	return alerts
}

func CorrelateEndpointShadow(events []map[string]any) []EndpointAlert {
	endpointEvents := make([]map[string]any, 0, len(events))
	for _, ev := range events {
		if EpStr(ev, "telemetry_type") == "endpoint" {
			endpointEvents = append(endpointEvents, ev)
		}
	}
	if len(endpointEvents) == 0 {
		return nil
	}
	var alerts []EndpointAlert
	alerts = append(alerts, RuleParentChildProcess(endpointEvents)...)
	alerts = append(alerts, RulePowershellEncoded(endpointEvents)...)
	alerts = append(alerts, RuleSuspiciousTempFile(endpointEvents)...)
	alerts = append(alerts, RuleFailedLoginBurst(endpointEvents)...)
	alerts = append(alerts, RuleSuspiciousDNS(endpointEvents)...)
	alerts = append(alerts, RuleSuspiciousOutbound(endpointEvents)...)
	alerts = append(alerts, RuleScheduledTaskPersistence(endpointEvents)...)
	alerts = append(alerts, RuleNewServicePersistence(endpointEvents)...)
	alerts = append(alerts, RuleC2BeaconPattern(endpointEvents)...)
	// Phase 1 behavioral visibility rules (2026-05-18)
	alerts = append(alerts, ruleParentChildChain(endpointEvents)...)
	alerts = append(alerts, ruleShellChain(endpointEvents)...)
	alerts = append(alerts, ruleLongLivedProcess(endpointEvents)...)
	alerts = append(alerts, rulePersistenceEntry(endpointEvents)...)
	// Phase 1 behavioral analytics rules (2026-05-18)
	alerts = append(alerts, ruleExecutionChain(endpointEvents)...)
	alerts = append(alerts, ruleBeaconPatternAnalytics(endpointEvents)...)
	alerts = append(alerts, ruleLolbinUsage(endpointEvents)...)
	alerts = append(alerts, rulePersistenceCorrelationAnalytics(endpointEvents)...)
	alerts = append(alerts, ruleRareParentChildAnalytics(endpointEvents)...)
	// Threat hunting behavioral rules (2026-05-18)
	alerts = append(alerts, ruleRepeatedBehavioralChain(endpointEvents)...)
	alerts = append(alerts, ruleMultiHostBeaconPattern(endpointEvents)...)
	alerts = append(alerts, ruleRepeatedLolbinSequence(endpointEvents)...)
	alerts = append(alerts, rulePersistenceReactivation(endpointEvents)...)
	return DedupeEndpointAlerts(alerts)
}
