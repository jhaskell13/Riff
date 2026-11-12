<?php

namespace App\Models;

use App\Contracts\Geocodable;
use App\Observers\GeocodableObserver;
use App\Support\MapCache;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
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
 * A "looking for" post: local, tagged, and expiring. Placed at its town's
 * centre (town-level geocoding, never a street).
 *
 * user_id, artist_id, expires_at and closed_at are not fillable: the
 * poster comes from the relation, the artist only after a membership
 * check, and the dates from the post / renew / close actions.
 *
 * @property int $id
 * @property string $type
 * @property int $user_id
 * @property int|null $artist_id
 * @property string $title
 * @property string $body
 * @property list<string>|null $instruments
 * @property string $city
 * @property string $state
 * @property string|null $country
 * @property float|null $lat
 * @property float|null $lng
 * @property Carbon $expires_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property-read string $type_label
 * @property-read string $icon
 * @property-read string $area
 * @property-read bool $is_active
 * @property-read bool $can_renew
 * @property-read list<string> $instrument_labels
 * @property-read User|null $user
 * @property-read Artist|null $artist
 */
#[ObservedBy([GeocodableObserver::class])]
class Listing extends Model implements Geocodable
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['type', 'title', 'body', 'instruments', 'city', 'state', 'country'];

    protected $casts = [
        'instruments' => 'array',
        'expires_at' => 'datetime',
        'closed_at' => 'datetime',
        'lat' => 'float',
        'lng' => 'float',
    ];

    protected static function booted(): void
    {
        foreach (['saved', 'deleted', 'restored'] as $event) {
            static::$event(fn () => MapCache::bump());
        }
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Artist, $this> */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    /** @return BelongsToMany<Genre, $this> */
    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class, 'listing_genre');
    }

    /** @return HasMany<ListingReply, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(ListingReply::class);
    }

    /** @return MorphMany<Report, $this> */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'reportable');
    }

    /**
     * Shown anywhere public: open, not expired, and not left behind by an
     * archived artist. Expiry is a query concern, not a cron job.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('closed_at')
            ->where('expires_at', '>', now()->toDateTimeString())
            ->where(fn (Builder $q) => $q->whereNull('artist_id')->orWhereHas('artist'));
    }

    /** Listings this user may manage: their own, and their artists'. */
    public function scopeManagedBy(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('user_id', $user->id)
            ->orWhereIn('artist_id', $user->artists()->select('artists.id')));
    }

    public function isManagedBy(User $user): bool
    {
        return $this->user_id === $user->id
            || ($this->artist !== null && $user->isMemberOf($this->artist));
    }

    public function geocodableAddress(): array
    {
        return [$this->city, $this->state, $this->country];
    }

    public function geocodableAttributes(): array
    {
        return ['city', 'state', 'country'];
    }

    /** @return array{label: string, as_artist: string, icon: string}|null */
    public static function typeConfig(string $type): ?array
    {
        return config('riff.listings.types')[$type] ?? null;
    }

    protected function typeLabel(): Attribute
    {
        return Attribute::get(fn (): string => self::typeConfig($this->type)['label'] ?? 'Listing');
    }

    protected function icon(): Attribute
    {
        return Attribute::get(fn (): string => self::typeConfig($this->type)['icon'] ?? 'campaign');
    }

    protected function area(): Attribute
    {
        return Attribute::get(fn (): string => collect([$this->city, $this->state])->filter()->implode(', '));
    }

    protected function isActive(): Attribute
    {
        return Attribute::get(fn (): bool => $this->closed_at === null && $this->expires_at->isFuture());
    }

    /** Within the renew window of ending, or already ended (not closed). */
    protected function canRenew(): Attribute
    {
        return Attribute::get(fn (): bool => $this->closed_at === null
            && $this->expires_at->lte(now()->addDays((int) config('riff.listings.renew_window_days'))));
    }

    protected function instrumentLabels(): Attribute
    {
        $labels = (array) config('riff.listings.instruments');

        return Attribute::get(fn (): array => array_map(
            fn (string $key) => $labels[$key] ?? $key,
            $this->instruments ?? [],
        ));
    }
}
