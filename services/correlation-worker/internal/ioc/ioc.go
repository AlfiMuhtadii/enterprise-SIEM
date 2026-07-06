// Package ioc implements shadow/advisory threat-intelligence IOC lookup with
// a bounded, TTL, thread-safe cache (PERF-GO-HOT-HTTP) so repeated indicators
// within a batch don't re-issue a synchronous HTTP GET on every call.
//
// Extracted from correlation-worker/main.go as the first incremental package
// seam (CODE-STRUCT-DECOMPOSE) — pure code movement, no behavior change.
package ioc

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

var (
	LookupTotal atomic.Int64
	MatchTotal  atomic.Int64
	CacheHits   atomic.Int64
	httpClient  = &http.Client{Timeout: 3 * time.Second}
	// GlobalCache is the single shared IOC lookup cache for this process —
	// there is one correlation-worker instance and one IOC feed, so a single
	// package-level cache is correct. Call Configure once at startup to size
	// it from env vars; the default here matches historical fallbacks.
	GlobalCache = NewCache(60*time.Second, 10000)
)

// Configure replaces GlobalCache with new TTL/max bounds. Call once at
// startup (before the consume loop starts calling Lookup).
func Configure(ttl time.Duration, max int) {
	GlobalCache = NewCache(ttl, max)
}

// cacheEntry is a cached lookup result (result == nil means a definitive
// no-match). Errors/transient failures are never cached (they are retried).
type cacheEntry struct {
	result  map[string]any
	expires time.Time
}

// Cache is a bounded, TTL, thread-safe cache for IOC lookups.
type Cache struct {
	mu      sync.RWMutex
	entries map[string]cacheEntry
	ttl     time.Duration
	max     int
}

func NewCache(ttl time.Duration, max int) *Cache {
	return &Cache{entries: make(map[string]cacheEntry), ttl: ttl, max: max}
}

func (c *Cache) Get(key string) (map[string]any, bool) {
	c.mu.RLock()
	e, ok := c.entries[key]
	c.mu.RUnlock()
	if !ok || time.Now().After(e.expires) {
		return nil, false
	}
	return e.result, true
}

func (c *Cache) Set(key string, result map[string]any) {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.max > 0 && len(c.entries) >= c.max {
		// Bounded: drop expired entries first; if still at capacity, skip caching
		// (the live lookup already returned a value, we just don't grow the map).
		now := time.Now()
		for k, e := range c.entries {
			if now.After(e.expires) {
				delete(c.entries, k)
			}
		}
		if len(c.entries) >= c.max {
			return
		}
	}
	c.entries[key] = cacheEntry{result: result, expires: time.Now().Add(c.ttl)}
}

// Len reports the current entry count — exposed for tests only.
func (c *Cache) Len() int {
	c.mu.RLock()
	defer c.mu.RUnlock()
	return len(c.entries)
}

func Lookup(iocURL, iocType, value string) map[string]any {
	if iocURL == "" || value == "" {
		return nil
	}
	key := iocType + "|" + value
	if cached, ok := GlobalCache.Get(key); ok {
		CacheHits.Add(1)
		return cached
	}
	result, cacheable := fetchIOC(iocURL, iocType, value)
	if cacheable {
		GlobalCache.Set(key, result)
	}
	return result
}

// fetchIOC performs the synchronous HTTP lookup. The bool return is true only for
// a definitive HTTP-200 outcome (match or explicit no-match), which is safe to
// cache; transient errors/non-200 return false so they are not cached.
func fetchIOC(iocURL, iocType, value string) (map[string]any, bool) {
	LookupTotal.Add(1)
	queryURL := fmt.Sprintf("%s/v1/lookup?type=%s&value=%s",
		strings.TrimRight(iocURL, "/"),
		iocType,
		url.QueryEscape(value))
	req, err := http.NewRequest(http.MethodGet, queryURL, nil)
	if err != nil {
		return nil, false
	}
	resp, err := httpClient.Do(req)
	if err != nil {
		return nil, false
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil, false
	}
	var result map[string]any
	if err := json.NewDecoder(resp.Body).Decode(&result); err != nil {
		return nil, false
	}
	matched, _ := result["matched"].(bool)
	if !matched {
		return nil, true
	}
	MatchTotal.Add(1)
	return result, true
}

func Severity(ioc map[string]any) string {
	if s, ok := ioc["severity"].(string); ok && s != "" {
		return s
	}
	return "medium"
}

func Confidence(ioc map[string]any) float64 {
	if c, ok := ioc["confidence"].(float64); ok && c > 0 {
		return c
	}
	return 0.70
}
