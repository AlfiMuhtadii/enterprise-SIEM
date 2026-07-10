// Package deliver provides bounded-retry delivery semantics for
// CONN-DELIVERY-LOSS: connectors previously cleared their buffer / marked a
// source file processed BEFORE confirming a forward() call succeeded, so a
// transient gateway outage (network error, timeout, 429, 5xx) permanently
// discarded telemetry. WithRetry gives every connector a shared, tested
// "try, wait, try again" primitive instead of each reimplementing its own
// backoff loop.
package deliver

import "time"

// WithRetry calls fn up to maxAttempts times, sleeping with exponential
// backoff (doubling from base, capped at max) between attempts — no sleep
// before the first attempt. Returns nil on the first success, or the last
// error if every attempt fails. maxAttempts<=0 is treated as 1 (fn is
// always called at least once).
func WithRetry(maxAttempts int, base, max time.Duration, fn func() error) error {
	if maxAttempts <= 0 {
		maxAttempts = 1
	}
	var lastErr error
	var backoff time.Duration
	for attempt := 0; attempt < maxAttempts; attempt++ {
		if attempt > 0 {
			if backoff == 0 {
				backoff = base
			} else {
				backoff *= 2
				if backoff > max {
					backoff = max
				}
			}
			time.Sleep(backoff)
		}
		if err := fn(); err == nil {
			return nil
		} else {
			lastErr = err
		}
	}
	return lastErr
}
