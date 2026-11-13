<?php

namespace App\Models;

use App\Support\WallAction;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One entry in an artist's wall-moderation trail.
 *
 * Append-only: a hide and the unhide that reverses it are two rows, never
 * one row edited. An artist who hid the wrong comment needs to be able to
 * see what they did and when, and a trail that rewrites itself answers
 * neither question.
 *
 * @property int $id
 * @property int $artist_id
 * @property int|null $actor_id
 * @property WallAction $action
 * @property string $subject_type
 * @property int $subject_id
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property-read User|null $actor
 */
class WallModerationEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'actor_id',
        'action',
        'note',
    ];

    protected $casts = [
        'action' => WallAction::class,
    ];

    /** @return BelongsTo<Artist, $this> */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * The post, comment, or (for a ban) listener acted on. withTrashed is
     * not needed: none of these are soft-deleted by this feature, which is
     * the point of it.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
