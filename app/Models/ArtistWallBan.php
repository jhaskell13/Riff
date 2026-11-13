<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A listener who may no longer post on one artist's wall.
 *
 * A ban is about the wall, not the fandom: they keep following, keep
 * reading, and keep whatever they already wrote (hiding that is a separate
 * decision). Lifting it deletes the row; the trail remembers it happened.
 *
 * @property int $id
 * @property int $artist_id
 * @property int $user_id
 * @property int|null $banned_by
 * @property string|null $reason
 * @property Carbon|null $created_at
 */
class ArtistWallBan extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'banned_by',
        'reason',
    ];

    /** @return BelongsTo<Artist, $this> */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
