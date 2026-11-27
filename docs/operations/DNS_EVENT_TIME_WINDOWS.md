# DNS Event-Time Windows

Status: implementation in progress. The new evaluator is tested but is
not wired into the Kafka consumer. The running worker still groups DNS
events inside one poll. Do not cite this change as live soak evidence.

The evaluator uses fixed UTC one-minute windows, grouped by tenant,
rehearsal classification, and client identifier. The ordered broker record
timestamp advances the watermark. A record at or after a window's end
plus 30 seconds cannot reopen it. Idle input does not advance the watermark
by wall time, so replay is not affected by poll timing or process speed.
This mode needs an ongoing input stream to finalize its last window.

The snapshot contains the next offset, open buckets, timestamps, and
bounded event and trace references. It omits DNS names and source addresses.
The evaluator rejects gaps, conflicting contributor IDs in a bucket,
and capacity overflow without advancing the prior snapshot. Late and
invalid contributing records are counted separately. Restore and replay
must remain scoped to the same ordered broker partition.

## Runtime Work Remaining

- Verify source ordering and partition ownership before enablement. The
  current normalizer does not guarantee that a tenant and client are
  assigned to one partition in a multi-partition cluster.
- Atomically persist the snapshot and pending advisory findings before
  input acknowledgment. After restart, publish pending findings only to
  the network advisory topic and preserve their stable IDs for replay.
- Keep the existing active scope and prevent the old poll-based DNS
  aggregators from emitting a second result when the new mode is enabled.
- Validate with real authorized DNS traffic across separate polls, including
  restart and late-arrival behavior. Assess the time-window policy and
  false positives before any six-hour promotion soak.
