<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recommendation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'date',
        'type',
        'dedupe_key',
        'title',
        'message',
        'factors',
        'actions',
        'priority',
        'status',
        'is_estimate',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'date' => 'date:Y-m-d',
        'factors' => 'array',
        'actions' => 'array',
        'priority' => 'integer',
        'is_estimate' => 'boolean',
    ];

    protected $attributes = [
        'priority' => 3,
        'status' => 'new',
        'is_estimate' => false,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Clé de dédoublonnage stable : sha1(type|title).
     */
    public static function dedupeKey(string $type, string $title): string
    {
        return sha1($type.'|'.$title);
    }
}
