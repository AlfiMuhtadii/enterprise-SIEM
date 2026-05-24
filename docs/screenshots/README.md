# Screenshots

This directory contains curated screenshots of the XDR platform for portfolio, thesis, and evaluator use.

## Recommended Screenshots to Capture

| Screenshot | URL | Filename |
|---|---|---|
| Demo Dashboard | /demo-platform | demo-dashboard.png |
| Attack Timeline | /demo-platform/timeline | attack-timeline.png |
| Demo Readiness | /demo-platform/readiness | demo-readiness.png |
| Architecture Explorer | /demo-platform/architecture | architecture-explorer.png |
| Capability Matrix | /demo-platform/capabilities | capability-matrix.png |
| Replay Explorer | /demo-platform/replay | replay-explorer.png |
| Showcase Dashboard | /demo-platform/showcase | showcase-dashboard.png |
| XDR Maturity Dashboard | /xdr-maturity | xdr-maturity-dashboard.png |
| Detection Scorecard | /xdr-maturity/detection | detection-scorecard.png |
| XDR Maturity Report | /xdr-maturity/report | xdr-readiness-report.png |
| XDR Certification | /xdr-certification | xdr-certification.png |
| Threat Hunt Results | /threat-hunts | threat-hunt-results.png |
| Security Alerts | /security/alerts | security-alerts.png |
| SOAR Orchestration | /soar | soar-orchestration.png |
| Grafana Dashboard | :3000 | grafana-overview.png |

## Capturing Screenshots

1. Run `.\bootstrap-dev.ps1` then `php artisan serve`
2. Navigate to each URL above
3. Capture full-page screenshot (browser developer tools → device toolbar → capture)
4. Save as PNG in this directory using the filename from the table above

## Usage

- **Portfolio:** Include `demo-dashboard.png`, `attack-timeline.png`, `xdr-maturity-dashboard.png`, `detection-scorecard.png`
- **Thesis defense:** Include all screenshots as appendix or slide deck
- **Interview:** Include `architecture-explorer.png`, `capability-matrix.png`, `xdr-certification.png`
