<?php

namespace Tests\Unit;

use App\Services\ReportExportService;
use App\Services\ReportRenderer;
use PHPUnit\Framework\TestCase;

/**
 * CODE-STRUCT-DECOMPOSE: ReportRenderer is the pure rendering/templating
 * logic extracted from ReportExportService (~550 lines: renderJson,
 * renderMarkdown, renderHtml, the markdown/html section builders, and the
 * render() format dispatcher).
 *
 * The existing ReportExportTest already covers full behavioral correctness
 * end-to-end (all 4 report types x 3 formats, through the real DB-backed
 * service) and still passes unmodified after this extraction — these tests
 * instead prove the renderer is genuinely isolated: no Laravel bootstrap,
 * no DB, plain PHPUnit\Framework\TestCase, matching the TotpServiceTest/
 * ThreatHuntQueryAllowlistTest precedent for pure services in this codebase.
 */
class ReportRendererTest extends TestCase
{
    private function baseMeta(string $type, string $label): array
    {
        return [
            'report_type' => $type,
            'report_type_label' => $label,
            'generated_at' => '2026-07-10T10:00:00+00:00',
            'generated_by' => 'Test Analyst',
            'generated_by_id' => 1,
            'source_id' => 'src-1',
            'export_reason' => 'unit test',
            'disclaimer' => ReportExportService::DISCLAIMER,
            'platform' => 'XDR Platform',
        ];
    }

    public function test_render_json_returns_valid_pretty_printed_json(): void
    {
        $data = ['export_meta' => $this->baseMeta('investigation', 'Investigation Summary Report'), 'investigation' => ['title' => 'Test']];

        $content = ReportRenderer::render($data, 'json');
        $decoded = json_decode($content, true);

        $this->assertNotNull($decoded, 'expected valid JSON output');
        $this->assertSame('Test', $decoded['investigation']['title']);
        $this->assertStringContainsString("\n", $content, 'expected pretty-printed (multi-line) JSON');
    }

    public function test_render_markdown_includes_title_and_disclaimer(): void
    {
        $data = ['export_meta' => $this->baseMeta('investigation', 'Investigation Summary Report'), 'investigation' => []];

        $content = ReportRenderer::render($data, 'markdown');

        $this->assertStringContainsString('# Investigation Summary Report', $content);
        $this->assertStringContainsString(ReportExportService::DISCLAIMER, $content);
    }

    public function test_render_html_is_a_self_contained_document_with_title_and_disclaimer(): void
    {
        $data = ['export_meta' => $this->baseMeta('investigation', 'Investigation Summary Report'), 'investigation' => []];

        $content = ReportRenderer::render($data, 'html');

        $this->assertStringStartsWith('<!DOCTYPE html>', $content);
        $this->assertStringContainsString('<title>Investigation Summary Report</title>', $content);
        $this->assertStringContainsString(ReportExportService::DISCLAIMER, $content);
        $this->assertStringContainsString('<style>', $content, 'expected inline styles for a self-contained document');
    }

    public function test_render_throws_for_unsupported_format(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReportRenderer::render(['export_meta' => $this->baseMeta('investigation', 'x')], 'yaml');
    }

    /**
     * @dataProvider reportTypeProvider
     */
    public function test_markdown_dispatches_to_the_correct_section_builder_per_report_type(string $type, string $label, string $dataKey): void
    {
        $data = ['export_meta' => $this->baseMeta($type, $label), $dataKey => []];

        $content = ReportRenderer::render($data, 'markdown');

        $this->assertStringContainsString("# {$label}", $content);
    }

    /**
     * @dataProvider reportTypeProvider
     */
    public function test_html_dispatches_to_the_correct_section_builder_per_report_type(string $type, string $label, string $dataKey): void
    {
        $data = ['export_meta' => $this->baseMeta($type, $label), $dataKey => []];

        $content = ReportRenderer::render($data, 'html');

        $this->assertStringContainsString("<title>{$label}</title>", $content);
    }

    public static function reportTypeProvider(): array
    {
        return [
            'investigation' => ['investigation', 'Investigation Summary Report', 'investigation'],
            'response_plan' => ['response_plan', 'Response Plan Report', 'response_plan'],
            'entity_risk' => ['entity_risk', 'Entity Risk Report', 'entity'],
            'trace' => ['trace', 'Trace Report', 'trace'],
        ];
    }

    public function test_markdown_omits_export_reason_row_when_absent(): void
    {
        $meta = $this->baseMeta('investigation', 'Investigation Summary Report');
        $meta['export_reason'] = null;
        $content = ReportRenderer::render(['export_meta' => $meta, 'investigation' => []], 'markdown');

        $this->assertStringNotContainsString('Export Reason', $content);
    }

    public function test_markdown_includes_export_reason_row_when_present(): void
    {
        $data = ['export_meta' => $this->baseMeta('investigation', 'Investigation Summary Report'), 'investigation' => []];

        $content = ReportRenderer::render($data, 'markdown');

        $this->assertStringContainsString('Export Reason', $content);
        $this->assertStringContainsString('unit test', $content);
    }
}
