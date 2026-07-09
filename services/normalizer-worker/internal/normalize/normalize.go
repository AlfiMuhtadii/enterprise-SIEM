package normalize

import (
	"fmt"
	"strconv"
	"strings"

	"detector-xdr-normalizer-worker/internal/traceparent"
)

func Event(raw map[string]any) (map[string]any, error) {
	out, err := dispatch(raw)
	if err != nil {
		return nil, err
	}
	inboundTP, _ := raw["traceparent"].(string)
	out["traceparent"] = traceparent.Propagate(inboundTP)
	return out, nil
}

func dispatch(raw map[string]any) (map[string]any, error) {
	ts := first(raw, "ts", "timestamp", "event_time")
	telemetryType := strings.ToLower(fmt.Sprint(first(raw, "telemetry_type", "source_type", "category")))
	eventType := strings.ToLower(fmt.Sprint(first(raw, "event_type", "action", "operation")))
	if ts == "" || telemetryType == "" || eventType == "" {
		return nil, fmt.Errorf("missing_required_fields")
	}
	if telemetryType == "endpoint" {
		return Endpoint(raw)
	}
	if telemetryType == "dns" {
		return Dns(raw)
	}
	if telemetryType == "proxy" {
		return Proxy(raw)
	}
	if telemetryType == "firewall" {
		return Firewall(raw)
	}
	if telemetryType == "identity_provider" {
		return IdentityProvider(raw)
	}
	if telemetryType == "saas_audit" {
		return SaasAudit(raw)
	}
	if telemetryType == "ticket_sync" {
		return TicketSync(raw)
	}
	if telemetryType == "notification" {
		return NotificationEvent(raw)
	}
	if telemetryType == "sysmon" {
		return Sysmon(raw)
	}
	if telemetryType == "powershell" {
		return PowerShell(raw)
	}
	if telemetryType == "security_event" {
		return WindowsSecurityEvent(raw)
	}
	if telemetryType == "syslog_cef" {
		return CefSyslog(raw)
	}
	return map[string]any{
		"schema_version":  1,
		"ts":              ts,
		"event_id":        first(raw, "event_id", "id"),
		"telemetry_type":  telemetryType,
		"event_type":      eventType,
		"user":            first(raw, "user", "xdr_user", "principal", "actor"),
		"host":            first(raw, "host", "host_id", "device_name"),
		"source_ip":       first(raw, "source_ip", "src_ip", "client_ip"),
		"destination_ip":  first(raw, "destination_ip", "dst_ip", "server_ip"),
		"domain":          first(raw, "domain", "query", "url_domain"),
		"file_hash":       first(raw, "file_hash", "sha256"),
		"email_sender":    first(raw, "email_sender", "sender"),
		"email_recipient": first(raw, "email_recipient", "recipient"),
		"cloud_account":   first(raw, "cloud_account", "account_id", "tenant_id"),
		"action":          first(raw, "action", "operation"),
		"result":          first(raw, "result", "outcome", "status"),
		"risk_score":      number(first(raw, "risk_score", "risk", "score")),
		"event_source":    first(raw, "event_source", "source_adapter", "vendor"),
		"trace_id":        first(raw, "trace_id"),
		// Demo lineage metadata — injected by demo_feed.py; empty string for non-demo events.
		"demo_run_id":     first(raw, "demo_run_id"),
		"source_event_id": first(raw, "source_event_id"),
		"scenario_id":     first(raw, "scenario_id"),
		"tenant_id":       first(raw, "tenant_id"),
	}, nil
}

func Endpoint(raw map[string]any) (map[string]any, error) {
	eventID := first(raw, "event_id", "id")
	return map[string]any{
		"schema_version":        1,
		"normalization_version": "endpoint-v1",
		"normalized_event_id":   eventID,
		"raw_event_id":          eventID,
		"ts":                    first(raw, "ts", "timestamp", "event_time"),
		"telemetry_type":        "endpoint",
		"event_type":            strings.ToLower(first(raw, "event_type", "action", "operation")),
		"host":                  first(raw, "host", "host_id", "device_name"),
		"user":                  first(raw, "user"),
		"risk_score":            number(first(raw, "risk_score", "risk", "score")),
		"event_source":          first(raw, "event_source", "source_adapter", "vendor"),
		"trace_id":              first(raw, "trace_id"),
		"process": map[string]any{
			"name":         first(raw, "process_name"),
			"pid":          rawIntField(raw, "pid"),
			"ppid":         rawIntField(raw, "ppid"),
			"command_line": first(raw, "command_line"),
			"path":         first(raw, "process_path"),
			"hash":         first(raw, "file_hash", "sha256"),
		},
		"network": map[string]any{
			"source_ip":        first(raw, "source_ip", "src_ip", "client_ip"),
			"destination_ip":   first(raw, "destination_ip", "dst_ip", "server_ip"),
			"destination_port": rawIntField(raw, "destination_port"),
			"protocol":         strings.ToLower(first(raw, "protocol")),
		},
		"dns": map[string]any{
			"domain":       first(raw, "domain", "query", "url_domain"),
			"resolved_ips": raw["resolved_ips"],
		},
		"file": map[string]any{
			"path":      first(raw, "file_path"),
			"hash":      first(raw, "file_hash", "sha256"),
			"operation": strings.ToLower(first(raw, "operation")),
		},
		"auth": map[string]any{
			"action": strings.ToLower(first(raw, "action")),
			"result": strings.ToLower(first(raw, "result", "outcome")),
		},
		"tenant_id":       first(raw, "tenant_id"),
		"demo_run_id":     first(raw, "demo_run_id"),
		"source_event_id": first(raw, "source_event_id"),
		"scenario_id":     first(raw, "scenario_id"),
	}, nil
}

// Sysmon normalizes Sysmon telemetry events (telemetry_type=sysmon).
// Sysmon events already carry endpoint-like fields; we unify them with the
// standard endpoint envelope and add sysmon-specific metadata.
func Sysmon(raw map[string]any) (map[string]any, error) {
	eventID := first(raw, "event_id", "id")
	sysmonEventID := rawIntField(raw, "sysmon_event_id")
	out := map[string]any{
		"schema_version":        1,
		"normalization_version": "sysmon-v1",
		"normalized_event_id":   eventID,
		"raw_event_id":          eventID,
		"ts":                    first(raw, "ts", "timestamp", "event_time"),
		"telemetry_type":        "endpoint",
		"event_type":            strings.ToLower(first(raw, "event_type", "action")),
		"host":                  first(raw, "host", "host_id", "device_name"),
		"user":                  first(raw, "user"),
		"event_source":          "sysmon",
		"trace_id":              first(raw, "trace_id"),
		"sysmon_event_id":       sysmonEventID,
		"is_advisory":           true,
		"process": map[string]any{
			"name":            first(raw, "process_name", "Image"),
			"command_line":    first(raw, "command_line", "CommandLine"),
			"parent_name":     first(raw, "parent_process_name", "ParentImage"),
			"integrity_level": first(raw, "integrity_level", "IntegrityLevel"),
		},
		"network": map[string]any{
			"destination_ip":   first(raw, "destination_ip", "DestinationIp"),
			"destination_port": rawIntField(raw, "destination_port"),
		},
		"script": map[string]any{
			"is_encoded":      raw["is_encoded"],
			"decoded_preview": first(raw, "decoded_preview"),
			"script_hash":     first(raw, "script_hash"),
			"script_source":   first(raw, "script_source"),
		},
		"registry": map[string]any{
			"key":   first(raw, "registry_key", "TargetObject"),
			"value": first(raw, "registry_value", "Details"),
		},
	}
	out["tenant_id"] = first(raw, "tenant_id")
	out["demo_run_id"] = first(raw, "demo_run_id")
	out["source_event_id"] = first(raw, "source_event_id")
	out["scenario_id"] = first(raw, "scenario_id")
	return out, nil
}

// PowerShell normalizes Windows PowerShell operational log events
// (telemetry_type=powershell).
func PowerShell(raw map[string]any) (map[string]any, error) {
	eventID := first(raw, "event_id", "id")
	return map[string]any{
		"schema_version":        1,
		"normalization_version": "powershell-v1",
		"normalized_event_id":   eventID,
		"raw_event_id":          eventID,
		"ts":                    first(raw, "ts", "timestamp", "event_time"),
		"telemetry_type":        "endpoint",
		"event_type":            "script_execution",
		"host":                  first(raw, "host", "host_id", "device_name"),
		"user":                  first(raw, "user"),
		"event_source":          "powershell_operational",
		"trace_id":              first(raw, "trace_id"),
		"is_advisory":           true,
		"process": map[string]any{
			"name":         "powershell.exe",
			"command_line": first(raw, "command_line", "script_block", "ScriptBlockText"),
		},
		"script": map[string]any{
			"is_encoded":      raw["is_encoded"],
			"decoded_preview": first(raw, "decoded_preview"),
			"script_hash":     first(raw, "script_hash"),
			"script_source":   first(raw, "script_source"),
			"ps_event_id":     rawIntField(raw, "ps_event_id"),
		},
		"tenant_id":       first(raw, "tenant_id"),
		"demo_run_id":     first(raw, "demo_run_id"),
		"source_event_id": first(raw, "source_event_id"),
		"scenario_id":     first(raw, "scenario_id"),
	}, nil
}

// WindowsSecurityEvent normalizes Windows Security Event Log entries
// (telemetry_type=security_event). Handles 4688 (process create),
// 4672 (special privileges), 4697/4698 (service/task install).
func WindowsSecurityEvent(raw map[string]any) (map[string]any, error) {
	eventID := first(raw, "event_id", "id")
	securityEventID := rawIntField(raw, "security_event_id")
	eventType := strings.ToLower(first(raw, "event_type", "action"))
	return map[string]any{
		"schema_version":        1,
		"normalization_version": "security-event-v1",
		"normalized_event_id":   eventID,
		"raw_event_id":          eventID,
		"ts":                    first(raw, "ts", "timestamp", "event_time"),
		"telemetry_type":        "endpoint",
		"event_type":            eventType,
		"host":                  first(raw, "host", "host_id", "device_name"),
		"user":                  first(raw, "user", "SubjectUserName"),
		"event_source":          "security_event",
		"trace_id":              first(raw, "trace_id"),
		"security_event_id":     securityEventID,
		"is_advisory":           true,
		"process": map[string]any{
			"name":            first(raw, "process_name", "NewProcessName"),
			"command_line":    first(raw, "command_line", "CommandLine"),
			"integrity_level": first(raw, "integrity_level", "MandatoryLabel"),
			"parent_name":     first(raw, "parent_process_name", "ParentProcessName"),
		},
		"privilege": map[string]any{
			"escalation_type":  first(raw, "escalation_type"),
			"persistence_type": first(raw, "persistence_type"),
		},
		"tenant_id":       first(raw, "tenant_id"),
		"demo_run_id":     first(raw, "demo_run_id"),
		"source_event_id": first(raw, "source_event_id"),
		"scenario_id":     first(raw, "scenario_id"),
	}, nil
}

func rawIntField(raw map[string]any, key string) any {
	v, ok := raw[key]
	if !ok || v == nil {
		return nil
	}
	switch val := v.(type) {
	case float64:
		return int64(val)
	case int64:
		return val
	case int:
		return int64(val)
	default:
		return nil
	}
}

func first(row map[string]any, keys ...string) string {
	for _, key := range keys {
		if value, ok := row[key]; ok && value != nil && fmt.Sprint(value) != "" {
			return fmt.Sprint(value)
		}
	}
	return ""
}

func number(text string) float64 {
	value, err := strconv.ParseFloat(text, 64)
	if err != nil {
		return 0
	}
	return value
}

// ---------------------------------------------------------------------------
// Network telemetry normalizers — dns-v1, proxy-v1, firewall-v1
// Preserves trace_id, event_id, occurred_at, source_service, host_id, user, source_ip.
// Advisory-only. No active network blocking.
// ---------------------------------------------------------------------------

func Dns(raw map[string]any) (map[string]any, error) {
	eventID := first(raw, "event_id", "id")
	return map[string]any{
		"schema_version":        1,
		"normalization_version": "dns-v1",
		"normalized_event_id":   eventID,
		"raw_event_id":          eventID,
		"ts":                    first(raw, "ts", "timestamp", "occurred_at", "event_time"),
		"telemetry_type":        "dns",
		"event_type":            "dns_query",
		"host_id":               first(raw, "host_id", "host", "device_name"),
		"agent_id":              first(raw, "agent_id"),
		"source_ip":             first(raw, "source_ip", "src_ip", "client_ip"),
		"user":                  first(raw, "user"),
		"queried_domain":        first(raw, "queried_domain", "domain", "query"),
		"query_type":            strings.ToUpper(first(raw, "query_type", "qtype")),
		"response_code":         strings.ToUpper(first(raw, "response_code", "rcode")),
		"resolved_ips":          raw["resolved_ips"],
		"source_service":        first(raw, "source_service", "event_source", "vendor"),
		"trace_id":              first(raw, "trace_id"),
		"tenant_id":             first(raw, "tenant_id"),
		"demo_run_id":           first(raw, "demo_run_id"),
		"source_event_id":       first(raw, "source_event_id"),
		"scenario_id":           first(raw, "scenario_id"),
	}, nil
}

func Proxy(raw map[string]any) (map[string]any, error) {
	eventID := first(raw, "event_id", "id")
	statusCode := 0
	if sc, ok := raw["status_code"].(float64); ok {
		statusCode = int(sc)
	}
	return map[string]any{
		"schema_version":        1,
		"normalization_version": "proxy-v1",
		"normalized_event_id":   eventID,
		"raw_event_id":          eventID,
		"ts":                    first(raw, "ts", "timestamp", "occurred_at", "event_time"),
		"telemetry_type":        "proxy",
		"event_type":            "proxy_request",
		"host_id":               first(raw, "host_id", "host", "device_name"),
		"agent_id":              first(raw, "agent_id"),
		"source_ip":             first(raw, "source_ip", "src_ip", "client_ip"),
		"user":                  first(raw, "user"),
		"url":                   first(raw, "url"),
		"domain":                first(raw, "domain", "url_domain"),
		"http_method":           strings.ToUpper(first(raw, "http_method", "method")),
		"status_code":           statusCode,
		"user_agent":            first(raw, "user_agent", "ua"),
		"bytes_out":             raw["bytes_out"],
		"bytes_in":              raw["bytes_in"],
		"source_service":        first(raw, "source_service", "event_source", "vendor"),
		"trace_id":              first(raw, "trace_id"),
		"tenant_id":             first(raw, "tenant_id"),
		"demo_run_id":           first(raw, "demo_run_id"),
		"source_event_id":       first(raw, "source_event_id"),
		"scenario_id":           first(raw, "scenario_id"),
	}, nil
}

func Firewall(raw map[string]any) (map[string]any, error) {
	eventID := first(raw, "event_id", "id")
	return map[string]any{
		"schema_version":        1,
		"normalization_version": "firewall-v1",
		"normalized_event_id":   eventID,
		"raw_event_id":          eventID,
		"ts":                    first(raw, "ts", "timestamp", "occurred_at", "event_time"),
		"telemetry_type":        "firewall",
		"event_type":            "firewall_flow",
		"host_id":               first(raw, "host_id", "host", "device_name"),
		"agent_id":              first(raw, "agent_id"),
		"source_ip":             first(raw, "source_ip", "src_ip"),
		"destination_ip":        first(raw, "destination_ip", "dst_ip"),
		"destination_port":      raw["destination_port"],
		"protocol":              strings.ToLower(first(raw, "protocol", "proto")),
		"action":                strings.ToLower(first(raw, "action", "verdict")),
		"bytes_out":             raw["bytes_out"],
		"bytes_in":              raw["bytes_in"],
		"rule_name":             first(raw, "rule_name", "policy_name"),
		"user":                  first(raw, "user"),
		"source_service":        first(raw, "source_service", "event_source", "vendor"),
		"trace_id":              first(raw, "trace_id"),
		"tenant_id":             first(raw, "tenant_id"),
		"demo_run_id":           first(raw, "demo_run_id"),
		"source_event_id":       first(raw, "source_event_id"),
		"scenario_id":           first(raw, "scenario_id"),
	}, nil
}

// CefSyslog normalizes ArcSight CEF events forwarded by log-connector-syslog
// (telemetry_type=syslog_cef). The connector already promotes common
// extension fields to top-level aliases and preserves the full extension
// verbatim, so this normalizer mainly folds those into the standard envelope
// shape the other typed normalizers use. Advisory-only, feeds the existing
// shadow pipeline — no new active alert domain.
func CefSyslog(raw map[string]any) (map[string]any, error) {
	eventID := first(raw, "event_id", "id")
	return map[string]any{
		"schema_version":        1,
		"normalization_version": "cef-syslog-v1",
		"normalized_event_id":   eventID,
		"raw_event_id":          eventID,
		"ts":                    first(raw, "ts", "timestamp", "occurred_at", "event_time"),
		"telemetry_type":        "syslog_cef",
		"event_type":            first(raw, "event_type"),
		"device_vendor":         first(raw, "device_vendor"),
		"device_product":        first(raw, "device_product"),
		"device_version":        first(raw, "device_version"),
		"signature_id":          first(raw, "signature_id"),
		"name":                  first(raw, "name"),
		"severity":              first(raw, "severity"),
		"source_ip":             first(raw, "source_ip", "src_ip", "client_ip"),
		"destination_ip":        first(raw, "destination_ip", "dst_ip", "server_ip"),
		"source_port":           first(raw, "source_port"),
		"destination_port":      first(raw, "destination_port"),
		"protocol":              strings.ToLower(first(raw, "protocol")),
		"action":                strings.ToLower(first(raw, "action")),
		"user":                  first(raw, "user"),
		"message":               first(raw, "message"),
		"cef_extension":         raw["cef_extension"],
		"source_service":        first(raw, "event_source", "source_service", "vendor"),
		"trace_id":              first(raw, "trace_id"),
		"advisory_only":         true,
		"tenant_id":             first(raw, "tenant_id"),
		"demo_run_id":           first(raw, "demo_run_id"),
		"source_event_id":       first(raw, "source_event_id"),
		"scenario_id":           first(raw, "scenario_id"),
	}, nil
}

// ---------------------------------------------------------------------------
// Enterprise integration normalizers — advisory-only, inbound read
// No account actions, no credential harvesting, no bidirectional destructive sync.
// ---------------------------------------------------------------------------

func IdentityProvider(raw map[string]any) (map[string]any, error) {
	eventID := first(raw, "event_id", "id")
	failedCount := 0
	if fc, ok := raw["failed_attempt_count"].(float64); ok {
		failedCount = int(fc)
	}
	return map[string]any{
		"schema_version":        1,
		"normalization_version": "identity-provider-v1",
		"normalized_event_id":   eventID,
		"raw_event_id":          eventID,
		"ts":                    first(raw, "ts", "timestamp", "occurred_at", "event_time"),
		"telemetry_type":        "identity_provider",
		"event_type":            first(raw, "event_type", "action"),
		"provider":              strings.ToLower(first(raw, "provider", "idp")),
		"user_id":               first(raw, "user_id", "sub"),
		"user_email":            first(raw, "user_email", "email", "upn"),
		"source_ip":             first(raw, "source_ip", "ip_address", "client_ip"),
		"geo_country":           first(raw, "geo_country", "country"),
		"user_agent":            first(raw, "user_agent"),
		"mfa_used":              raw["mfa_used"],
		"is_failed":             raw["is_failed"],
		"failed_attempt_count":  failedCount,
		"raw_attributes":        raw["raw_attributes"],
		"source_service":        first(raw, "source_service", "vendor"),
		"trace_id":              first(raw, "trace_id"),
		"advisory_only":         true,
		"no_account_action":     true,
		"tenant_id":             first(raw, "tenant_id"),
		"demo_run_id":           first(raw, "demo_run_id"),
		"source_event_id":       first(raw, "source_event_id"),
		"scenario_id":           first(raw, "scenario_id"),
	}, nil
}

func SaasAudit(raw map[string]any) (map[string]any, error) {
	eventID := first(raw, "event_id", "id")
	return map[string]any{
		"schema_version":        1,
		"normalization_version": "saas-audit-v1",
		"normalized_event_id":   eventID,
		"raw_event_id":          eventID,
		"ts":                    first(raw, "ts", "timestamp", "occurred_at", "event_time"),
		"telemetry_type":        "saas_audit",
		"event_type":            first(raw, "action", "event_type", "operation"),
		"provider":              strings.ToLower(first(raw, "provider", "saas_platform")),
		"actor_id":              first(raw, "actor_id", "user_id", "sub"),
		"actor_email":           first(raw, "actor_email", "email", "user_email"),
		"actor_ip":              first(raw, "actor_ip", "source_ip", "ip_address"),
		"resource_type":         first(raw, "resource_type", "object_type"),
		"resource_id":           first(raw, "resource_id", "object_id"),
		"target_id":             first(raw, "target_id"),
		"target_email":          first(raw, "target_email"),
		"source_country":        first(raw, "source_country", "geo_country", "country"),
		"user_agent":            first(raw, "user_agent"),
		"additional_details":    raw["additional_details"],
		"source_service":        first(raw, "source_service", "vendor"),
		"trace_id":              first(raw, "trace_id"),
		"advisory_only":         true,
		"no_account_action":     true,
		"tenant_id":             first(raw, "tenant_id"),
		"demo_run_id":           first(raw, "demo_run_id"),
		"source_event_id":       first(raw, "source_event_id"),
		"scenario_id":           first(raw, "scenario_id"),
	}, nil
}

func TicketSync(raw map[string]any) (map[string]any, error) {
	eventID := first(raw, "event_id", "id")
	return map[string]any{
		"schema_version":        1,
		"normalization_version": "ticket-sync-v1",
		"normalized_event_id":   eventID,
		"raw_event_id":          eventID,
		"ts":                    first(raw, "ts", "timestamp", "occurred_at", "event_time"),
		"telemetry_type":        "ticket_sync",
		"event_type":            first(raw, "event_type", "action", "sync_type"),
		"provider":              strings.ToLower(first(raw, "provider", "ticketing_system")),
		"ticket_id":             first(raw, "ticket_id", "external_ticket_id"),
		"investigation_id":      first(raw, "investigation_id"),
		"sync_direction":        first(raw, "sync_direction", "direction"),
		"external_status":       first(raw, "external_status", "ticket_status"),
		"source_service":        first(raw, "source_service", "vendor"),
		"trace_id":              first(raw, "trace_id"),
		"advisory_only":         true,
		"no_auto_close":         true,
		"tenant_id":             first(raw, "tenant_id"),
		"demo_run_id":           first(raw, "demo_run_id"),
		"source_event_id":       first(raw, "source_event_id"),
		"scenario_id":           first(raw, "scenario_id"),
	}, nil
}

func NotificationEvent(raw map[string]any) (map[string]any, error) {
	eventID := first(raw, "event_id", "id")
	return map[string]any{
		"schema_version":        1,
		"normalization_version": "notification-event-v1",
		"normalized_event_id":   eventID,
		"raw_event_id":          eventID,
		"ts":                    first(raw, "ts", "timestamp", "occurred_at", "event_time"),
		"telemetry_type":        "notification",
		"event_type":            first(raw, "notification_type", "event_type"),
		"channel":               strings.ToLower(first(raw, "channel")),
		"severity":              strings.ToLower(first(raw, "severity")),
		"subject":               first(raw, "subject"),
		"source_reference":      raw["source_reference"],
		"requires_approval":     raw["requires_analyst_approval"],
		"source_service":        first(raw, "source_service", "vendor"),
		"trace_id":              first(raw, "trace_id"),
		"advisory_only":         true,
		"simulated":             true,
		"tenant_id":             first(raw, "tenant_id"),
		"demo_run_id":           first(raw, "demo_run_id"),
		"source_event_id":       first(raw, "source_event_id"),
		"scenario_id":           first(raw, "scenario_id"),
	}, nil
}
