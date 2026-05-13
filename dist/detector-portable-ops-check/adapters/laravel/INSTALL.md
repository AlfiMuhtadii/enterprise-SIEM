# Laravel Adapter Install

Copy these files into the target Laravel app:

- `config/security_detector.php` -> `config/security_detector.php`
- `app/Services/SecurityLogger.php` -> `app/Services/SecurityLogger.php`
- `app/Http/Middleware/SecurityRequestLogger.php` -> `app/Http/Middleware/SecurityRequestLogger.php`

Register the middleware in `app/Http/Kernel.php`.

For Laravel 10 style `Kernel.php`, add this to the global middleware stack:

```php
protected $middleware = [
    // existing middleware...
    \App\Http\Middleware\SecurityRequestLogger::class,
];
```

Add environment variables:

```dotenv
SECURITY_DETECTOR_ENABLED=true
SECURITY_DETECTOR_LOG_PATH=/absolute/path/to/storage/logs/security.jsonl
SECURITY_DETECTOR_HASH_KEY="${APP_KEY}"
SECURITY_DETECTOR_CAPTURE_QUERY_PATHS=search,api/search,products
SECURITY_DETECTOR_IGNORED_PATHS=up,health,livewire/update
```

Validate the generated file from the detector package:

```powershell
python engine/scripts/security_event_contract.py --file C:\client-app\storage\logs\security.jsonl
```

For login detection, call `SecurityLogger::log('auth_login_failed', [...])` after failed authentication and `SecurityLogger::log('auth_login_success', [...])` after successful authentication. The required fields are the same as `http_request`: `request_id`, `ip`, `user_agent_hash`, `user_id`, `email_hash`, `method`, `path`, `status`, `latency_ms`, `query_hash`, `has_sql_keywords`, and `has_script_payload`.
