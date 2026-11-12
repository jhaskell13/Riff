<?php

namespace App\Models;

use App\Support\AuthorContext;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A reply on a wall post (and, from step 5, on a show thread).
 *
 * Carries a voice like a post does: a member answering as the band is the
 * clearest signal a wall has, so it is stored, not inferred.
 *
 * @property int $id
 * @property string $commentable_type
 * @property int $commentable_id
 * @property int $user_id
 * @property AuthorContext $author_context
 * @property int|null $artist_id
 * @property int|null $parent_id
 * @property string $content
 * @property string $status
 * @property int $likes
 * @property Carbon|null $created_at
 * @property-read User|null $user
 * @property-read Artist|null $artist
 */
class Comment extends Model
{
    use HasFactory;

    public const PUBLISHED = 'published';

    /** Hidden by the artist whose page it is. Recoverable. */
    public const HIDDEN = 'hidden';

    /** Removed by a platform moderator. The row stays for the audit trail. */
    public const REMOVED = 'removed';

    /**
     * author_context and artist_id are fillable but never validation
     * rules: the controller sets both from the authorization result, so a
     * request can't choose to speak as the band.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'author_context',
        'artist_id',
        'parent_id',
        'content',
        'status',
    ];

    protected $casts = [
        'author_context' => AuthorContext::class,
        'likes' => 'integer',
    ];

    /** The post or show being discussed. */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Which artist is speaking, when the voice is the artist's. */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Comment, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return MorphMany<Report, $this> */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'reportable');
    }

    /**
     * Named likers, not likes: comments carry an integer likes column.
     */
    public function likers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'comment_likes')->withTimestamps();
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::PUBLISHED);
    }

    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function isArtistVoice(): bool
    {
        return $this->author_context === AuthorContext::Artist;
    }

    public function isLikedBy(User $user): bool
    {
        return $this->likers()->whereKey($user->id)->exists();
    }
}
