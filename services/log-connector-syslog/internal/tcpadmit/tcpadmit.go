// Package tcpadmit provides bounded connection admission control for a raw
// TCP listener (SYSLOG-TCP-ADMISSION): a concurrent-connection ceiling and
// the accept-error backoff algorithm, both kept independent of net.Conn/
// net.Listener so they can be unit tested without a real socket.
package tcpadmit

import "time"

// Limiter is a bounded concurrent-connection admission gate. A Limiter
// constructed with max<=0 never rejects — matching the "0 disables the cap"
// convention already used elsewhere in this codebase (e.g. ingestion-gateway's
// RATE-LIMIT-DOS maxTenantBuckets).
type Limiter struct {
	sem chan struct{}
}

// NewLimiter builds a Limiter allowing at most max concurrently-held slots.
func NewLimiter(max int) *Limiter {
	if max <= 0 {
		return &Limiter{}
	}
	return &Limiter{sem: make(chan struct{}, max)}
}

// TryAcquire attempts to reserve one connection slot. Never blocks — returns
// false immediately if the limiter is already at capacity.
func (l *Limiter) TryAcquire() bool {
	if l.sem == nil {
		return true
	}
	select {
	case l.sem <- struct{}{}:
		return true
	default:
		return false
	}
}

// Release frees one connection slot. Safe to call on a disabled limiter (no-op).
func (l *Limiter) Release() {
	if l.sem == nil {
		return
	}
	<-l.sem
}

// Max returns the configured capacity, or 0 if the limiter is disabled.
func (l *Limiter) Max() int {
	return cap(l.sem)
}

// Active returns the number of currently held slots (for /metrics).
func (l *Limiter) Active() int {
	if l.sem == nil {
		return 0
	}
	return len(l.sem)
}

// NextBackoff computes the next exponential backoff delay for a temporary
// Accept() error: doubling from base and capping at max. prev<=0 (the first
// temporary error in a streak) returns base. This is the same algorithm
// net/http's own Server.Serve uses for this exact scenario.
func NextBackoff(prev, base, max time.Duration) time.Duration {
	if prev <= 0 {
		return base
	}
	next := prev * 2
	if next > max {
		return max
	}
	return next
}
