<?php

namespace App\Models;

use App\Contracts\Geocodable;
use App\Observers\GeocodableObserver;
use App\Support\OnboardingIntent;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $city
 * @property string $state
 * @property string $country
 * @property string $distance_unit
 * @property OnboardingIntent|null $onboarding_intent
 * @property bool $is_moderator
 * @property float|null $lat
 * @property float|null $lng
 */
#[ObservedBy([GeocodableObserver::class])]
class User extends Authenticatable implements Geocodable, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'city',
        'state',
        'country',
        'distance_unit',
        'onboarding_intent',
        'lat',
        'lng',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'onboarding_intent' => OnboardingIntent::class,
            // Absent from $fillable on purpose: no request may grant it.
            // See the riff:moderator command.
            'is_moderator' => 'boolean',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    /**
     * Get the artist that owns the User
     */
    /** @return BelongsToMany<Artist, $this> */
    public function artists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class);
    }

    public function geocodableAddress(): array
    {
        return [$this->city, $this->state, $this->country];
    }

    public function geocodableAttributes(): array
    {
        return ['city', 'state', 'country'];
    }

    public function isMemberOf(Artist $artist): bool
    {
        return $this->artists()->whereKey($artist->id)->exists();
    }

    /**
     * Artists this user follows. Following is a listener relationship and
     * carries no permissions -- membership is what grants those.
     */
    /** @return BelongsToMany<Artist, $this> */
    public function followedArtists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class, 'artist_follows')->withTimestamps();
    }

    public function follows(Artist $artist): bool
    {
        return $this->followedArtists()->whereKey($artist->id)->exists();
    }

    /** Artists this listener has hidden from their own map and feed. */
    /** @return BelongsToMany<Artist, $this> */
    public function blockedArtists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class, 'artist_blocks')->withTimestamps();
    }

    public function blocks(Artist $artist): bool
    {
        return $this->blockedArtists()->whereKey($artist->id)->exists();
    }

    /**
     * Whether this listener hides anything at all -- the map asks before
     * deciding if their results can come from the shared cache.
     */
    public function hasBlocks(): bool
    {
        return $this->blockedArtists()->exists();
    }

    /** Reports this user has filed; used only for the duplicate check. */
    public function filedReports(): HasMany
    {
        return $this->hasMany(Report::class, 'reporter_id');
    }

    /**
     * Venues this user added. Create through this relation so the
     * submitter can't be mass-assigned.
     *
     * @return HasMany<Venue, $this>
     */
    public function submittedVenues(): HasMany
    {
        return $this->hasMany(Venue::class, 'submitted_by');
    }

    /**
     * "Looking for" posts this user made. Create through this relation so
     * the poster can't be mass-assigned.
     *
     * @return HasMany<Listing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    /**
     * Guess a distance unit from a stated country. Nearly everywhere uses
     * kilometres, so the imperial list is the exception table.
     */
    public static function distanceUnitForCountry(?string $country): string
    {
        $needle = Str::lower(trim((string) $country));

        return in_array($needle, config('riff.distance.imperial_countries', []), true) ? 'mi' : 'km';
    }

    /**
     * Posts this user wrote, in either voice. Create through this relation
     * so the author can't be mass-assigned.
     *
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * The postLikes that belong to the User
     */
    public function postLikes(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_likes');
    }

    /**
     * Get all of the comments for the User
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function commentLikes(): BelongsToMany
    {
        return $this->belongsToMany(Comment::class, 'comment_likes')->withTimestamps();
    }

    public function hasLikedPost(Post $post): bool
    {
        return $this->postLikes()->where('post_id', $post->id)->exists();
    }

    public function hasLikedComment(Comment $comment): bool
    {
        return $this->commentLikes()->where('comment_id', $comment->id)->exists();
    }
}
