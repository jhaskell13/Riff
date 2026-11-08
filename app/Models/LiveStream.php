<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveStream extends Model
{
    protected $fillable = [
        'artist_id',
        'title',
        'description',
        'status',
        'viewer_count',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    /**
     * Get the artist that owns the LiveStream
     */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }
}
