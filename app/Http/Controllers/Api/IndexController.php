<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Misc client-facing endpoints that need no auth and no models.
 * Part of the RustDesk client contract (docs/modernization/02-client-api-contract.md).
 */
class IndexController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => 'rustdesk-api']);
    }

    public function version(): JsonResponse
    {
        // The client reads this to display/compare the server version.
        return response()->json(['version' => (string) config('app.version')]);
    }
}
