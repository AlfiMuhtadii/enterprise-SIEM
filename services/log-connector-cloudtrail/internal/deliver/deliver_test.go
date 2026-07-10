package deliver

import (
	"errors"
	"testing"
	"time"
)

func TestWithRetrySucceedsOnFirstAttempt(t *testing.T) {
	calls := 0
	err := WithRetry(3, time.Millisecond, 10*time.Millisecond, func() error {
		calls++
		return nil
	})
	if err != nil {
		t.Fatalf("expected no error, got: %v", err)
	}
	if calls != 1 {
		t.Errorf("expected exactly 1 call on immediate success, got %d", calls)
	}
}

func TestWithRetrySucceedsAfterTransientFailures(t *testing.T) {
	calls := 0
	err := WithRetry(5, time.Millisecond, 10*time.Millisecond, func() error {
		calls++
		if calls < 3 {
			return errors.New("transient")
		}
		return nil
	})
	if err != nil {
		t.Fatalf("expected eventual success, got: %v", err)
	}
	if calls != 3 {
		t.Errorf("expected exactly 3 calls (2 failures + 1 success), got %d", calls)
	}
}

func TestWithRetryReturnsLastErrorAfterExhaustingAttempts(t *testing.T) {
	calls := 0
	wantErr := errors.New("permanent failure")
	err := WithRetry(3, time.Millisecond, 10*time.Millisecond, func() error {
		calls++
		return wantErr
	})
	if !errors.Is(err, wantErr) {
		t.Errorf("expected the last error to be returned, got: %v", err)
	}
	if calls != 3 {
		t.Errorf("expected exactly 3 attempts, got %d", calls)
	}
}

func TestWithRetryZeroOrNegativeMaxAttemptsStillCallsOnce(t *testing.T) {
	for _, max := range []int{0, -1} {
		calls := 0
		_ = WithRetry(max, time.Millisecond, 10*time.Millisecond, func() error {
			calls++
			return nil
		})
		if calls != 1 {
			t.Errorf("maxAttempts=%d: expected exactly 1 call, got %d", max, calls)
		}
	}
}

func TestWithRetryBackoffGrowsBetweenAttempts(t *testing.T) {
	var timestamps []time.Time
	_ = WithRetry(3, 20*time.Millisecond, time.Second, func() error {
		timestamps = append(timestamps, time.Now())
		return errors.New("always fails")
	})
	if len(timestamps) != 3 {
		t.Fatalf("expected 3 attempts, got %d", len(timestamps))
	}
	firstGap := timestamps[1].Sub(timestamps[0])
	secondGap := timestamps[2].Sub(timestamps[1])
	if firstGap < 15*time.Millisecond {
		t.Errorf("expected the first retry gap to be at least ~base (20ms), got %v", firstGap)
	}
	if secondGap <= firstGap {
		t.Errorf("expected the second retry gap (%v) to be larger than the first (%v) — backoff should grow", secondGap, firstGap)
	}
}

func TestWithRetryDoesNotSleepBeforeFirstAttempt(t *testing.T) {
	start := time.Now()
	_ = WithRetry(1, time.Second, time.Second, func() error { return nil })
	if elapsed := time.Since(start); elapsed > 100*time.Millisecond {
		t.Errorf("expected the first attempt to run immediately with no pre-sleep, took %v", elapsed)
	}
}
