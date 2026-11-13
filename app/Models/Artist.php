<?php

namespace App\Models;

use App\Contracts\Geocodable;
use App\Contracts\HasPublicSlug;
use App\Models\Concerns\HasHistoricalSlugs;
use App\Observers\GeocodableObserver;
use App\Services\MusicLink;
use App\Support\MapCache;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $name
 * @property string|null $slug
 * @property string $artist_type
 * @property string $hometown
 * @property string $stateOrProvince
 * @property string $country
 * @property string|null $biography
 * @property string|null $music_url
 * @property string|null $music_provider
 * @property string|null $image_path
 * @property bool $show_on_map
 * @property string $wall_mode
 * @property float|null $lat
 * @property float|null $lng
 * @property-read string|null $image_url
 * @property-read string|null $type_label
 * @property-read array<string, mixed>|null $music_embed
 * @property-read string $address
 */
#[ObservedBy([GeocodableObserver::class])]
class Artist extends Model implements Geocodable, HasPublicSlug
{
    use HasFactory, HasHistoricalSlugs, SoftDeletes;

    /**
     * image_path and music_provider are deliberately absent: the first is
     * written only after an upload is processed, the second is derived from
     * music_url. Neither may be set from a request.
     */
    protected $fillable = [
        'name',
        'artist_type',
        'hometown',
        'stateOrProvince',
        'country',
        'biography',
        'website',
        'bandcamp_url',
        'spotify_url',
        'soundcloud_url',
        'youtube_url',
        'instagram_url',
        'email',
        'music_url',
        'show_on_map',
        'wall_mode',
        'formed_date',
        'lat',
        'lng',
    ];

    protected $casts = [
        'formed_date' => 'date',
        'lat' => 'float',
        'lng' => 'float',
        'show_on_map' => 'boolean',
    ];

    /**
     * image_url replaced a column of the same name, so every page that used
     * to read it keeps working.
     */
    protected $appends = ['image_url'];

    /**
     * Any change to an artist can change what a map viewport shows, so it
     * invalidates every cached map result. Coordinates written by the
     * geocoder bypass these events and bump the cache themselves.
     */
    protected static function booted(): void
    {
        foreach (['saved', 'deleted', 'restored'] as $event) {
            static::$event(fn () => MapCache::bump());
        }
    }

    /**
     * Shows this artist's members listed. Shows it merely plays are
     * Show::featuring($artist).
     *
     * @return HasMany<Show, $this>
     */
    public function hostedShows(): HasMany
    {
        return $this->hasMany(Show::class, 'host_artist_id');
    }

    /** @return HasMany<ArtistSlug, $this> */
    public function slugHistory(): HasMany
    {
        return $this->hasMany(ArtistSlug::class);
    }

    /**
     * "1994" the band becomes 1994-band.
     */
    public function slugFallback(): string
    {
        return $this->artist_type ?: 'artist';
    }

    /**
     * Membership — not following — grants the authority to speak as the
     * artist.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * Listeners following this artist. Counted with withCount('followers');
     * there is intentionally no followers_count column to shadow it.
     *
     * @return BelongsToMany<User, $this>
     */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'artist_follows')
            ->withPivot('notify')
            ->withTimestamps();
    }

    /**
     * Both voices; filter with Post::inVoice().
     *
     * @return HasMany<Post, $this>
     */
    public function wallPosts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * A ban is about the wall, not the fandom: they keep following and
     * keep reading.
     *
     * @return HasMany<ArtistWallBan, $this>
     */
    public function wallBans(): HasMany
    {
        return $this->hasMany(ArtistWallBan::class);
    }

    /** @return HasMany<WallModerationEvent, $this> */
    public function wallModerationEvents(): HasMany
    {
        return $this->hasMany(WallModerationEvent::class);
    }

    /**
     * Riff links out and takes no cut (principle 4).
     *
     * @return HasMany<ArtistSupportLink, $this>
     */
    public function supportLinks(): HasMany
    {
        return $this->hasMany(ArtistSupportLink::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Listeners who hid this artist from their own map; never shown to them.
     *
     * @return BelongsToMany<User, $this>
     */
    public function blockers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'artist_blocks')->withTimestamps();
    }

    /** @return MorphMany<Report, $this> */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'reportable');
    }

    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class);
    }

    public function geocodableAddress(): array
    {
        return [$this->hometown, $this->stateOrProvince, $this->country];
    }

    public function geocodableAttributes(): array
    {
        return ['hometown', 'stateOrProvince', 'country'];
    }

    /**
     * Setting music_url re-derives the provider, and normalises the link to
     * the provider's canonical form. An unrecognised link clears both,
     * which validation surfaces before it can reach here.
     */
    protected function musicUrl(): Attribute
    {
        return Attribute::make(
            set: function (?string $value) {
                $embed = MusicLink::parse($value);

                return [
                    'music_url' => $embed?->url,
                    'music_provider' => $embed?->provider,
                ];
            },
        );
    }

    /**
     * The player a profile should render, or null when the artist has no
     * recognised link. Rebuilt from the stored canonical URL rather than
     * persisted, so a fix to MusicLink applies to existing profiles.
     */
    protected function musicEmbed(): Attribute
    {
        return Attribute::get(fn () => MusicLink::parse($this->attributes['music_url'] ?? null)?->toArray());
    }

    /**
     * Public URL of the uploaded photo on the configured media disk.
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(function () {
            $path = $this->attributes['image_path'] ?? null;

            return $path ? Storage::disk(config('riff.media.disk'))->url($path) : null;
        });
    }

    protected function typeLabel(): Attribute
    {
        return Attribute::get(fn () => config('riff.artist.types')[$this->artist_type] ?? null);
    }

    public function scopeGeolocated(Builder $query): Builder
    {
        return $query->whereNotNull('lat')->whereNotNull('lng');
    }

    /**
     * Artists who have not opted their pin off the map.
     */
    public function scopeVisibleOnMap(Builder $query): Builder
    {
        return $query->where('show_on_map', true);
    }

    /**
     * Artists inside a map viewport. Viewports crossing the antimeridian
     * are not supported; acceptable until Riff hosts artists near it.
     */
    public function scopeWithinBounds(Builder $query, float $south, float $west, float $north, float $east): Builder
    {
        return $query
            ->whereBetween('lat', [$south, $north])
            ->whereBetween('lng', [$west, $east]);
    }

    public function getAddressAttribute(): string
    {
        return $this->hometown.', '.$this->stateOrProvince.', '.$this->country;
    }
}
