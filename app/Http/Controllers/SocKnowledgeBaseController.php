<?php

namespace App\Http\Controllers;

use App\Support\AuditLogger;
use App\Support\SocKnowledgeRetriever;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SocKnowledgeBaseController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $type = trim((string) $request->query('entry_type', ''));
        $tag = trim((string) $request->query('tag', ''));

        $query = DB::table('soc_knowledge_base')->orderByDesc('updated_at');
        if ($q !== '') {
            $query->where(fn ($inner) => $inner->where('title', 'ilike', "%{$q}%")->orWhere('content_markdown', 'ilike', "%{$q}%"));
        }
        if ($type !== '') {
            $query->where('entry_type', $type);
        }
        if ($tag !== '') {
            $query->whereRaw('tags::text ilike ?', ['%'.$tag.'%']);
        }

        return view('soc.knowledge.index', [
            'entries' => $query->paginate(25)->withQueryString(),
            'q' => $q,
            'type' => $type,
            'tag' => $tag,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:220'],
            'entry_type' => ['required', 'in:rule_doc,ioc_note,investigation_template,lesson_learned,analyst_note,response_procedure,mitre_reference,ai_suggestion_feedback'],
            'content_markdown' => ['required', 'string', 'max:20000'],
            'tags' => ['nullable', 'string', 'max:500'],
            'related_incident_id' => ['nullable', 'string', 'max:80'],
            'related_rule_id' => ['nullable', 'string', 'max:120'],
            'related_ioc_id' => ['nullable', 'string', 'max:80'],
        ]);
        $kbId = 'kb-'.Str::uuid();
        DB::table('soc_knowledge_base')->insert([
            'kb_id' => $kbId,
            'title' => $data['title'],
            'entry_type' => $data['entry_type'],
            'content_markdown' => $data['content_markdown'],
            'tags' => json_encode($this->tags($data['tags'] ?? '')),
            'related_incident_id' => $data['related_incident_id'] ?? null,
            'related_rule_id' => $data['related_rule_id'] ?? null,
            'related_ioc_id' => $data['related_ioc_id'] ?? null,
            'created_by' => $request->user()->email,
            'updated_by' => $request->user()->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $entry = DB::table('soc_knowledge_base')->where('kb_id', $kbId)->first();
        (new SocKnowledgeRetriever())->upsertEmbedding($entry);
        AuditLogger::log($request->user()->email, 'knowledge.create', 'knowledge_base', $kbId, null, $data);

        return back()->with('status', 'Knowledge entry saved.');
    }

    private function tags(string $raw): array
    {
        return collect(explode(',', $raw))->map(fn ($tag) => trim($tag))->filter()->values()->all();
    }
}
