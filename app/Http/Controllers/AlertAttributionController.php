<?php

namespace App\Http\Controllers;

use App\Services\AlertAttributionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AlertAttributionController extends Controller
{
    public function index(Request $request, AlertAttributionService $attribution): View
    {
        $limit   = max(10, min(200, (int) $request->query('limit', 50)));
        $records = $attribution->latest($limit);

        return view('security.attribution', [
            'records' => $records,
            'limit'   => $limit,
        ]);
    }
}
