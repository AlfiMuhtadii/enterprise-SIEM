// Package gcpaudit parses GCP Cloud Audit Log entries exported to Cloud
// Storage via a log sink — GCP writes newline-delimited JSON (NDJSON, one
// LogEntry object per line), gzip-compressed only if the sink is configured
// that way (plain JSON by default), to a bucket path like
// `gs://<bucket>/cloudaudit.googleapis.com/<log-type>/<year>/<month>/<day>/...`.
//
// GCP's audit payload shape (google.cloud.audit.AuditLog nested inside
// protoPayload) is materially different from both CloudTrail's flat record
// and GuardDuty's Service.Action variants, so this needed its own parser —
// consistent with every connector in this framework: distinct source
// schemas, but all promoted onto the same canonical telemetry.raw field
// names in main.go.
//
// This is a pure, side-effect-free parser — no network/filesystem concerns
// — matching the internal/cloudtrail and internal/guardduty package pattern.
package gcpaudit

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
	// skipped WITHOUT being unmarshaled. 0 = unlimited.
	MaxRecordBytes int64
}

// Resource is the GCP monitored-resource block identifying what emitted
// the log entry (project, bucket, instance, ...).
type Resource struct {
	Type   string            `json:"type"`
	Labels map[string]string `json:"labels"`
}

// LogEntry is one parsed Cloud Audit Log entry. ProtoPayload is kept as a
// raw map (not a deeply typed google.cloud.audit.AuditLog struct) since
// its exact shape varies by GCP service — Raw preserves the entire
// original entry so no detail is lost regardless of source service.
type LogEntry struct {
	InsertID     string         `json:"insertId"`
	Timestamp    string         `json:"timestamp"`
	Severity     string         `json:"severity"`
	LogName      string         `json:"logName"`
	Resource     Resource       `json:"resource"`
	ProtoPayload map[string]any `json:"protoPayload"`
	Raw          map[string]any `json:"-"`
}

// Parse decodes a Cloud Audit Log export file: NDJSON (one LogEntry object
// per line), optionally gzip-compressed (detected via the gzip magic bytes
// 0x1f 0x8b — GCP sinks write plain JSON by default, gzip only if the sink
// destination is configured that way). A line that fails to decode is
// skipped rather than aborting the whole file. Equivalent to ParseBounded
// with no limits.
func Parse(data []byte) ([]LogEntry, error) {
	entries, _, err := ParseBounded(data, Limits{})
	return entries, err
}

// ParseBounded is Parse with CONN-UNBOUNDED-FILE size ceilings: gzip
// expansion is capped at limits.MaxExpandedBytes (ErrExpandedTooLarge if
// exceeded), and any NDJSON line whose byte length exceeds
// limits.MaxRecordBytes is skipped and counted in the returned
// oversizedRecords BEFORE any unmarshal is attempted on it.
func ParseBounded(data []byte, limits Limits) (entries []LogEntry, oversizedRecords int, err error) {
	if isGzip(data) {
		decompressed, gzErr := gunzip(data, limits.MaxExpandedBytes)
		if gzErr != nil {
			return nil, 0, gzErr
		}
		data = decompressed
	}

	lines := bytes.Split(data, []byte("\n"))
	entries = make([]LogEntry, 0, len(lines))
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
		var e LogEntry
		if err := json.Unmarshal(line, &e); err != nil {
			continue
		}
		e.Raw = raw
		entries = append(entries, e)
	}

	return entries, oversizedRecords, nil
}

// MethodName returns protoPayload.methodName (e.g. "storage.objects.get"),
// GCP's equivalent of an API action/event name.
func (e LogEntry) MethodName() string {
	s, _ := e.ProtoPayload["methodName"].(string)
	return s
}

// ServiceName returns protoPayload.serviceName (e.g. "storage.googleapis.com").
func (e LogEntry) ServiceName() string {
	s, _ := e.ProtoPayload["serviceName"].(string)
	return s
}

// PrincipalEmail returns protoPayload.authenticationInfo.principalEmail,
// the identity that performed the audited action.
func (e LogEntry) PrincipalEmail() string {
	return digString(e.ProtoPayload, []string{"authenticationInfo", "principalEmail"})
}

// CallerIP returns protoPayload.requestMetadata.callerIp.
func (e LogEntry) CallerIP() string {
	return digString(e.ProtoPayload, []string{"requestMetadata", "callerIp"})
}

// ProjectID returns resource.labels.project_id, GCP's equivalent of an
// AWS account ID.
func (e LogEntry) ProjectID() string {
	if e.Resource.Labels == nil {
		return ""
	}
	return e.Resource.Labels["project_id"]
}

// HasErrorStatus reports whether protoPayload.status carries a non-empty
// error code or message — GCP audit log convention: an empty/absent
// status object means the call succeeded, a populated one means it failed.
func (e LogEntry) HasErrorStatus() bool {
	status, ok := e.ProtoPayload["status"].(map[string]any)
	if !ok {
		return false
	}
	if code, ok := status["code"]; ok {
		if n, ok := code.(float64); ok && n != 0 {
			return true
		}
	}
	if msg, ok := status["message"].(string); ok && msg != "" {
		return true
	}
	return false
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
