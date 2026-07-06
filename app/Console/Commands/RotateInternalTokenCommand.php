<?php

namespace App\Console\Commands;

use App\Contracts\SecretProvider;
use App\Models\SecurityHardeningEvent;
use Illuminate\Console\Command;

/**
 * SECRETS-VAULT: rotate XDR_INTERNAL_AUTH_SECRET via the configured
 * secret-provider backend. The 'env' backend cannot durably persist a
 * rotated value from within a running process (a PHP process cannot
 * mutate its parent shell's environment) — it reports the generated
 * value and instructs the operator to update .env and restart. The
 * 'vault' backend writes the rotated value directly.
 */
class RotateInternalTokenCommand extends Command
{
    protected $signature = 'security:rotate-internal-token {--dry-run : Generate a candidate secret only, do not write it anywhere}';

    protected $description = 'Rotate the internal service-to-service auth secret via the configured secret-provider backend';

    public function handle(SecretProvider $provider): int
    {
        $newSecret = bin2hex(random_bytes(32));

        if ($this->option('dry-run')) {
            $this->info('dry-run: candidate secret generated, not written:');
            $this->line($newSecret);
            return self::SUCCESS;
        }

        $written = $provider->set('XDR_INTERNAL_AUTH_SECRET', $newSecret);

        SecurityHardeningEvent::record(
            SecurityHardeningEvent::EVENT_SECRET_ROTATION,
            'laravel',
            ['backend' => $provider->driverName(), 'written' => $written],
            'cli',
        );

        if ($written) {
            $this->info("Secret rotated via the '{$provider->driverName()}' backend. Restart internal-auth-dependent services to pick up the new value.");
            return self::SUCCESS;
        }

        $this->warn("The '{$provider->driverName()}' backend cannot persist a rotated secret from within a running process.");
        $this->line('Set this value as XDR_INTERNAL_AUTH_SECRET in .env (and the corresponding secret store for every polyglot service), then restart all services:');
        $this->line($newSecret);

        return self::SUCCESS;
    }
}
