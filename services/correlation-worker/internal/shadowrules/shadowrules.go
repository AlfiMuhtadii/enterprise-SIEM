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
