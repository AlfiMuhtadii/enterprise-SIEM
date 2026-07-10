// Package guardduty parses AWS GuardDuty finding export files — GuardDuty's
// native "export findings" feature writes newline-delimited JSON (NDJSON,
// one finding object per line), gzip-compressed by default, to a configured
// S3 bucket (`.../AWSLogs/<account>/GuardDuty/<region>/...`).
//
// This is a materially different shape from CloudTrail's export (a single
// `{"Records": [...]}` JSON array per file) — GuardDuty is one JSON object
// per line — so it needed its own parser rather than reusing CloudTrail's.
//
// This is a pure, side-effect-free parser — no network/filesystem concerns
// — matching the internal/cloudtrail package pattern.
package guardduty

import (
	"bytes"
	"compress/gzip"
	"encoding/json"
	"errors"
	"io"
)

// ErrExpandedTooLarge is returned by ParseBounded when a gzip-compressed
// file's decompressed content exceeds Limits.MaxExpandedBytes — the
// defense against a compression-bomb export file (CONN-UNBOUNDED-FILE).
var ErrExpandedTooLarge = errors.New("decompressed content exceeds configured size limit")

// Limits bounds ParseBounded's resource usage. Zero value means "no limit"
// on that dimension, matching Parse's original unbounded behavior.
type Limits struct {
	// MaxExpandedBytes caps how much decompressed data a gzip-compressed
	// file is allowed to produce. 0 = unlimited.
	MaxExpandedBytes int64
	// MaxRecordBytes caps a single NDJSON line's byte length. A line over
	// this limit is counted in the returned oversizedRecords count and
	// skipped WITHOUT being unmarshaled — the caller is expected to
	// surface that count (metric/log/quarantine record), not treat it as
	// a silent drop. 0 = unlimited.
	MaxRecordBytes int64
}

// Finding is one parsed GuardDuty finding. Resource/Service are kept as raw
// maps (not deeply typed structs) since their shape varies significantly by
// finding type (GuardDuty has dozens of distinct finding types, each with a
// different Service.Action variant) — Raw preserves the entire original
// finding so no detail is lost regardless of type.
type Finding struct {
	SchemaVersion string         `json:"SchemaVersion"`
	AccountID     string         `json:"AccountId"`
	Region        string         `json:"Region"`
	ID            string         `json:"Id"`
	Type          string         `json:"Type"`
	Severity      float64        `json:"Severity"`
	CreatedAt     string         `json:"CreatedAt"`
	UpdatedAt     string         `json:"UpdatedAt"`
	Title         string         `json:"Title"`
	Description   string         `json:"Description"`
	Resource      map[string]any `json:"Resource"`
	Service       map[string]any `json:"Service"`
	Raw           map[string]any `json:"-"`
}

// Parse decodes a GuardDuty findings export file: NDJSON (one finding
// object per line), optionally gzip-compressed (detected via the gzip
// magic bytes 0x1f 0x8b, the default GuardDuty S3 export format). A line
// that fails to decode is skipped rather than aborting the whole file — one
// poison line must not block every other finding in the same export.
// Equivalent to ParseBounded with no limits.
func Parse(data []byte) ([]Finding, error) {
	findings, _, err := ParseBounded(data, Limits{})
	return findings, err
}

// ParseBounded is Parse with CONN-UNBOUNDED-FILE size ceilings: gzip
// expansion is capped at limits.MaxExpandedBytes (ErrExpandedTooLarge if
// exceeded), and any NDJSON line whose byte length exceeds
// limits.MaxRecordBytes is skipped and counted in the returned
// oversizedRecords BEFORE any unmarshal is attempted on it.
func ParseBounded(data []byte, limits Limits) (findings []Finding, oversizedRecords int, err error) {
	if isGzip(data) {
		decompressed, gzErr := gunzip(data, limits.MaxExpandedBytes)
		if gzErr != nil {
			return nil, 0, gzErr
		}
		data = decompressed
	}

	lines := bytes.Split(data, []byte("\n"))
	findings = make([]Finding, 0, len(lines))
	for _, line := range lines {
		line = bytes.TrimSpace(line)
		if len(line) == 0 {
			continue
		}
		if limits.MaxRecordBytes > 0 && int64(len(line)) > limits.MaxRecordBytes {
			oversizedRecords++
			continue
		}
		var raw map[string]any
		if err := json.Unmarshal(line, &raw); err != nil {
			continue
		}
		var f Finding
		if err := json.Unmarshal(line, &f); err != nil {
			continue
		}
		f.Raw = raw
		findings = append(findings, f)
	}

	return findings, oversizedRecords, nil
}

// RemoteIPAddress best-effort extracts a remote IP from whichever
// Service.Action.* variant this finding's type populated. GuardDuty
// findings carry different action shapes depending on Type
// (NetworkConnectionAction, AwsApiCallAction, DnsRequestAction,
// KubernetesApiCallAction, PortProbeAction, ...) — this checks the common
// ones and returns "" if none match, rather than guessing.
func (f Finding) RemoteIPAddress() string {
	action, _ := f.Service["Action"].(map[string]any)
	if action == nil {
		return ""
	}

	paths := [][]string{
		{"NetworkConnectionAction", "RemoteIpDetails", "IpAddressV4"},
		{"AwsApiCallAction", "RemoteIpDetails", "IpAddressV4"},
		{"DnsRequestAction", "RemoteIpDetails", "IpAddressV4"},
		{"KubernetesApiCallAction", "RemoteIpDetails", "IpAddressV4"},
	}
	for _, p := range paths {
		if ip := digString(action, p); ip != "" {
			return ip
		}
	}

	if probeAction, ok := action["PortProbeAction"].(map[string]any); ok {
		if details, ok := probeAction["PortProbeDetails"].([]any); ok && len(details) > 0 {
			if first, ok := details[0].(map[string]any); ok {
				if ip := digString(first, []string{"RemoteIpDetails", "IpAddressV4"}); ip != "" {
					return ip
				}
			}
		}
	}

	return ""
}

func digString(m map[string]any, path []string) string {
	var cur any = m
	for _, key := range path {
		mm, ok := cur.(map[string]any)
		if !ok {
			return ""
		}
		cur = mm[key]
	}
	s, _ := cur.(string)
	return s
}

func isGzip(data []byte) bool {
	return len(data) >= 2 && data[0] == 0x1f && data[1] == 0x8b
}

func gunzip(data []byte, maxExpandedBytes int64) ([]byte, error) {
	r, err := gzip.NewReader(bytes.NewReader(data))
	if err != nil {
		return nil, err
	}
	defer func() { _ = r.Close() }()

	if maxExpandedBytes <= 0 {
		return io.ReadAll(r)
	}

	out, err := io.ReadAll(io.LimitReader(r, maxExpandedBytes+1))
	if err != nil {
		return nil, err
	}
	if int64(len(out)) > maxExpandedBytes {
		return nil, ErrExpandedTooLarge
	}
	return out, nil
}
