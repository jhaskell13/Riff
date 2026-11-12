<?php

namespace App\Models;

use App\Contracts\Geocodable;
use App\Contracts\HasPublicSlug;
use App\Models\Concerns\HasHistoricalSlugs;
use App\Observers\GeocodableObserver;
use App\Support\MapCache;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An established place that hosts music regularly. Community-submitted and
 * moderated after the fact. One-off show locations (a backyard, a park) are
 * never venues: they live on the show, so nothing outlasts the night on the
 * map.
 *
 * A private venue has no street address at all — the saving hook enforces
 * it, whatever a request sent — and is pinned at its town. The fields to
 * render publicly are public_address and is_private; nothing should build
 * an address from the columns directly.
 *
 * @property int $id
 * @property string $name
 * @property string|null $slug
 * @property string $kind
 * @property string $address_visibility
 * @property string|null $street_address
 * @property string $city
 * @property string $state
 * @property string|null $country
 * @property string|null $age_policy
 * @property string|null $website
 * @property string|null $description
 * @property float|null $lat
 * @property float|null $lng
 * @property bool $show_on_map
 * @property int|null $submitted_by
 * @property-read string $kind_label
 * @property-read string|null $age_policy_label
 * @property-read bool $is_private
 * @property-read string $public_address
 * @property-read string $area
 * @property-read User|null $submitter
 */
#[ObservedBy([GeocodableObserver::class])]
class Venue extends Model implements Geocodable, HasPublicSlug
{
    use HasFactory, HasHistoricalSlugs, SoftDeletes;

    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_PRIVATE = 'private';

    public const VISIBILITIES = [self::VISIBILITY_PUBLIC, self::VISIBILITY_PRIVATE];

    /**
     * slug, coordinates, show_on_map and submitted_by are absent on
     * purpose: the first is model-managed, the second geocoder-written, the
     * third a moderator's decision, the last set through the relation.
     */
    protected $fillable = [
        'name',
        'kind',
        'address_visibility',
        'street_address',
        'city',
        'state',
        'country',
        'age_policy',
        'website',
        'description',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'show_on_map' => 'boolean',
    ];

    protected static function booted(): void
    {
        // On the map now (Phase 2 Step 4): any change can change a cached
        // viewport. See MapCache.
        foreach (['saved', 'deleted', 'restored'] as $event) {
            static::$event(fn () => MapCache::bump());
        }

        // Defence in depth for the privacy rule: whatever the request
        // carried, a private venue never stores a street. Runs before the
        // geocoding observer's updating hook sees the dirty attributes, so
        // going private also re-places the pin at town level.
        static::saving(function (self $venue): void {
            if ($venue->address_visibility === self::VISIBILITY_PRIVATE) {
                $venue->street_address = null;
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return HasMany<Show, $this> */
    public function shows(): HasMany
    {
        return $this->hasMany(Show::class);
    }

    /** @return MorphMany<Report, $this> */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'reportable');
    }

    /** @return HasMany<VenueSlug, $this> */
    public function slugHistory(): HasMany
    {
        return $this->hasMany(VenueSlug::class);
    }

    public function slugFallback(): string
    {
        return 'venue';
    }

    /**
     * Street-level only for a public venue that gave a street. Everything
     * else is placed at its town's centre, like artists are.
     */
    public function geocodableAddress(): array
    {
        $address = [$this->city, $this->state, $this->country];

        if (! $this->is_private && filled($this->street_address)) {
            $address[] = $this->street_address;
        }

        return $address;
    }

    public function geocodableAttributes(): array
    {
        return ['street_address', 'city', 'state', 'country', 'address_visibility'];
    }

    protected function isPrivate(): Attribute
    {
        return Attribute::get(fn (): bool => $this->address_visibility === self::VISIBILITY_PRIVATE);
    }

    /**
     * "Hartford, CT".
     */
    protected function area(): Attribute
    {
        return Attribute::get(fn (): string => collect([$this->city, $this->state])->filter()->implode(', '));
    }

    /**
     * What may be shown to anyone: the street for a public venue, the town
     * alone for a private one.
     */
    protected function publicAddress(): Attribute
    {
        return Attribute::get(fn (): string => $this->is_private || blank($this->street_address)
            ? $this->area
            : $this->street_address.', '.$this->area);
    }

    protected function kindLabel(): Attribute
    {
        return Attribute::get(fn (): string => config('riff.venues.kinds')[$this->kind] ?? 'Venue');
    }

    protected function agePolicyLabel(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->age_policy === null
            ? null
            : (config('riff.age_policies')[$this->age_policy] ?? null));
    }
}
