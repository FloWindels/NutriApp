<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\User;
use App\Services\Notifications\NotificationBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifications in-app générées à la volée (brief §14).
 */
class NotificationController extends Controller
{
    public const MSG_LUE = 'Notification marquée comme lue.';
    public const MSG_TOUTES_LUES = 'Toutes les notifications sont marquées comme lues.';

    public function __construct(private readonly NotificationBuilder $builder)
    {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $this->builder->build($user);

        return response()->json([
            'data' => NotificationResource::collection($result['data'])->resolve($request),
            'unread_count' => $result['unread_count'],
        ]);
    }

    public function read(Request $request, string $key): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->builder->markRead($user, $key);

        return response()->json([
            'message' => self::MSG_LUE,
            'data' => ['key' => $key, 'read' => true],
        ]);
    }

    public function readAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $marked = $this->builder->markAllRead($user);

        return response()->json([
            'message' => self::MSG_TOUTES_LUES,
            'data' => ['marked_count' => $marked, 'unread_count' => 0],
        ]);
    }
}
