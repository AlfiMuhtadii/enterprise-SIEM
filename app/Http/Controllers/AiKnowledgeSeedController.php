<?php

namespace App\Http\Controllers;

use App\Services\AiKnowledgeSeedService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiKnowledgeSeedController extends Controller
{
    public function __construct(private readonly AiKnowledgeSeedService $service) {}

    public function index(): View
    {
        return view('soc.ai-knowledge-seed.index', [
            'history'      => $this->service->getSeedHistory(10),
            'fixtures'     => $this->service->getFixtures(50),
            'seededCount'  => $this->service->getSeededCount(),
        ]);
    }

    public function seed(Request $request): RedirectResponse
    {
        $dryRun = $request->boolean('dry_run', false);
        $this->service->seed($request->user()->email, $dryRun);

        $msg = $dryRun ? 'Dry-run complete — no records written.' : 'Knowledge base seeding complete.';
        return redirect()->route('soc.ai.knowledge-seed.index')->with('status', $msg);
    }
}
