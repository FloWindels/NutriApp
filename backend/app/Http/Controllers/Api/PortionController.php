<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Portions;
use Illuminate\Http\JsonResponse;

class PortionController extends Controller
{
    /**
     * GET /portions — catalogue public des unités de portion (mis en cache côté client).
     *
     * Réponse : {data:[{unit, label, label_short, grams|null, step, is_estimate}], aliases:{alias: unit}}
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Portions::catalog(),
            'aliases' => Portions::aliases(),
        ])->header('Cache-Control', 'public, max-age=86400');
    }
}
