<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Notification générée à la volée par NotificationBuilder (brief §14).
 *
 * @property array{key: string, type: string, title: string, message: string, date: string, read: bool, action: array<string, mixed>|null} $resource
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => (string) $this->resource['key'],
            'type' => (string) $this->resource['type'],
            'title' => (string) $this->resource['title'],
            'message' => (string) $this->resource['message'],
            'date' => (string) $this->resource['date'],
            'read' => (bool) $this->resource['read'],
            'action' => $this->resource['action'] ?? null,
        ];
    }
}
