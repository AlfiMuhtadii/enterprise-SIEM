# Phase 14 - Response Bertingkat (Mitigation Story)

Tujuan: tambah mitigasi aman tanpa langsung jadi auto-block agresif.

## 1) Response Levels

- `recommend` (default): hanya publish rekomendasi action.
- `auto`: eksekusi step-up action aman.

Action map:
- `BRUTE_FORCE_IP`, `CREDENTIAL_STUFFING`, `ML_BRUTEFORCE` -> `THROTTLE_LOGIN_IP`
- `SCAN_BURST`, `ML_SCAN`, `INJECTION_INDICATOR`, `ML_INJECTION` -> `FORCE_CAPTCHA_IP`
- `PRIVILEGE_PROBING` -> `REVOKE_SESSION_USER`

## 2) Suppress False Positive

- IP allowlist dari `storage/app/detector_allowlist.json`
- jika target IP ada di allowlist -> status response `suppressed`

## 3) Komponen Baru

- Tabel response:
  - `security_responses` (migration `2026_03_06_000007_create_security_responses_table.php`)
- Engine:
  - `scripts/response_engine.py`
- Runtime enforcement:
  - `app/Http/Requests/Auth/LoginRequest.php`
    - cek throttle/captcha policy file
  - `app/Http/Middleware/EnforceRevokedSessions.php`
    - logout paksa user yang masuk revoke list
  - helper policy:
    - `app/Support/SecurityResponsePolicy.php`

Policy files (auto mode):
- `storage/app/response/throttle_ips.json`
- `storage/app/response/captcha_ips.json`
- `storage/app/response/revoke_user_ids.json`

## 4) Jalankan

Recommend-only:

```bash
python scripts/response_engine.py --mode recommend --minutes 30
```

Auto step-up:

```bash
python scripts/response_engine.py --mode auto --minutes 30
```

## 5) Audit Trail

Response action tercatat di:
- `security_responses`
- `security_audit_trails` (`RESPONSE_ENGINE_ACTION`)

Perubahan allowlist/threshold/model deploy juga sudah diaudit:
- `ALLOWLIST_UPDATED`
- `THRESHOLD_UPDATED`
- `MODEL_DEPLOYED`
