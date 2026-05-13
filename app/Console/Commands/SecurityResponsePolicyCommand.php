<?php

namespace App\Console\Commands;

use App\Support\SecurityResponsePolicy;
use Illuminate\Console\Command;

class SecurityResponsePolicyCommand extends Command
{
    protected $signature = 'security:response-policy
                            {--prune : Remove expired policy entries}';

    protected $description = 'Show active security response policy entries';

    public function handle(): int
    {
        $keys = [
            'throttle_ips' => 'THROTTLE_LOGIN_IP',
            'captcha_ips' => 'FORCE_CAPTCHA_IP',
            'revoke_user_ids' => 'REVOKE_SESSION_USER',
        ];

        if ($this->option('prune')) {
            foreach (array_keys($keys) as $key) {
                $removed = SecurityResponsePolicy::pruneExpired($key);
                $this->line("Pruned {$key}: {$removed}");
            }
            $this->newLine();
        }

        foreach ($keys as $key => $action) {
            $entries = SecurityResponsePolicy::activeEntries($key);
            $this->info("{$action} ({$key})");
            $this->table(
                ['target', 'expires_at', 'reason'],
                collect($entries)
                    ->map(fn (array $record, string $target) => [
                        $target,
                        (string) ($record['expires_at'] ?? ''),
                        (string) ($record['reason'] ?? ''),
                    ])
                    ->values()
                    ->all()
            );
        }

        return self::SUCCESS;
    }
}
