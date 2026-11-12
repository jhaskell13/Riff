<?php

namespace App\Models;

use App\Support\AuthorContext;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A post on an artist's wall, in one of two voices.
 *
 * artist_id is the *wall* (whose page this appears on); author_context is
 * the *voice* (the artist, or a fan); user_id is who actually typed it and
 * is never published for an artist-voice post. See App\Support\AuthorContext.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $artist_id
 * @property AuthorContext $author_context
 * @property string $content
 * @property string $status
 * @property Carbon|null $published_at
 * @property-read User|null $user
 * @property-read Artist|null $artist
 */
class Post extends Model
{
    use HasFactory;

    /**
     * author_context is fillable but is never a validation rule, so
     * ...$request->validated() cannot carry it -- the controller sets it
     * from the authorization result. Same arrangement as status.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'artist_id',
        'author_context',
        'content',
        'media',
        'status',
        'published_at',
        'meta_data',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'meta_data' => 'array',
        'author_context' => AuthorContext::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The wall this post lives on. */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    /**
     * Polymorphic since Phase 3: shows get threads of their own, and a
     * phantom post row per show would leak into every post query.
     *
     * @return MorphMany<Comment, $this>
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /** @return MorphMany<Report, $this> */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'reportable');
    }

    /**
     * Named likers, not likes: the posts table has an integer likes
     * column, and a relation with the same name would be shadowed by it.
     */
    public function likers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'post_likes')->withTimestamps();
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Everything on one artist's page, in both voices. Reads the
     * posts_wall_index.
     */
    public function scopeOnWall(Builder $query, Artist $artist): Builder
    {
        return $query->where('artist_id', $artist->id);
    }

    public function scopeInVoice(Builder $query, AuthorContext $context): Builder
    {
        return $query->where('author_context', $context->value);
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
