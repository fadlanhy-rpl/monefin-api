<?php

namespace App\Http\Controllers;

use App\Services\SmartInsightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmartInsightController extends Controller
{
    private const VALID_PAGES = ['dashboard', 'categories', 'budgets', 'accounts', 'goals'];

    public function __construct(private readonly SmartInsightService $service) {}

    /**
     * GET /api/smart-insights/{page}
     */
    public function show(Request $request, string $page): JsonResponse
    {
        if (!in_array($page, self::VALID_PAGES, true)) {
            return response()->json(['message' => "Page '{$page}' tidak dikenali."], 422);
        }

        $lang    = $request->header('Accept-Language') ?? ($request->user()?->preferences['language'] ?? 'id');
        $insight = $this->service->getInsight($request->user(), $page, $lang);

        return response()->json(['data' => $insight]);
    }
}
