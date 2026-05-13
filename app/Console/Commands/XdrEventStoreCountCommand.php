<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class XdrEventStoreCountCommand extends Command
{
    protected $signature = 'xdr:event-store-count
        {--event-type= : Filter by event type}
        {--trace-id= : Filter by trace ID}
        {--json : Print JSON output}';

    protected $description = 'Count XDR operational event-store rows for validation scripts.';

    public function handle(): int
    {
        $query = DB::table('xdr_operational_events');

        if ($eventType = $this->option('event-type')) {
            $query->where('event_type', (string) $eventType);
        }

        if ($traceId = $this->option('trace-id')) {
            $query->where('trace_id', (string) $traceId);
        }

        $count = (int) $query->count();

        if ($this->option('json')) {
            $this->line(json_encode(['count' => $count]));
        } else {
            $this->line((string) $count);
        }

        return self::SUCCESS;
    }
}
