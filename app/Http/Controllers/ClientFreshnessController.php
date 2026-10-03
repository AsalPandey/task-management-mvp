<?php

namespace App\Http\Controllers;

use App\Services\ClientFreshness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClientFreshnessController extends Controller
{
    public function __invoke(Request $request, ClientFreshness $freshness): JsonResponse
    {
        return response()->json([
            'user' => (string) $request->user()->id,
            'version' => $freshness->version($request->user()),
        ])->header('Cache-Control', 'private, no-store');
    }
}
