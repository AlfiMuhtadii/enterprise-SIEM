<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div><p class="brand-chip">SOC Knowledge</p><h2 class="mt-2 text-2xl font-semibold text-main-ui">Knowledge Base</h2></div>
            <a href="{{ route('soc.dashboard') }}" class="rounded-lg border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Back to SOC</a>
        </div>
    </x-slot>

    @if (session('status'))<div class="mb-4 rounded-lg border border-cyan-200/20 bg-cyan-100/10 p-3 text-sm text-cyan-50">{{ session('status') }}</div>@endif

    <section class="glass-card p-5">
        <h3 class="text-lg font-semibold text-main-ui">Search Knowledge</h3>
        <form method="GET" action="{{ route('soc.knowledge') }}" class="mt-3 grid gap-2 md:grid-cols-5">
            <input name="q" value="{{ $q }}" placeholder="search title/content" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
            <select name="entry_type" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                <option value="">all types</option>
                @foreach (['rule_doc','ioc_note','investigation_template','lesson_learned','analyst_note','response_procedure','mitre_reference'] as $entryType)
                    <option value="{{ $entryType }}" @selected($type === $entryType)>{{ $entryType }}</option>
                @endforeach
            </select>
            <input name="tag" value="{{ $tag }}" placeholder="tag" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
            <button class="rounded border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Filter</button>
        </form>
    </section>

    @if (in_array(Auth::user()?->role, ['admin', 'analyst'], true))
        <section class="glass-card mt-4 p-5">
            <h3 class="text-lg font-semibold text-main-ui">Add Knowledge Entry</h3>
            <form method="POST" action="{{ route('soc.knowledge.store') }}" class="mt-3 grid gap-3 md:grid-cols-4">
                @csrf
                <input name="title" placeholder="title" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                <select name="entry_type" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                    @foreach (['rule_doc','ioc_note','investigation_template','lesson_learned','analyst_note','response_procedure','mitre_reference'] as $entryType)
                        <option value="{{ $entryType }}">{{ $entryType }}</option>
                    @endforeach
                </select>
                <input name="tags" placeholder="tags comma separated" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                <input name="related_incident_id" placeholder="related incident" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                <input name="related_rule_id" placeholder="related rule" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                <input name="related_ioc_id" placeholder="related IOC" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                <textarea name="content_markdown" placeholder="markdown content" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50 md:col-span-4" rows="6"></textarea>
                <button class="rounded border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50 md:col-span-4">Save Entry</button>
            </form>
        </section>
    @endif

    <section class="glass-card mt-4 overflow-hidden">
        <div class="border-b border-cyan-100/15 px-5 py-4"><h3 class="text-lg font-semibold text-main-ui">Entries</h3></div>
        <div class="grid gap-3 p-5">
            @forelse ($entries as $entry)
                @php $tags = json_decode($entry->tags ?: '[]', true) ?: []; @endphp
                <article class="rounded-lg border border-cyan-200/15 bg-black/20 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div><p class="text-lg font-semibold text-cyan-50">{{ $entry->title }}</p><p class="text-xs text-cyan-100/60">{{ $entry->entry_type }} | {{ $entry->created_by }} | {{ $entry->updated_at }}</p></div>
                        <div class="flex flex-wrap gap-1">@foreach ($tags as $tagName)<span class="rounded-full border border-cyan-200/20 px-2 py-1 text-xs text-cyan-100">{{ $tagName }}</span>@endforeach</div>
                    </div>
                    <pre class="mt-3 whitespace-pre-wrap rounded bg-slate-950/60 p-3 text-sm text-cyan-100">{{ $entry->content_markdown }}</pre>
                    <p class="mt-2 text-xs text-cyan-100/50">incident={{ $entry->related_incident_id ?: '-' }} rule={{ $entry->related_rule_id ?: '-' }} ioc={{ $entry->related_ioc_id ?: '-' }}</p>
                </article>
            @empty
                <p class="p-5 text-sm text-muted-ui">No knowledge entries found.</p>
            @endforelse
        </div>
        <div class="border-t border-cyan-100/15 px-5 py-4">{{ $entries->links() }}</div>
    </section>
</x-app-layout>
