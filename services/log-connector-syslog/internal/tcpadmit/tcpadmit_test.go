package tcpadmit

import (
	"sync"
	"testing"
	"time"
)

func TestLimiterDisabledWhenMaxIsZeroOrNegative(t *testing.T) {
	for _, max := range []int{0, -1} {
		l := NewLimiter(max)
		for i := 0; i < 1000; i++ {
			if !l.TryAcquire() {
				t.Fatalf("max=%d: expected disabled limiter to never reject, rejected at attempt %d", max, i)
			}
		}
		if got := l.Active(); got != 0 {
			t.Errorf("max=%d: expected Active()=0 for a disabled limiter, got %d", max, got)
		}
	}
}

func TestLimiterMaxReflectsConfiguredCapacity(t *testing.T) {
	if got := NewLimiter(5).Max(); got != 5 {
		t.Errorf("expected Max()=5, got %d", got)
	}
	if got := NewLimiter(0).Max(); got != 0 {
		t.Errorf("expected Max()=0 for a disabled limiter, got %d", got)
	}
}

func TestLimiterRejectsBeyondCapacity(t *testing.T) {
	l := NewLimiter(3)
	for i := 0; i < 3; i++ {
		if !l.TryAcquire() {
			t.Fatalf("expected acquire %d to succeed within capacity", i)
		}
	}
	if l.TryAcquire() {
		t.Error("expected the 4th acquire to be rejected at capacity 3")
	}
	if got := l.Active(); got != 3 {
		t.Errorf("expected Active()=3, got %d", got)
	}
}

func TestLimiterReleaseFreesASlot(t *testing.T) {
	l := NewLimiter(1)
	if !l.TryAcquire() {
		t.Fatal("expected first acquire to succeed")
	}
	if l.TryAcquire() {
		t.Fatal("expected second acquire to be rejected before release")
	}
	l.Release()
	if !l.TryAcquire() {
		t.Error("expected acquire to succeed again after release")
	}
}

func TestLimiterReleaseOnDisabledLimiterIsNoOp(t *testing.T) {
	l := NewLimiter(0)
	// Must not panic or block.
	l.Release()
	l.Release()
}

func TestLimiterConcurrentAccessStaysWithinCapacity(t *testing.T) {
	const capacity = 10
	l := NewLimiter(capacity)
	var wg sync.WaitGroup
	var accepted, rejected int
	var mu sync.Mutex
	for i := 0; i < 100; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			if l.TryAcquire() {
				mu.Lock()
				accepted++
				mu.Unlock()
			} else {
				mu.Lock()
				rejected++
				mu.Unlock()
			}
		}()
	}
	wg.Wait()
	if accepted != capacity {
		t.Errorf("expected exactly %d accepted (capacity), got %d (rejected=%d)", capacity, accepted, rejected)
	}
	if accepted+rejected != 100 {
		t.Errorf("expected all 100 attempts accounted for, got accepted=%d rejected=%d", accepted, rejected)
	}
}

func TestNextBackoffStartsAtBase(t *testing.T) {
	got := NextBackoff(0, 5*time.Millisecond, time.Second)
	if got != 5*time.Millisecond {
		t.Errorf("expected first backoff to equal base (5ms), got %v", got)
	}
}

func TestNextBackoffDoubles(t *testing.T) {
	base := 5 * time.Millisecond
	max := time.Second
	b1 := NextBackoff(0, base, max)
	b2 := NextBackoff(b1, base, max)
	b3 := NextBackoff(b2, base, max)
	if b1 != 5*time.Millisecond || b2 != 10*time.Millisecond || b3 != 20*time.Millisecond {
		t.Errorf("expected doubling sequence 5ms,10ms,20ms, got %v,%v,%v", b1, b2, b3)
	}
}

func TestNextBackoffCapsAtMax(t *testing.T) {
	base := 100 * time.Millisecond
	max := 250 * time.Millisecond
	b := time.Duration(0)
	for i := 0; i < 10; i++ {
		b = NextBackoff(b, base, max)
	}
	if b != max {
		t.Errorf("expected backoff to cap at %v after repeated doubling, got %v", max, b)
	}
}
