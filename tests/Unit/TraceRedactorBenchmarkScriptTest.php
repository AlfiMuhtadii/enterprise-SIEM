<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class TraceRedactorBenchmarkScriptTest extends TestCase
{
    public function test_benchmark_smoke_run_produces_comparable_metrics(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $outputPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'trace-redactor-benchmark-'.uniqid().'.json';
        $process = proc_open(
            [
                PHP_BINARY,
                $projectRoot.DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'xdr_trace_redactor_benchmark.php',
                '--rows=12',
                '--field-bytes=256',
                '--iterations=1',
                '--output='.$outputPath,
                '--report-only',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $projectRoot,
        );

        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        try {
            $this->assertSame(0, $exitCode, "Benchmark failed:\nSTDOUT: {$stdout}\nSTDERR: {$stderr}");
            $this->assertFileExists($outputPath);
            $report = json_decode((string) file_get_contents($outputPath), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame('PERF-REDACTION-OVERHEAD', $report['benchmark']);
            $this->assertTrue($report['output_equivalent']);
            $this->assertSame(12, $report['configuration']['rows']);
            $this->assertCount(1, $report['samples']['legacy']);
            $this->assertCount(1, $report['samples']['current']);
            $this->assertGreaterThan(0, $report['median']['legacy']['peak_heap_bytes']);
            $this->assertGreaterThan(0, $report['median']['current']['peak_heap_bytes']);
        } finally {
            if (is_file($outputPath)) {
                unlink($outputPath);
            }
        }
    }
}
