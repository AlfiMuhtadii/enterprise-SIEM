package main

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
	"time"
)

func TestIOCCacheGetSetAndExpiry(t *testing.T) {
	c := newIOCCache(50*time.Millisecond, 100)
	if _, ok := c.get("k"); ok {
		t.Fatal("expected miss on empty cache")
	}
	c.set("k", map[string]any{"matched": true})
	if v, ok := c.get("k"); !ok || v["matched"] != true {
		t.Fatal("expected cached hit")
	}
	time.Sleep(70 * time.Millisecond)
	if _, ok := c.get("k"); ok {
		t.Fatal("entry must expire after TTL")
	}
}

func TestIOCCacheNegativeIsCached(t *testing.T) {
	c := newIOCCache(time.Minute, 100)
	c.set("k", nil) // definitive no-match
	v, ok := c.get("k")
	if !ok || v != nil {
		t.Fatalf("expected cached negative (nil), got ok=%v v=%v", ok, v)
	}
}

func TestIOCCacheIsBounded(t *testing.T) {
	c := newIOCCache(time.Minute, 2)
	c.set("a", map[string]any{"x": 1})
	c.set("b", map[string]any{"x": 1})
	c.set("c", map[string]any{"x": 1}) // at cap → not added
	c.mu.RLock()
	n := len(c.entries)
	c.mu.RUnlock()
	if n > 2 {
		t.Fatalf("cache exceeded max size: %d", n)
	}
}

func TestLookupIOCCachesRepeatedIndicator(t *testing.T) {
	var calls int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&calls, 1)
		_ = json.NewEncoder(w).Encode(map[string]any{"matched": true, "severity": "high"})
	}))
	defer srv.Close()

	orig := globalIOCCache
	globalIOCCache = newIOCCache(time.Minute, 100)
	defer func() { globalIOCCache = orig }()

	hitsBefore := iocCacheHits.Load()
	for i := 0; i < 5; i++ {
		r := lookupIOC(srv.URL, "ip", "203.0.113.9")
		if r == nil || r["matched"] != true {
			t.Fatalf("iteration %d: expected a match", i)
		}
	}
	if got := atomic.LoadInt32(&calls); got != 1 {
		t.Errorf("expected exactly 1 upstream HTTP call (rest served from cache), got %d", got)
	}
	if delta := iocCacheHits.Load() - hitsBefore; delta != 4 {
		t.Errorf("expected 4 cache hits, got %d", delta)
	}
}

func TestLookupIOCDoesNotCacheTransientError(t *testing.T) {
	var calls int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&calls, 1)
		w.WriteHeader(http.StatusInternalServerError) // transient failure → must not cache
	}))
	defer srv.Close()

	orig := globalIOCCache
	globalIOCCache = newIOCCache(time.Minute, 100)
	defer func() { globalIOCCache = orig }()

	for i := 0; i < 3; i++ {
		if r := lookupIOC(srv.URL, "ip", "203.0.113.10"); r != nil {
			t.Fatal("expected nil on error")
		}
	}
	if got := atomic.LoadInt32(&calls); got != 3 {
		t.Errorf("transient errors must not be cached; expected 3 calls, got %d", got)
	}
}
