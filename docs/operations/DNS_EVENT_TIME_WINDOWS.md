# DNS Event-Time Windows

This optional single-worker pilot replaces only the TXT and NXDOMAIN
aggregators. Without the pilot overlay the worker keeps its existing
poll-based behavior. Nothing in this mode changes the active rule scope
or treats lab traffic as evidence of a representative soak.

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

## Enable on a Pilot Stack

Use `docker-compose.dns-event-time.yml` with the pilot stack's existing
Compose files. It uses native Kafka, enables the event loop, fixes active
scope to `identity-cloud`, and mounts a local Linux volume. It is not part
of the default startup flow. Build the candidate correlation image before
launching the overlay. On a new volume, set
`XDR_DNS_EVENT_TIME_START_OFFSET` to a reviewed retained offset. It is ignored
after initialization. Do not reset the volume to recover from an error.

Enable only one pilot worker on `telemetry.normalized` with one partition.
Do not run a second worker using a different volume for the same stream.
Multi-partition support would need a verified tenant/client partition key;
it is not implemented. The input must be append-only and the last processed
record must still be retained at restart. Do not recreate or compact the
topic while the pilot is enabled.

The pilot has an independent checkpoint cursor, not a Kafka consumer-group
commit. It stores open windows and any pending advisories in one atomic,
synced write before advancing the cursor. On restart it verifies broker and
topic identity and rereads the last consumed record as an anchor. It then
retries any pending findings only to `xdr.alerts.shadow.network`, with stable
IDs. A lost response can cause redelivery; this is at-least-once publication,
not exactly-once. The primary correlation consumer and active alerts keep
their independent offsets.

Monitor `dns_event_time_ready`, `dns_event_time_failed`,
`dns_event_time_next_offset`, `dns_event_time_late`, and
`dns_event_time_invalid` in `/metrics`. An unavailable lane does not switch
back to the old DNS aggregators. Investigate any failure or gaps before
using results for a soak. Review real traffic and false positives separately
before any promotion decision.
