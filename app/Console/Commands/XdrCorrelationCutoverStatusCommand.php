<?php

namespace App\Console\Commands;

use App\Support\XdrCorrelationCutover;
use Illuminate\Console\Command;

class XdrCorrelationCutoverStatusCommand extends Command
{
    protected $signature = 'xdr:correlation-cutover-status
        {--engine= : Runtime preview override: legacy, go, or shadow}
        {--scope= : Runtime preview override. Only identity-cloud is eligible}
        {--audit=1 : Write audit event when cutover/fallback state changes}
        {--json : Print JSON output}';

    protected $description = 'Evaluate staged XDR correlation cutover, fallback, and parity monitoring for identity/cloud scope.';

    public function handle(): int
    {
        $engine = $this->option('engine') ?: null;
        $scope = $this->option('scope') ?: null;
        $status = XdrCorrelationCutover::status($engine, $scope);

        if (($status['scope'] ?? null) !== 'identity-cloud') {
            $this->error('Only XDR_CORRELATION_SCOPE=identity-cloud is supported for staged Go cutover.');
            return self::FAILURE;
        }

        if ((string) $this->option('audit') !== '0') {
            XdrCorrelationCutover::auditStatus($status);
        }

        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->line('Configured engine: '.$status['configured_engine']);
        $this->line('Effective engine: '.$status['effective_engine']);
        $this->line('Scope: '.$status['scope']);
        $this->line('Source of truth: '.$status['source_of_truth']);
        $this->line('Comparison engine: '.$status['comparison_engine']);
        $this->line('Go worker: '.$status['go_worker']['status']);
        if ($status['fallback_active']) {
            $this->warn('Fallback active: '.$status['fallback_reason']);
        }
        $this->line('Cutover gate: '.($status['cutover_gate']['passed'] ? 'PASS' : 'FAIL'));
        $this->line('Alert delta: '.($status['monitoring']['alert_count_delta'] ?? 'n/a'));
        $this->line('Go p95 latency: '.($status['monitoring']['p95_latency_ms'] ?? 'n/a').' ms');
        $this->line('Stream lag: '.($status['monitoring']['stream_lag'] ?? 'n/a'));
        $this->line('Manual rollback: '.$status['manual_rollback']['env']);

        return self::SUCCESS;
    }
}
