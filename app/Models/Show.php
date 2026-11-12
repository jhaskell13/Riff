<?php

namespace App\Models;

use App\Contracts\Geocodable;
use App\Jobs\GeocodeAddress;
use App\Support\MapCache;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A dated show: a lineup, and either a venue or a one-off location of its
 * own. Listed and managed by the members of its host artist.
 *
 * A one-off location is a label, a town and a note — never a street, never
 * a venue row — and is geocoded at town level. A venue show is placed by
 * its venue, so its own location columns are cleared on save.
 *
 * Not observed by GeocodableObserver: only one-off shows need geocoding,
 * so booted() carries the same three hooks with that condition.
 *
 * @property int $id
 * @property string|null $title
 * @property int|null $host_artist_id
 * @property int|null $created_by
 * @property int|null $venue_id
 * @property string|null $location_name
 * @property string|null $city
 * @property string|null $state
 * @property string|null $country
 * @property string|null $location_note
 * @property float|null $lat
 * @property float|null $lng
 * @property Carbon $starts_at
 * @property Carbon|null $doors_at
 * @property string $timezone
 * @property string|null $price
 * @property string|null $ticket_url
 * @property string|null $age_policy
 * @property string|null $description
 * @property string $source
 * @property string|null $provider
 * @property string|null $provider_ref
 * @property Carbon|null $cancelled_at
 * @property-read bool $is_one_off
 * @property-read bool $is_cancelled
 * @property-read bool $has_passed
 * @property-read string|null $area
 * @property-read string|null $effective_age_policy
 * @property-read Artist|null $hostArtist
 * @property-read Venue|null $venue
 */
class Show extends Model implements Geocodable
{
    use HasFactory, SoftDeletes;

    public const SOURCE_MEMBER = 'member';

    public const SOURCE_IMPORTED = 'imported';

    /**
     * host_artist_id, created_by, source/provider fields, coordinates and
     * cancelled_at are absent on purpose: set by the controller, the
     * geocoder, or the cancel action — never from a request.
     */
    protected $fillable = [
        'title',
        'venue_id',
        'location_name',
        'city',
        'state',
        'country',
        'location_note',
        'starts_at',
        'doors_at',
        'timezone',
        'price',
        'ticket_url',
        'age_policy',
        'description',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'doors_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'lat' => 'float',
        'lng' => 'float',
    ];

    protected static function booted(): void
    {
        // On the map now (Phase 2 Step 4): any change can change a cached
        // viewport. See MapCache.
        foreach (['saved', 'deleted', 'restored'] as $event) {
            static::$event(fn () => MapCache::bump());
        }

        // A show is at a venue or at its own one-off location, never both:
        // whatever the request sent, a venue show keeps no location of its
        // own (and so nothing stale to put on the map).
        static::saving(function (self $show): void {
            if ($show->venue_id !== null) {
                $show->forceFill([
                    'location_name' => null, 'city' => null, 'state' => null,
                    'country' => null, 'location_note' => null, 'lat' => null, 'lng' => null,
                ]);
            }
        });

        // GeocodableObserver's rules, applied to one-off locations only.
        static::updating(function (self $show): void {
            if ($show->venue_id === null && $show->isDirty($show->geocodableAttributes())) {
                $show->forceFill(['lat' => null, 'lng' => null]);
            }
        });

        static::created(function (self $show): void {
            if ($show->is_one_off && ($show->lat === null || $show->lng === null)) {
                GeocodeAddress::dispatch($show);
            }
        });

        static::updated(function (self $show): void {
            if ($show->is_one_off && $show->wasChanged($show->geocodableAttributes())) {
                GeocodeAddress::dispatch($show);
            }
        });
    }

    // ---- Relations ----------------------------------------------------------

    /** @return BelongsTo<Artist, $this> */
    public function hostArtist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'host_artist_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Venue, $this> */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /**
     * Every entry, in bill order, whatever its status. Public surfaces
     * must use confirmedLineup().
     *
     * @return HasMany<LineupEntry, $this>
     */
    public function lineup(): HasMany
    {
        return $this->hasMany(LineupEntry::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<LineupEntry, $this> */
    public function confirmedLineup(): HasMany
    {
        return $this->lineup()->where('status', LineupEntry::CONFIRMED);
    }

    /**
     * Fans going or interested. Private by default: the pivot's is_public
     * says whether a name may be shown; counts are always fine.
     *
     * @return BelongsToMany<User, $this>
     */
    public function attendees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'show_rsvps')
            ->withPivot(['status', 'is_public'])
            ->withTimestamps();
    }

    /** @return MorphMany<Report, $this> */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'reportable');
    }

    // ---- Scopes ---------------------------------------------------------------

    /**
     * Shows anyone may see listed: not cancelled, and not left behind by an
     * archived host. (Moderator-removed shows are soft-deleted, so the
     * default scope already drops them.)
     */
    public function scopeListed(Builder $query): Builder
    {
        return $query
            ->whereNull('cancelled_at')
            ->where(fn (Builder $q) => $q
                ->whereNull('host_artist_id')
                ->orWhereHas('hostArtist'));
    }

    /**
     * Still worth listing: starting later, or started within the last few
     * hours (tonight's show is still on after the doors open).
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $this->scopeNotOver($query)->orderBy('starts_at')->orderBy('id');
    }

    /** upcoming() without the ordering, for use inside whereHas(). */
    public function scopeNotOver(Builder $query): Builder
    {
        return $query->where('starts_at', '>=', now()->subHours((int) config('riff.shows.still_on_hours')));
    }

    /** Shows this artist has confirmed playing. */
    public function scopeFeaturing(Builder $query, Artist $artist): Builder
    {
        return $query->whereHas('lineup', fn (Builder $q) => $q
            ->where('artist_id', $artist->id)
            ->where('status', LineupEntry::CONFIRMED));
    }

    // ---- Geocodable (one-off locations only) --------------------------------

    public function geocodableAddress(): array
    {
        return [(string) $this->city, (string) $this->state, $this->country];
    }

    public function geocodableAttributes(): array
    {
        return ['city', 'state', 'country', 'venue_id'];
    }

    // ---- Accessors ------------------------------------------------------------

    protected function isOneOff(): Attribute
    {
        return Attribute::get(fn (): bool => $this->venue_id === null);
    }

    protected function isCancelled(): Attribute
    {
        return Attribute::get(fn (): bool => $this->cancelled_at !== null);
    }

    protected function hasPassed(): Attribute
    {
        return Attribute::get(fn (): bool => $this->starts_at->lt(
            now()->subHours((int) config('riff.shows.still_on_hours'))
        ));
    }

    /**
     * "Hartford, CT" for a one-off location; the venue's area otherwise.
     */
    protected function area(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->venue_id !== null
            ? $this->venue?->area
            : (collect([$this->city, $this->state])->filter()->implode(', ') ?: null));
    }

    protected function effectiveAgePolicy(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->age_policy ?? $this->venue?->age_policy);
    }

    /**
     * The start, in the show's own timezone.
     */
    public function localStart(): CarbonImmutable
    {
        return CarbonImmutable::instance($this->starts_at)->setTimezone($this->timezone);
    }

    public function localDoors(): ?CarbonImmutable
    {
        return $this->doors_at === null
            ? null
            : CarbonImmutable::instance($this->doors_at)->setTimezone($this->timezone);
    }
}
