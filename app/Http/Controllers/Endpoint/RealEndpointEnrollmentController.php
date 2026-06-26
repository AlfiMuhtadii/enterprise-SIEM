<?php

namespace App\Http\Controllers\Endpoint;

use App\Http\Controllers\Controller;
use App\Services\RealEndpointEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RealEndpointEnrollmentController extends Controller
{
    public function __construct(private readonly RealEndpointEnrollmentService $service) {}

    public function index(Request $request): View|JsonResponse
    {
        $enrollments = $this->service->getEnrollments();
        $summary     = $this->service->getSummary();

        $viewData = [
            'enrollments'  => $enrollments,
            'summary'      => $summary,
            'advisory_only'=> true,
        ];

        if ($request->expectsJson()) {
            return response()->json($viewData);
        }

        return view('endpoint.real_enrollments', $viewData);
    }
}
