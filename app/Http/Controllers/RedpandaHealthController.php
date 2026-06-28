<?php

namespace App\Http\Controllers;

use App\Services\RedpandaRecoveryHardeningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RedpandaHealthController extends Controller
{
    public function __construct(private readonly RedpandaRecoveryHardeningService $service) {}

    public function index(): View
    {
        return view('soc.redpanda-health.index', [
            'topicHistory'   => $this->service->getTopicHealthHistory(5),
            'consumerHistory' => $this->service->getConsumerGroupHistory(5),
            'recoveryEvents' => $this->service->getRecoveryEvents(20),
            'expectedTopics' => RedpandaRecoveryHardeningService::EXPECTED_TOPICS,
            'expectedGroups' => RedpandaRecoveryHardeningService::EXPECTED_CONSUMER_GROUPS,
        ]);
    }

    public function check(Request $request): RedirectResponse
    {
        $this->service->assessTopicHealth($request->user()->email);
        $this->service->assessConsumerGroupHealth($request->user()->email);
        return redirect()->route('soc.redpanda.health.index')
            ->with('status', 'Health check complete (advisory offline check).');
    }

    public function events(): View
    {
        return view('soc.redpanda-health.events', [
            'events' => $this->service->getRecoveryEvents(100),
        ]);
    }
}
