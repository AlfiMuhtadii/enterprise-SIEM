package shadowrules

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"sort"
	"strings"
	"time"
)

const (
	DNSWindowDuration = time.Minute
	DNSWindowLateness = 30 * time.Second
	maxDNSClockLead   = 5 * time.Second
	maxDNSOpenEvents  = 4096
	dnsWindowVersion  = 1
)

// DNSWindowRecord is one record from an ordered partition. Query is nil for
// records that only advance the broker-arrival watermark.
type DNSWindowRecord struct {
	Offset    int64
	ArrivedAt time.Time
	Query     *DNSWindowQuery
}

type DNSWindowQuery struct {
	Tenant   string
	Origin   string
	Host     string
	Observed time.Time
	Event    DNSWindowEvent
}

type DNSWindowEvent struct {
	ID    string `json:"id"`
	Trace string `json:"trace,omitempty"`
	TXT   bool   `json:"txt,omitempty"`
	NX    bool   `json:"nx,omitempty"`
}

type DNSWindowKey struct {
	Start  time.Time `json:"start"`
	Tenant string    `json:"tenant"`
	Origin string    `json:"origin"`
	Host   string    `json:"host"`
}

type DNSWindow struct {
	Key    DNSWindowKey     `json:"key"`
	Events []DNSWindowEvent `json:"events"`
}

// DNSWindowState can be serialized as a checkpoint. A caller must persist
// the returned checkpoint and closed findings before acknowledging input.
type DNSWindowState struct {
	Version    int         `json:"version"`
	NextOffset int64       `json:"next_offset"`
	Watermark  time.Time   `json:"watermark"`
	Open       []DNSWindow `json:"open"`
	Late       uint64      `json:"late"`
	Invalid    uint64      `json:"invalid"`
}

func NewDNSWindowState(firstOffset int64) DNSWindowState {
	return DNSWindowState{Version: dnsWindowVersion, NextOffset: firstOffset}
}

// DNSWindowQueryFromNormalized extracts only fields needed by the DNS rules.
// Origin is computed by the caller's existing rehearsal classifier.
func DNSWindowQueryFromNormalized(event map[string]any, origin string) *DNSWindowQuery {
	if EpStr(event, "telemetry_type") != "dns" {
		return nil
	}
	tenant, validTenant := event["tenant_id"].(string)
	if !validTenant && event["tenant_id"] != nil {
		tenant = "\x00"
	}
	host, _ := event["host_id"].(string)
	trace, _ := event["trace_id"].(string)
	observed, _ := time.Parse(time.RFC3339Nano, EpStr(event, "ts"))
	return &DNSWindowQuery{
		Tenant: tenant, Origin: origin, Host: host, Observed: observed,
		Event: DNSWindowEvent{
			ID: networkEvidenceID(event), Trace: trace,
			TXT: strings.EqualFold(EpStr(event, "query_type"), "TXT"),
			NX:  strings.EqualFold(EpStr(event, "response_code"), "NXDOMAIN"),
		},
	}
}

func dnsIdentifier(value string, optional bool) bool {
	return (optional || value != "") && len(value) <= 255 &&
		strings.TrimSpace(value) == value && !strings.ContainsRune(value, 0)
}

func validDNSOrigin(origin string) bool {
	return origin == "unverified" || origin == "rehearsal"
}

func validDNSWindowEvent(event DNSWindowEvent) bool {
	return (event.TXT || event.NX) && dnsIdentifier(event.ID, false) && dnsIdentifier(event.Trace, true)
}

func dnsClosedBefore(watermark time.Time) time.Time {
	return watermark.Add(-DNSWindowLateness).Truncate(DNSWindowDuration)
}

func sortDNSWindows(windows []DNSWindow) {
	sort.Slice(windows, func(i, j int) bool {
		a, b := windows[i].Key, windows[j].Key
		switch {
		case !a.Start.Equal(b.Start):
			return a.Start.Before(b.Start)
		case a.Tenant != b.Tenant:
			return a.Tenant < b.Tenant
		case a.Origin != b.Origin:
			return a.Origin < b.Origin
		default:
			return a.Host < b.Host
		}
	})
}

func (s DNSWindowState) copyOpen() (map[DNSWindowKey]DNSWindow, int, error) {
	if s.Version != dnsWindowVersion || s.NextOffset < 0 || len(s.Open) > maxDNSOpenEvents ||
		(s.Watermark.IsZero() && len(s.Open) > 0) {
		return nil, 0, fmt.Errorf("invalid DNS window checkpoint")
	}
	open := make(map[DNSWindowKey]DNSWindow, len(s.Open))
	total := 0
	for _, window := range s.Open {
		key := window.Key
		key.Start = key.Start.UTC()
		if key.Start.IsZero() || key.Start != key.Start.Truncate(DNSWindowDuration) ||
			key.Start.Before(dnsClosedBefore(s.Watermark)) ||
			key.Start.After(s.Watermark.Add(maxDNSClockLead)) ||
			!dnsIdentifier(key.Tenant, true) || !dnsIdentifier(key.Host, false) || !validDNSOrigin(key.Origin) ||
			len(window.Events) == 0 || len(window.Events) > maxNetworkLineageRefs {
			return nil, 0, fmt.Errorf("invalid open DNS window checkpoint")
		}
		if _, exists := open[key]; exists || total+len(window.Events) > maxDNSOpenEvents {
			return nil, 0, fmt.Errorf("duplicate or excessive DNS window checkpoint")
		}
		ids := make(map[string]bool, len(window.Events))
		for _, event := range window.Events {
			if !validDNSWindowEvent(event) || ids[event.ID] {
				return nil, 0, fmt.Errorf("invalid DNS evidence in checkpoint")
			}
			ids[event.ID] = true
		}
		window.Key = key
		window.Events = append([]DNSWindowEvent(nil), window.Events...)
		open[key] = window
		total += len(window.Events)
	}
	return open, total, nil
}

// Advance consumes records in partition order without consulting wall time.
// Once a bucket closes it cannot be reopened by late data or redelivery.
// An invalid offset or capacity error leaves the original state unchanged.
func (s DNSWindowState) Advance(records []DNSWindowRecord) (DNSWindowState, []DNSWindow, error) {
	open, total, err := s.copyOpen()
	if err != nil {
		return s, nil, err
	}
	next := s
	var closed []DNSWindow
	for _, record := range records {
		if record.Offset < 0 {
			return s, nil, fmt.Errorf("invalid DNS input offset")
		}
		if record.Offset < next.NextOffset {
			continue
		}
		if record.Offset != next.NextOffset || record.Offset == 1<<63-1 || record.ArrivedAt.IsZero() {
			return s, nil, fmt.Errorf("DNS input offset gap or invalid arrival timestamp")
		}
		next.NextOffset++
		previousBoundary := dnsClosedBefore(next.Watermark)
		if record.ArrivedAt.After(next.Watermark) {
			next.Watermark = record.ArrivedAt.UTC()
		}
		boundary := dnsClosedBefore(next.Watermark)
		if boundary.After(previousBoundary) {
			for key, window := range open {
				if key.Start.Before(boundary) {
					closed = append(closed, window)
					total -= len(window.Events)
					delete(open, key)
				}
			}
		}
		query := record.Query
		if query == nil || (!query.Event.TXT && !query.Event.NX) {
			continue
		}
		if query.Observed.IsZero() || query.Observed.After(record.ArrivedAt.Add(maxDNSClockLead)) ||
			!dnsIdentifier(query.Tenant, true) || !dnsIdentifier(query.Host, false) || !validDNSOrigin(query.Origin) ||
			!validDNSWindowEvent(query.Event) {
			next.Invalid++
			continue
		}
		key := DNSWindowKey{query.Observed.UTC().Truncate(DNSWindowDuration), query.Tenant, query.Origin, query.Host}
		if key.Start.IsZero() {
			next.Invalid++
			continue
		}
		if key.Start.Before(boundary) {
			next.Late++
			continue
		}
		window := open[key]
		duplicate := false
		for _, seen := range window.Events {
			if seen.ID == query.Event.ID {
				if seen != query.Event {
					return s, nil, fmt.Errorf("conflicting DNS evidence identity")
				}
				duplicate = true
				break
			}
		}
		if duplicate {
			continue
		}
		if len(window.Events) == maxNetworkLineageRefs || total == maxDNSOpenEvents {
			return s, nil, fmt.Errorf("DNS window evidence capacity exceeded")
		}
		window.Key = key
		window.Events = append(window.Events, query.Event)
		open[key] = window
		total++
	}
	next.Open = nil
	for _, window := range open {
		next.Open = append(next.Open, window)
	}
	sortDNSWindows(next.Open)
	sortDNSWindows(closed)
	return next, closed, nil
}

func (w DNSWindow) Findings() []EndpointAlert {
	events := make([]map[string]any, 0, len(w.Events))
	for _, event := range w.Events {
		normalized := map[string]any{
			"host_id": w.Key.Host, "telemetry_type": "dns",
			"normalized_event_id": event.ID, "trace_id": event.Trace,
		}
		if event.TXT {
			normalized["query_type"] = "TXT"
		}
		if event.NX {
			normalized["response_code"] = "NXDOMAIN"
		}
		events = append(events, normalized)
	}
	findings := ruleNetworkDnsTxtQueryPattern(events)
	findings = append(findings, ruleNetworkRepeatedNxdomain(events)...)
	for i := range findings {
		finding := &findings[i]
		identity, _ := json.Marshal([]any{"dns-event-time-v1", w.Key, finding.AlertID})
		hash := sha256.Sum256(identity)
		finding.AlertID = hex.EncodeToString(hash[:])[:40]
		finding.TenantID, finding.EvidenceOrigin = w.Key.Tenant, w.Key.Origin
		finding.Evidence["aggregation_mode"] = "dns-event-time-v1"
		finding.Evidence["window_start"] = w.Key.Start
		finding.Evidence["window_end"] = w.Key.Start.Add(DNSWindowDuration)
	}
	return findings
}
