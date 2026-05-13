<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SocIocImportCommand extends Command
{
    protected $signature = 'soc:ioc-import {path} {--format=jsonl} {--source=cli}';
    protected $description = 'Import IOC feed from JSONL or CSV file.';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        if (!is_file($path)) {
            $this->error('file not found: '.$path);
            return self::FAILURE;
        }
        $format = (string) $this->option('format');
        $rows = $format === 'csv' ? $this->csv(file_get_contents($path)) : $this->jsonl(file_get_contents($path));
        $count = 0;
        foreach ($rows as $row) {
            $type = $row['ioc_type'] ?? $row['type'] ?? null;
            $value = $row['ioc_value'] ?? $row['value'] ?? null;
            if (!in_array($type, ['ip', 'domain', 'hash', 'url'], true) || !is_string($value) || trim($value) === '') {
                continue;
            }
            DB::table('threat_iocs')->updateOrInsert(
                ['ioc_type' => $type, 'ioc_value' => trim($value)],
                [
                    'ioc_id' => 'ioc-'.Str::uuid(),
                    'source' => $row['source'] ?? $this->option('source'),
                    'reputation' => $row['reputation'] ?? 'suspicious',
                    'threat_label' => $row['threat_label'] ?? $row['label'] ?? null,
                    'expires_at' => $row['expires_at'] ?? null,
                    'enabled' => true,
                    'metadata' => json_encode(['import_path' => $path, 'format' => $format]),
                    'created_by' => 'cli',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            $count++;
        }
        $this->info("imported={$count}");
        return self::SUCCESS;
    }

    private function jsonl(string $body): array
    {
        $rows = [];
        foreach (preg_split('/\r\n|\n|\r/', $body) as $line) {
            $row = json_decode(trim($line), true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private function csv(string $body): array
    {
        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', $body), fn ($line) => trim($line) !== ''));
        if (!$lines) {
            return [];
        }
        $headers = str_getcsv(array_shift($lines));
        return array_map(fn ($line) => array_combine($headers, array_pad(str_getcsv($line), count($headers), null)), $lines);
    }
}
