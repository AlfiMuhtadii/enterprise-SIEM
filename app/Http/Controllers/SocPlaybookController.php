<?php

namespace App\Http\Controllers;

use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SocPlaybookController extends Controller
{
    public function store(Request $request, string $incidentId): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'incident_type' => ['nullable', 'string', 'max:120'],
            'assigned_analyst' => ['nullable', 'string', 'max:120'],
            'template' => ['nullable', 'in:generic,web_attack,endpoint_compromise'],
        ]);
        abort_unless(DB::table('security_incidents')->where('incident_id', $incidentId)->exists(), 404);

        $playbookId = 'pb-'.Str::uuid();
        DB::table('incident_playbooks')->insert([
            'playbook_id' => $playbookId,
            'incident_id' => $incidentId,
            'name' => $data['name'],
            'incident_type' => $data['incident_type'] ?? null,
            'status' => 'active',
            'assigned_analyst' => $data['assigned_analyst'] ?? $request->user()->email,
            'progress_percent' => 0,
            'metadata' => json_encode(['template' => $data['template'] ?? 'manual']),
            'created_by' => $request->user()->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($this->templateTasks($data['template'] ?? 'generic') as $idx => $task) {
            DB::table('incident_playbook_tasks')->insert([
                'task_id' => 'task-'.Str::uuid(),
                'playbook_id' => $playbookId,
                'task_type' => $task['type'],
                'title' => $task['title'],
                'description' => $task['description'] ?? null,
                'status' => 'pending',
                'assigned_to' => $data['assigned_analyst'] ?? $request->user()->email,
                'sort_order' => $idx + 1,
                'metadata' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        AuditLogger::log($request->user()->email, 'playbook.create', 'incident', $incidentId, null, ['playbook_id' => $playbookId]);

        return back()->with('status', 'Playbook created.');
    }

    public function updateTask(Request $request, string $taskId): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:pending,in_progress,blocked,completed,skipped'],
            'assigned_to' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $before = DB::table('incident_playbook_tasks')->where('task_id', $taskId)->first();
        abort_if(!$before, 404);
        DB::table('incident_playbook_tasks')->where('task_id', $taskId)->update([
            'status' => $data['status'],
            'assigned_to' => $data['assigned_to'] ?? $before->assigned_to,
            'completed_at' => $data['status'] === 'completed' ? now() : null,
            'metadata' => json_encode(['last_note' => $data['note'] ?? null]),
            'updated_at' => now(),
        ]);
        $this->refreshProgress($before->playbook_id);
        $after = DB::table('incident_playbook_tasks')->where('task_id', $taskId)->first();
        AuditLogger::log($request->user()->email, 'playbook.task_update', 'playbook_task', $taskId, $before, $after);

        return back()->with('status', 'Playbook task updated.');
    }

    private function refreshProgress(string $playbookId): void
    {
        $total = DB::table('incident_playbook_tasks')->where('playbook_id', $playbookId)->count();
        $done = DB::table('incident_playbook_tasks')->where('playbook_id', $playbookId)->whereIn('status', ['completed', 'skipped'])->count();
        $progress = $total > 0 ? (int) floor(($done / $total) * 100) : 0;
        DB::table('incident_playbooks')->where('playbook_id', $playbookId)->update([
            'progress_percent' => $progress,
            'status' => $progress >= 100 ? 'completed' : 'active',
            'updated_at' => now(),
        ]);
    }

    private function templateTasks(string $template): array
    {
        $base = [
            ['type' => 'investigation', 'title' => 'Validate alert evidence'],
            ['type' => 'investigation', 'title' => 'Identify affected entities'],
            ['type' => 'approval', 'title' => 'Confirm response approval requirement'],
            ['type' => 'closure', 'title' => 'Write closure validation summary'],
        ];
        if ($template === 'web_attack') {
            return array_merge([
                ['type' => 'investigation', 'title' => 'Review HTTP request and source IP history'],
                ['type' => 'response', 'title' => 'Evaluate IP/session containment'],
            ], $base);
        }
        if ($template === 'endpoint_compromise') {
            return array_merge([
                ['type' => 'investigation', 'title' => 'Review process/network timeline'],
                ['type' => 'response', 'title' => 'Collect endpoint forensic bundle'],
            ], $base);
        }
        return $base;
    }
}
