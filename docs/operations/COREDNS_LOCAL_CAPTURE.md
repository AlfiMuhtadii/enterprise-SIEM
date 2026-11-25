# Local CoreDNS Capture

Collect an actual DNS request and reply without a cloud account. The
example serves a fixed record under `detector.test` locally and does not
forward queries upstream or change the host's DNS settings. It demonstrates
ingestion of benign traffic, not a threat scenario.

Use an approved local Docker daemon and keep raw exports outside the repo.
From the repo root in PowerShell:

```powershell
docker run --rm -d --name detector-dns-demo --mount "type=bind,source=$($PWD.Path)/infra/demo/coredns/Corefile,target=/Corefile,readonly" coredns/coredns:1.14.2 -conf /Corefile
$start = [datetime]::UtcNow.ToString('o')
docker run --rm --network container:detector-dns-demo alpine:3.20 nslookup -type=A status.detector.test 127.0.0.1
$end = [datetime]::UtcNow.AddSeconds(1).ToString('o')
$prefix = Join-Path $env:LOCALAPPDATA ('Detector-dns-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
docker logs --timestamps detector-dns-demo 2>$null | Set-Content -LiteralPath "$prefix.log" -Encoding Ascii
python scripts/xdr_capture_coredns.py "$prefix.log" --source-id lab-coredns --tenant-id demo-network --zone detector.test --window-start $start --window-end $end
docker stop detector-dns-demo
```

The expected DNS reply is `192.0.2.10`; the inspection prints only a count
and export hash. The command requires the log layout in the included
`Corefile` and Docker timestamps. It filters to the specified zone and UTC
window, validates field boundaries, and preserves the query type, name,
response code, and observed log timestamp. The capture does not include a
client address, infer a client host, or invent answers for missing logs.

Add `--output "$prefix.jsonl"` to save a new capture before publication.
To publish in the same command, also pass `--publish-local` and set the
gateway's `XDR_INGEST_SECRET` in the local shell without displaying it. This
uses signed HMAC v2 to `127.0.0.1:8091` by default
(`--gateway-port` for an isolated port). Clear the secret from the shell
afterward. Do not repeat a submission merely because a receipt was lost.
Compare the capture's IDs and trace to the broker before deciding whether
to submit again.

`ACCEPTED_BY_GATEWAY` only acknowledges ingest. Check the same event ID,
trace, timestamp, and response code in `telemetry.raw` and
`telemetry.normalized` (`normalization_version=dns-v1`). Local publication
does not provide mTLS capture admission, prove representative traffic
or substitute for a six-hour soak. No rule is enabled by this workflow.
For an operator-approved pilot, retain the original log and capture and
follow the [promotion evidence checklist](rule-promotion-checklist.md).

CoreDNS reference: [query log format](https://coredns.io/plugins/log/) and
[static hosts configuration](https://coredns.io/plugins/hosts/).
