<?php

namespace App\Console\Commands;

use App\Services\RealEndpointEnrollmentService;
use Illuminate\Console\Command;

class EndpointVerifyEnrollmentCommand extends Command
{
    protected $signature = 'endpoint:verify-enrollment
                            {token? : Enrollment token to verify}
                            {--list  : List all enrolled endpoints}';

    protected $description = 'Verify a real endpoint enrollment token or list all enrollments (ENTERPRISE-053)';

    public function handle(RealEndpointEnrollmentService $service): int
    {
        if ($this->option('list')) {
            $enrollments = $service->getEnrollments();
            $summary     = $service->getSummary();
            $this->info("Enrolled endpoints: {$summary['total']}/{$summary['max_enrollments']}");
            $this->line("  real_os_data    : {$summary['real_os_data']}");
            $this->line("  heartbeat_active: {$summary['heartbeat_active']}");
            foreach ($enrollments as $e) {
                $this->line("  [{$e->os_platform}] {$e->hostname} (token={$e->enrollment_token})");
            }
            return 0;
        }

        $token = $this->argument('token');
        if (!$token) {
            $this->error('Provide a token argument or use --list');
            return 1;
        }

        $result = $service->validateEnrollment($token);

        if (!$result['valid']) {
            $this->error("Token invalid: " . ($result['reason'] ?? 'unknown'));
            return 1;
        }

        $this->info('Enrollment valid:');
        $this->line("  enrollment_id: {$result['enrollment_id']}");
        $this->line("  hostname     : {$result['hostname']}");
        $this->line("  os_platform  : {$result['os_platform']}");
        $this->line("  is_real      : " . ($result['is_real'] ? 'true' : 'false'));
        $this->line("  is_advisory  : true");

        return 0;
    }
}
