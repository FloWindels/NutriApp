<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminStatsService;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/admin/stats → {data} — uniquement des agrégats, jamais une ligne nominative.
 */
class AdminStatsController extends Controller
{
    public function index(AdminStatsService $stats): JsonResponse
    {
        return response()->json(['data' => $stats->build()]);
    }
}
