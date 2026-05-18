<?php

namespace App\Console\Commands;

use App\Services\SecretsValidationService;
use Illuminate\Console\Command;

class ValidateSecretsCommand extends Command
{
    protected $signature   = 'security:validate-secrets {--record : Record result to security_hardening_events}';
    protected $description = 'Validate platform secrets and report missing or dev-default values';

    public function handle(SecretsValidationService $secrets): int
    {
        $result = $this->option('record')
            ? $secrets->validateAndRecord()
            : $secrets->validate();

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $this->error('[ERROR] ' . $error);
            }
        }

        if (!empty($result['warnings'])) {
            foreach ($result['warnings'] as $warning) {
                $this->warn('[WARN]  ' . $warning);
            }
        }

        if (empty($result['errors']) && empty($result['warnings'])) {
            $this->info('All secrets validated — no issues found.');
        } elseif (empty($result['errors'])) {
            $this->info('Secrets validated — ' . count($result['warnings']) . ' warning(s), no errors.');
        } else {
            $this->error('Secrets validation failed — ' . count($result['errors']) . ' error(s).');
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
