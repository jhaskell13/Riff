<?php

namespace App\Models;

use App\Services\SupportLink;
use Database\Factories\ArtistSupportLinkFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "support this artist" link: where the artist already gets paid.
 *
 * @property int $id
 * @property int $artist_id
 * @property string $type
 * @property string $url
 * @property string|null $label
 * @property int $position
 * @property int $clicks
 * @property-read string $type_label
 * @property-read string $display_label
 * @property-read string $icon
 * @property-read string|null $host
 */
class ArtistSupportLink extends Model
{
    /** @use HasFactory<ArtistSupportLinkFactory> */
    use HasFactory;

    /**
     * artist_id and clicks are deliberately absent: links are created
     * through $artist->supportLinks(), and a request that could set the
     * click count would make the one number Phase 4 relies on worthless.
     *
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'url',
        'label',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
        'clicks' => 'integer',
    ];

    /** @return BelongsTo<Artist, $this> */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    /**
     * There is deliberately no url mutator here, unlike Artist::music_url.
     * Canonicalising a support link needs its *type* as well as its URL,
     * and a mutator reading a sibling attribute only works when that
     * sibling happens to be filled first -- fill() follows the order of the
     * incoming array, not $fillable, so it would be correct by luck.
     * Canonicalisation lives in App\Services\ArtistSupportLinks instead,
     * which has both values in hand, and validation (App\Rules\SupportUrl)
     * rejects anything the parser refuses before that.
     */
    protected function typeLabel(): Attribute
    {
        return Attribute::get(fn () => SupportLink::label($this->type) ?? 'Support');
    }

    /** The artist's own label where they gave one, else the type's. */
    protected function displayLabel(): Attribute
    {
        return Attribute::get(fn () => $this->label !== null && trim($this->label) !== ''
            ? trim($this->label)
            : $this->type_label);
    }

    protected function icon(): Attribute
    {
        return Attribute::get(fn () => SupportLink::icon($this->type));
    }

    /** Shown beside the link so nobody clicks blind. */
    protected function host(): Attribute
    {
        return Attribute::get(fn () => parse_url($this->url, PHP_URL_HOST) ?: null);
    }
}
