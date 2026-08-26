<?php

declare(strict_types=1);

use App\Support\TraceRedactor;

require dirname(__DIR__).'/vendor/autoload.php';

const DEFAULT_ROWS = 500;
const DEFAULT_FIELD_BYTES = 16384;
const DEFAULT_ITERATIONS = 7;
const DEFAULT_MIN_REDUCTION_PERCENT = 50.0;
const LEGACY_EMAIL_REGEX = '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/';

/** @return never */
function fail(string $message, int $exitCode = 1): void
{
    fwrite(STDERR, "ERROR: {$message}".PHP_EOL);
    exit($exitCode);
}

function positiveInt(mixed $value, string $name, int $default): int
{
    if ($value === false || $value === null) {
        return $default;
    }

    if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
        fail("--{$name} must be a positive integer");
    }

    return (int) $value;
}

/** @return array<string, mixed> */
function buildPayload(int $rowNumber, string $field, int $fieldBytes): array
{
    $seed = hash('sha256', "{$rowNumber}:{$field}");
    $message = substr(str_repeat($seed, (int) ceil($fieldBytes / strlen($seed))), 0, $fieldBytes);

    return [
        'event' => [
            'message' => $message,
            'actor' => "analyst{$rowNumber}@example.test",
            'authorization' => 'Bearer benchmark-secret',
            'sequence' => $rowNumber,
        ],
        'labels' => ['source' => 'benchmark', 'field' => $field],
    ];
}

/** @return list<array<string, mixed>> */
function buildRows(int $rows, int $fieldBytes): array
{
    $result = [];
    for ($index = 0; $index < $rows; $index++) {
        $source = [
            'alert_id' => sprintf('benchmark-alert-%05d', $index),
            'tenant_id' => 'benchmark-tenant',
            'severity' => 'high',
        ];
        foreach (TraceRedactor::JSON_PAYLOAD_FIELDS as $field) {
            $source[$field] = buildPayload($index, $field, $fieldBytes);
        }
        $result[] = $source;
    }

    return $result;
}

function legacyDeep(mixed $value, bool $redactEmails = false): mixed
{
    if (is_array($value)) {
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = TraceRedactor::isSensitiveKey((string) $key)
                ? TraceRedactor::REDACTED
                : legacyDeep($item, $redactEmails);
        }

        return $out;
    }

    if ($value instanceof stdClass) {
        $out = new stdClass;
        foreach (get_object_vars($value) as $key => $item) {
            $out->$key = TraceRedactor::isSensitiveKey($key)
                ? TraceRedactor::REDACTED
                : legacyDeep($item, $redactEmails);
        }

        return $out;
    }

    if ($redactEmails && is_string($value)) {
        return preg_replace(LEGACY_EMAIL_REGEX, TraceRedactor::EMAIL_PLACEHOLDER, $value) ?? $value;
    }

    return $value;
}

/** @param array<string, mixed> $source */
function legacyRedact(array $source): stdClass
{
    foreach (TraceRedactor::JSON_PAYLOAD_FIELDS as $field) {
        if (isset($source[$field]) && ! is_string($source[$field])) {
            $source[$field] = json_encode($source[$field], JSON_THROW_ON_ERROR);
        }
    }

    $row = (object) $source;
    $out = new stdClass;
    foreach (get_object_vars($row) as $key => $value) {
        if (in_array($key, TraceRedactor::JSON_PAYLOAD_FIELDS, true) && is_string($value)) {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
            $out->$key = legacyDeep($decoded, true);
        } elseif (TraceRedactor::isSensitiveKey($key)) {
            $out->$key = TraceRedactor::REDACTED;
        } else {
            $out->$key = $value;
        }
    }

    return $out;
}

/** @param array<string, mixed> $source */
function currentRedact(array $source): stdClass
{
    return TraceRedactor::row((object) $source);
}

/** @return array{cpu_us: int, wall_ns: int} */
function usageSnapshot(): array
{
    $usage = getrusage();
    $user = ((int) ($usage['ru_utime.tv_sec'] ?? 0) * 1_000_000) + (int) ($usage['ru_utime.tv_usec'] ?? 0);
    $system = ((int) ($usage['ru_stime.tv_sec'] ?? 0) * 1_000_000) + (int) ($usage['ru_stime.tv_usec'] ?? 0);

    return ['cpu_us' => $user + $system, 'wall_ns' => hrtime(true)];
}

/** @param list<array<string, mixed>> $rows */
function assertEquivalent(array $rows): void
{
    $sample = $rows[0] ?? null;
    if ($sample === null) {
        fail('benchmark input is empty');
    }

    if (serialize(legacyRedact($sample)) !== serialize(currentRedact($sample))) {
        fail('legacy and current redaction output differ');
    }
}

/** @return array<string, int|float|string|bool> */
function runWorker(string $mode, int $rowCount, int $fieldBytes): array
{
    if (! function_exists('memory_reset_peak_usage')) {
        fail('PHP 8.2 or newer is required for isolated peak-heap measurement');
    }

    $rows = buildRows($rowCount, $fieldBytes);
    assertEquivalent($rows);
    gc_collect_cycles();

    memory_reset_peak_usage();
    $baselineMemory = memory_get_usage(false);
    $before = usageSnapshot();
    $checksum = 0;

    foreach ($rows as $source) {
        $redacted = $mode === 'legacy' ? legacyRedact($source) : currentRedact($source);
        foreach (TraceRedactor::JSON_PAYLOAD_FIELDS as $field) {
            if ($redacted->$field['event']['authorization'] !== TraceRedactor::REDACTED
                || $redacted->$field['event']['actor'] !== TraceRedactor::EMAIL_PLACEHOLDER) {
                fail("{$mode} redaction invariant failed for {$field}");
            }
            $checksum += strlen($redacted->$field['event']['message']);
        }
        unset($redacted);
    }

    $after = usageSnapshot();
    $peakHeap = max(0, memory_get_peak_usage(false) - $baselineMemory);

    return [
        'mode' => $mode,
        'rows' => $rowCount,
        'field_bytes' => $fieldBytes,
        'payload_fields_per_row' => count(TraceRedactor::JSON_PAYLOAD_FIELDS),
        'processed_payload_bytes' => $checksum,
        'cpu_ms' => round(($after['cpu_us'] - $before['cpu_us']) / 1000, 3),
        'wall_ms' => round(($after['wall_ns'] - $before['wall_ns']) / 1_000_000, 3),
        'peak_heap_bytes' => $peakHeap,
        'output_equivalent' => true,
    ];
}

/** @return array<string, mixed> */
function executeWorker(string $mode, int $rows, int $fieldBytes): array
{
    $command = [
        PHP_BINARY,
        '-d',
        'opcache.enable_cli=0',
        __FILE__,
        "--mode={$mode}",
        "--rows={$rows}",
        "--field-bytes={$fieldBytes}",
    ];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    if (! is_resource($process)) {
        fail("unable to start {$mode} benchmark worker");
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        fail("{$mode} benchmark worker failed with exit {$exitCode}: ".trim($stderr));
    }

    $decoded = json_decode($stdout, true);
    if (! is_array($decoded)) {
        fail("{$mode} benchmark worker returned invalid JSON");
    }

    return $decoded;
}

/** @param list<float|int> $values */
function median(array $values): float
{
    sort($values, SORT_NUMERIC);
    $count = count($values);
    $middle = intdiv($count, 2);

    return $count % 2 === 1
        ? (float) $values[$middle]
        : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
}

function reductionPercent(float $legacy, float $current): float
{
    return $legacy <= 0.0 ? 0.0 : round((($legacy - $current) / $legacy) * 100, 2);
}

$options = getopt('', [
    'mode::',
    'rows::',
    'field-bytes::',
    'iterations::',
    'min-reduction-percent::',
    'output::',
    'report-only',
]);
$mode = (string) ($options['mode'] ?? 'compare');
$rows = positiveInt($options['rows'] ?? null, 'rows', DEFAULT_ROWS);
$fieldBytes = positiveInt($options['field-bytes'] ?? null, 'field-bytes', DEFAULT_FIELD_BYTES);

if (in_array($mode, ['current', 'legacy'], true)) {
    echo json_encode(runWorker($mode, $rows, $fieldBytes), JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode !== 'compare') {
    fail('--mode must be compare, current, or legacy');
}

$iterations = positiveInt($options['iterations'] ?? null, 'iterations', DEFAULT_ITERATIONS);
$minimumReduction = isset($options['min-reduction-percent'])
    ? (float) $options['min-reduction-percent']
    : DEFAULT_MIN_REDUCTION_PERCENT;
if ($minimumReduction < 0 || $minimumReduction > 100) {
    fail('--min-reduction-percent must be between 0 and 100');
}

$samples = ['legacy' => [], 'current' => []];
for ($iteration = 0; $iteration < $iterations; $iteration++) {
    $order = $iteration % 2 === 0 ? ['current', 'legacy'] : ['legacy', 'current'];
    foreach ($order as $workerMode) {
        $samples[$workerMode][] = executeWorker($workerMode, $rows, $fieldBytes);
    }
}

$medianMetrics = [];
foreach (['legacy', 'current'] as $workerMode) {
    $medianMetrics[$workerMode] = [
        'cpu_ms' => round(median(array_column($samples[$workerMode], 'cpu_ms')), 3),
        'wall_ms' => round(median(array_column($samples[$workerMode], 'wall_ms')), 3),
        'peak_heap_bytes' => (int) round(median(array_column($samples[$workerMode], 'peak_heap_bytes'))),
    ];
}

$reductions = [
    'cpu_percent' => reductionPercent($medianMetrics['legacy']['cpu_ms'], $medianMetrics['current']['cpu_ms']),
    'wall_percent' => reductionPercent($medianMetrics['legacy']['wall_ms'], $medianMetrics['current']['wall_ms']),
    'peak_heap_percent' => reductionPercent($medianMetrics['legacy']['peak_heap_bytes'], $medianMetrics['current']['peak_heap_bytes']),
];
$passed = $reductions['cpu_percent'] >= $minimumReduction
    && $reductions['peak_heap_percent'] >= $minimumReduction;
$report = [
    'benchmark' => 'PERF-REDACTION-OVERHEAD',
    'status' => $passed ? 'PASS' : 'FAIL',
    'output_equivalent' => true,
    'configuration' => [
        'rows' => $rows,
        'payload_fields_per_row' => count(TraceRedactor::JSON_PAYLOAD_FIELDS),
        'field_bytes' => $fieldBytes,
        'iterations' => $iterations,
        'minimum_reduction_percent' => $minimumReduction,
        'php_version' => PHP_VERSION,
    ],
    'median' => $medianMetrics,
    'reduction' => $reductions,
    'samples' => $samples,
];
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

if (isset($options['output'])) {
    $output = (string) $options['output'];
    $directory = dirname($output);
    if ($directory !== '.' && ! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        fail("unable to create output directory {$directory}");
    }
    if (file_put_contents($output, $json) === false) {
        fail("unable to write benchmark report {$output}");
    }
}

echo $json;
exit($passed || isset($options['report-only']) ? 0 : 2);
