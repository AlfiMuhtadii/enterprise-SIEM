package main

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"strings"
	"testing"

	"detector-xdr-correlation-worker/internal/shadowrules"
)

func TestPandaproxyPublishRequiresValidPartitionReceipts(t *testing.T) {
	tests := []struct {
		name   string
		status int
		body   string
		valid  bool
	}{
		{"single partition for several records", 200, `{"offsets":[{"partition":0,"offset":0}]}`, true},
		{"two partitions", 200, `{"offsets":[{"partition":0,"offset":2},{"partition":1,"offset":0,"error_code":0}]}`, true},
		{"partition rejection with HTTP success", 200, `{"offsets":[{"partition":0,"offset":4},{"partition":1,"offset":-1,"error_code":3}]}`, false},
		{"missing offsets", 200, `{}`, false},
		{"empty offsets", 200, `{"offsets":[]}`, false},
		{"malformed JSON", 200, `{"offsets":[`, false},
		{"missing offset", 200, `{"offsets":[{"partition":0}]}`, false},
		{"negative offset", 200, `{"offsets":[{"partition":0,"offset":-1}]}`, false},
		{"null partition", 200, `{"offsets":[{"partition":null,"offset":0}]}`, false},
		{"duplicated partition", 200, `{"offsets":[{"partition":0,"offset":0},{"partition":0,"offset":1}]}`, false},
		{"oversized response", 200, strings.Repeat(" ", maxProduceReceiptBytes+1), false},
		{"accepted is not a receipt", 202, `{"offsets":[{"partition":0,"offset":2}]}`, false},
		{"broker unavailable", 503, "private-broker-response", false},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			proxy := httptest.NewServer(http.HandlerFunc(func(rw http.ResponseWriter, r *http.Request) {
				rw.WriteHeader(test.status)
				_, _ = rw.Write([]byte(test.body))
			}))
			defer proxy.Close()
			w := Worker{redpandaREST: proxy.URL}
			err := w.publish("advisory.test", []map[string]any{{"record": 1}, {"record": 2}})
			if (err == nil) != test.valid {
				t.Fatalf("publish result = %v, expected valid = %v", err, test.valid)
			}
			if err != nil && strings.Contains(err.Error(), "private-broker-response") {
				t.Fatal("publish error leaked the broker body")
			}
		})
	}
}

func TestNetworkShadowCountersSeparateConfirmedFindingsAndFailures(t *testing.T) {
	alerts := []shadowrules.EndpointAlert{{AlertID: "finding-one"}, {AlertID: "finding-two"}}
	for _, tc := range []struct {
		name   string
		body   string
		ack    bool
		count  int64
		errors int64
	}{
		{"record accepted", `{"offsets":[{"partition":0,"offset":0}]}`, true, 2, 0},
		{"partition rejected", `{"offsets":[{"partition":0,"offset":-1,"error_code":3}]}`, false, 0, 1},
	} {
		t.Run(tc.name, func(t *testing.T) {
			proxy := httptest.NewServer(http.HandlerFunc(func(rw http.ResponseWriter, r *http.Request) {
				if r.URL.Path != "/topics/xdr.alerts.shadow.network" {
					t.Errorf("published to unexpected topic: %s", r.URL.Path)
				}
				_, _ = rw.Write([]byte(tc.body))
			}))
			defer proxy.Close()
			w := Worker{redpandaREST: proxy.URL, outputTopic: "xdr.alerts"}
			if accepted := w.publishShadowFindings("xdr.alerts.shadow.network", "network-shadow", alerts); accepted != tc.ack {
				t.Errorf("accepted = %v, want %v", accepted, tc.ack)
			}
			if got := w.networkShadowPublished.Load(); got != tc.count {
				t.Errorf("confirmed network findings = %d, want %d", got, tc.count)
			}
			if got := w.shadowPublishErrors.Load(); got != tc.errors {
				t.Errorf("shadow publish errors = %d, want %d", got, tc.errors)
			}
			if got := w.shadowAlertsPublished.Load(); got != 0 {
				t.Errorf("endpoint count changed: %d", got)
			}
			recorder := httptest.NewRecorder()
			w.metrics(recorder, httptest.NewRequest(http.MethodGet, "/metrics", nil))
			var report map[string]any
			if err := json.Unmarshal(recorder.Body.Bytes(), &report); err != nil {
				t.Fatal(err)
			}
			if report["network_shadow_alerts_published"] != float64(tc.count) ||
				report["shadow_publish_errors"] != float64(tc.errors) ||
				report["shadow_alerts_published"] != float64(0) {
				t.Fatal("metrics endpoint did not report independent broker outcomes")
			}
		})
	}
}

func TestLivePandaproxyProduceReceipt(t *testing.T) {
	endpoint := os.Getenv("XDR_PANDAPROXY_RECEIPT_TEST_URL")
	if endpoint == "" {
		t.Skip("requires a disposable Redpanda broker and dedicated receipt test topics")
	}
	proxy, err := url.Parse(endpoint)
	if err != nil || proxy.Scheme != "http" || proxy.Hostname() != "127.0.0.1" ||
		proxy.Port() == "" || proxy.User != nil || proxy.Path != "" || proxy.RawQuery != "" || proxy.Fragment != "" {
		t.Fatal("receipt test requires a bare loopback broker HTTP URL")
	}
	w := Worker{redpandaREST: endpoint}
	if err := w.publish("detector.receipt.probe", []map[string]any{{"probe": 1}, {"probe": 2}}); err != nil {
		t.Fatalf("one partition receipt should acknowledge both probe records: %v", err)
	}
	if err := w.publish("detector.receipt.size-limited", []map[string]any{{"probe": strings.Repeat("x", 4096)}}); err == nil || err.Error() != "publish_partition_rejected" {
		t.Fatalf("expected a partition rejection in the broker receipt, got %v", err)
	}
}
