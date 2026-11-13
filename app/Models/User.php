<?php

namespace App\Models;

use App\Contracts\Geocodable;
use App\Observers\GeocodableObserver;
use App\Support\NotificationType;
use App\Support\OnboardingIntent;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
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
 * @property array<string, bool>|null $notification_preferences
 * @property string $digest_frequency
 * @property Carbon|null $digest_sent_at
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
        'notification_preferences',
        'digest_frequency',
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
            'notification_preferences' => 'array',
            'digest_sent_at' => 'datetime',
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
        return $this->belongsToMany(Artist::class, 'artist_follows')
            // notify is the per-artist mute; see wantsNotificationsFrom().
            ->withPivot('notify')
            ->withTimestamps();
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

    /**
     * Whether this account receives a kind of notification.
     *
     * Null preferences mean "never chosen", so the config defaults apply:
     * a new account is not silently opted out of everything. A kind marked
     * `required` cannot be switched off -- turning the lineup invitation
     * off would quietly break other people's listings.
     */
    public function wantsNotification(NotificationType $type): bool
    {
        if ($type->config()['required'] ?? false) {
            return true;
        }

        $preferences = $this->notification_preferences;

        if (! is_array($preferences) || ! array_key_exists($type->value, $preferences)) {
            return $type->onByDefault();
        }

        return (bool) $preferences[$type->value];
    }

    /**
     * Whether this listener still wants to hear from one particular
     * artist. Following a band you love should not mean the same volume as
     * following one you are curious about, so the dial is on the follow.
     */
    public function wantsNotificationsFrom(Artist $artist): bool
    {
        $follow = $this->followedArtists()
            ->whereKey($artist->id)
            ->first();

        // Not following at all: nothing to mute, and nothing arrives by
        // way of a follow either.
        return $follow === null || (bool) $follow->pivot->getAttribute('notify');
    }

    /** Walls this listener may no longer post on. */
    /** @return BelongsToMany<Artist, $this> */
    public function bannedFromWalls(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class, 'artist_wall_bans')->withTimestamps();
    }

    public function isBannedFromWall(Artist $artist): bool
    {
        return $this->bannedFromWalls()->whereKey($artist->id)->exists();
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
