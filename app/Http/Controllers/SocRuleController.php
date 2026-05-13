<?php

namespace App\Http\Controllers;

use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

class SocRuleController extends Controller
{
    private function path(): string
    {
        return storage_path('app/telemetry_rules.json');
    }

    public function index(): View
    {
        $payload = json_decode(File::get($this->path()), true) ?: ['rules' => []];
        $qualityPath = base_path('reports/rule_quality_report.json');
        $quality = File::exists($qualityPath) ? (json_decode(File::get($qualityPath), true) ?: []) : [];

        return view('soc.rules', [
            'rules' => $payload['rules'] ?? [],
            'quality' => $quality,
        ]);
    }

    public function update(Request $request, string $ruleId): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['nullable', 'in:0,1'],
            'severity_override' => ['nullable', 'in:,low,medium,high,critical'],
            'metadata_status' => ['nullable', 'string', 'max:80'],
            'metadata_owner' => ['nullable', 'string', 'max:120'],
        ]);

        $payload = json_decode(File::get($this->path()), true) ?: ['version' => 1, 'rules' => []];
        $before = $payload;
        foreach ($payload['rules'] as &$rule) {
            if (($rule['rule_id'] ?? '') !== $ruleId) {
                continue;
            }
            $rule['enabled'] = ($data['enabled'] ?? '0') === '1';
            $rule['severity_override'] = ($data['severity_override'] ?? '') ?: null;
            $rule['metadata'] = $rule['metadata'] ?? [];
            if (isset($data['metadata_status'])) {
                $rule['metadata']['status'] = $data['metadata_status'];
            }
            if (isset($data['metadata_owner'])) {
                $rule['metadata']['owner'] = $data['metadata_owner'];
            }
            break;
        }
        unset($rule);

        File::put($this->path(), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        AuditLogger::log($request->user()->email, 'rule.update', 'rule', $ruleId, $before, $payload);

        return redirect()->route('soc.rules')->with('status', 'Rule updated.');
    }
}
