# Immutable Release Verification

This verifier is a mandatory pre-deployment gate for published Detector images.
It validates the retained release manifest and verifies every image by digest.
It does not deploy, pull, retag, or mutate registry content.

## Trust Policy

- Accept only a manifest artifact downloaded from the expected GitHub release workflow run.
- Trust only the exact workflow identity derived from the repository and SemVer tag:
  `https://github.com/OWNER/REPOSITORY/.github/workflows/release.yml@refs/tags/TAG`.
- Trust only GitHub Actions' OIDC issuer: `https://token.actions.githubusercontent.com`.
- Supply the approved release tag and commit independently; do not derive them from the manifest.
- Require the signing certificate's GitHub workflow SHA, repository, and tag ref claims to match.
- Verify `image@sha256:digest`; never verify or deploy a mutable image tag alone.
- Require retained vulnerability evidence with policy `release-critical-v1`
  status `PASS` for every image. See
  [Release Vulnerability Policy](RELEASE_VULNERABILITY_POLICY.md).
- Treat malformed manifests, missing Cosign, timeouts, network errors, and any invalid signature as blocking failures.

## Run

Install Cosign through the platform's pinned package/tooling process. Do not let
this script download or execute an unpinned verifier automatically.

```bash
python scripts/xdr_release_verify.py \
  --manifest release-manifest.json \
  --repository AlfiMuhtadii/enterprise-SIEM \
  --release-tag v1.2.3 \
  --commit 0123456789abcdef0123456789abcdef01234567 \
  --output reports/xdr_release_verification.json
```

A deployment may consume only the `image@digest` references from a report whose
top-level `status` is `PASS`. Preserve that report with the deployment evidence.
`FAIL` means at least one signature failed verification. `ERROR` means the
manifest, policy, local verifier, or input could not be trusted.

Manual release publication must be dispatched with the workflow ref set to the
same tag supplied in the `tag` input. Branch-ref publication is rejected so the
certificate identity remains exact and independently reproducible.
