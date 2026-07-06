package ioc

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
	"time"
)

func TestCacheGetSetAndExpiry(t *testing.T) {
	c := NewCache(50*time.Millisecond, 100)
	if _, ok := c.Get("k"); ok {
		t.Fatal("expected miss on empty cache")
	}
	c.Set("k", map[string]any{"matched": true})
	if v, ok := c.Get("k"); !ok || v["matched"] != true {
		t.Fatal("expected cached hit")
	}
	time.Sleep(70 * time.Millisecond)
	if _, ok := c.Get("k"); ok {
		t.Fatal("entry must expire after TTL")
	}
}

func TestCacheNegativeIsCached(t *testing.T) {
	c := NewCache(time.Minute, 100)
	c.Set("k", nil) // definitive no-match
	v, ok := c.Get("k")
	if !ok || v != nil {
		t.Fatalf("expected cached negative (nil), got ok=%v v=%v", ok, v)
	}
}

func TestCacheIsBounded(t *testing.T) {
	c := NewCache(time.Minute, 2)
	c.Set("a", map[string]any{"x": 1})
	c.Set("b", map[string]any{"x": 1})
	c.Set("c", map[string]any{"x": 1}) // at cap → not added
	if n := c.Len(); n > 2 {
		t.Fatalf("cache exceeded max size: %d", n)
	}
}

func TestLookupCachesRepeatedIndicator(t *testing.T) {
	var calls int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&calls, 1)
		_ = json.NewEncoder(w).Encode(map[string]any{"matched": true, "severity": "high"})
	}))
	defer srv.Close()

	orig := GlobalCache
	GlobalCache = NewCache(time.Minute, 100)
	defer func() { GlobalCache = orig }()

	hitsBefore := CacheHits.Load()
	for i := 0; i < 5; i++ {
		r := Lookup(srv.URL, "ip", "203.0.113.9")
		if r == nil || r["matched"] != true {
			t.Fatalf("iteration %d: expected a match", i)
		}
	}
	if got := atomic.LoadInt32(&calls); got != 1 {
		t.Errorf("expected exactly 1 upstream HTTP call (rest served from cache), got %d", got)
	}
	if delta := CacheHits.Load() - hitsBefore; delta != 4 {
		t.Errorf("expected 4 cache hits, got %d", delta)
	}
}

func TestLookupDoesNotCacheTransientError(t *testing.T) {
	var calls int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&calls, 1)
		w.WriteHeader(http.StatusInternalServerError) // transient failure → must not cache
	}))
	defer srv.Close()

	orig := GlobalCache
	GlobalCache = NewCache(time.Minute, 100)
	defer func() { GlobalCache = orig }()

	for i := 0; i < 3; i++ {
		if r := Lookup(srv.URL, "ip", "203.0.113.10"); r != nil {
			t.Fatal("expected nil on error")
		}
	}
	if got := atomic.LoadInt32(&calls); got != 3 {
		t.Errorf("transient errors must not be cached; expected 3 calls, got %d", got)
	}
}

func TestSeverityDefault(t *testing.T) {
	if got := Severity(map[string]any{}); got != "medium" {
		t.Errorf("expected default severity 'medium', got %q", got)
	}
	if got := Severity(map[string]any{"severity": "critical"}); got != "critical" {
		t.Errorf("expected 'critical', got %q", got)
	}
}

func TestConfidenceDefault(t *testing.T) {
	if got := Confidence(map[string]any{}); got != 0.70 {
		t.Errorf("expected default confidence 0.70, got %v", got)
	}
	if got := Confidence(map[string]any{"confidence": 0.95}); got != 0.95 {
		t.Errorf("expected 0.95, got %v", got)
	}
}
