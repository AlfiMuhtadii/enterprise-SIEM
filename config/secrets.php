<?php

return [
    // Pluggable secret-provider backend (SECRETS-VAULT). Default 'env' keeps
    // demo behavior unchanged. 'vault' reads/writes a HashiCorp Vault KV-v2
    // path via app/Services/Secrets/VaultSecretProvider.php.
    'backend' => env('XDR_SECRET_BACKEND', 'env'),

    'vault' => [
        'addr' => env('XDR_VAULT_ADDR', ''),
        'token' => env('XDR_VAULT_TOKEN', ''),
        'secret_path' => env('XDR_VAULT_SECRET_PATH', 'secret/data/xdr'),
        'timeout_seconds' => (int) env('XDR_VAULT_TIMEOUT_SECONDS', 5),
    ],
];
