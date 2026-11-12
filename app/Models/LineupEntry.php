<?php

namespace App\Models;

use App\Support\MapCache;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One act on a show's bill: a Riff artist, or free text for an act that
 * isn't on Riff.
 *
 * Consent is the point of the status: when a host lists another Riff
 * artist, that entry starts pending and stays off every public surface
 * until that artist's own members confirm. Entries for artists the host
 * belongs to are confirmed at once — membership is the consent.
 *
 * @property int $id
 * @property int $show_id
 * @property int|null $artist_id
 * @property string|null $name
 * @property int $position
 * @property string $status
 * @property Carbon|null $responded_at
 * @property-read string $display_name
 * @property-read Show|null $show
 * @property-read Artist|null $artist
 */
class LineupEntry extends Model
{
    public const CONFIRMED = 'confirmed';

    public const PENDING = 'pending';

    public const DECLINED = 'declined';

    /** Status and responded_at are written only by the lineup code. */
    protected $fillable = ['artist_id', 'name', 'position'];

    protected $casts = [
        'responded_at' => 'datetime',
    ];

    /**
     * A confirm or decline changes a show pin's title, its genre match,
     * and whether a blocking viewer sees it.
     */
    protected static function booted(): void
    {
        foreach (['saved', 'deleted'] as $event) {
            static::$event(fn () => MapCache::bump());
        }
    }

    /** @return BelongsTo<Show, $this> */
    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
    }

    /**
     * Archived artists resolve to null, and their entries drop out of
     * public lineups.
     *
     * @return BelongsTo<Artist, $this>
     */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => $this->artist_id !== null
            ? ($this->artist?->name ?? 'An archived artist')
            : (string) $this->name);
    }
}
