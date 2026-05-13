<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SecurityExportDatasetCommand extends Command
{
    protected $signature = 'security:export-dataset
                            {--from= : Start timestamp (e.g. 2026-03-04T00:00:00+07:00)}
                            {--to= : End timestamp}
                            {--output= : Output CSV path (default: storage/app/security_dataset.csv)}';

    protected $description = 'Export labeled training dataset to CSV using attack_runs windows';

    public function handle(): int
    {
        $from = $this->option('from') ? (string) $this->option('from') : null;
        $to = $this->option('to') ? (string) $this->option('to') : null;
        $output = (string) ($this->option('output') ?: storage_path('app/security_dataset.csv'));

        $dir = dirname($output);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            $this->error("Cannot create output directory: {$dir}");
            return self::FAILURE;
        }

        $fp = fopen($output, 'wb');
        if ($fp === false) {
            $this->error("Cannot open output file: {$output}");
            return self::FAILURE;
        }

        $headers = [
            'ts',
            'request_id',
            'event_type',
            'ip',
            'user_id',
            'method',
            'path',
            'status',
            'latency_ms',
            'query_hash',
            'has_sql_keywords',
            'has_script_payload',
            'label',
            'attack_run_id',
        ];
        fputcsv($fp, $headers);

        $sql = "
            select
                se.ts,
                se.request_id,
                se.event_type,
                se.ip,
                se.user_id,
                se.method,
                se.path,
                se.status,
                se.latency_ms,
                se.query_hash,
                se.has_sql_keywords,
                se.has_script_payload,
                coalesce(ar.attack_type, 'normal') as label,
                ar.id as attack_run_id
            from security_events se
            left join lateral (
                select id, attack_type
                from attack_runs
                where se.ts >= started_at
                  and se.ts <= coalesce(ended_at, now())
                order by started_at desc
                limit 1
            ) ar on true
        ";

        $clauses = [];
        $bindings = [];
        if ($from !== null) {
            $clauses[] = 'se.ts >= cast(? as timestamptz)';
            $bindings[] = $from;
        }
        if ($to !== null) {
            $clauses[] = 'se.ts <= cast(? as timestamptz)';
            $bindings[] = $to;
        }
        if (!empty($clauses)) {
            $sql .= ' where ' . implode(' and ', $clauses);
        }
        $sql .= ' order by se.ts asc';

        $count = 0;
        foreach (DB::cursor($sql, $bindings) as $row) {
            fputcsv($fp, [
                $row->ts,
                $row->request_id,
                $row->event_type,
                $row->ip,
                $row->user_id,
                $row->method,
                $row->path,
                $row->status,
                $row->latency_ms,
                $row->query_hash,
                $row->has_sql_keywords ? 1 : 0,
                $row->has_script_payload ? 1 : 0,
                $row->label,
                $row->attack_run_id,
            ]);
            $count++;
        }

        fclose($fp);
        $this->info("Exported {$count} rows to {$output}");

        return self::SUCCESS;
    }
}
