<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class BasicPageController extends Controller
{
    public function welcome(): View
    {
        return view('welcome');
    }

    public function search(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }

    public function admin(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'role' => 'admin',
        ]);
    }
}
