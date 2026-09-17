<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marque de lecture d'une notification générée à la volée. Clé primaire composite
 * (user_id, key) : utiliser des requêtes `where(...)` plutôt que `find()`.
 */
class NotificationRead extends Model
{
    use HasFactory;

    protected $table = 'notification_reads';

    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'key',
        'read_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
