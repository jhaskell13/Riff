<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A slug a venue used before a rename; see HasHistoricalSlugs. Written only
 * by the model's rename handling, never from a request.
 *
 * @property int $id
 * @property int $venue_id
 * @property string $slug
 * @property-read Venue|null $venue
 */
class VenueSlug extends Model
{
    protected $fillable = ['slug'];

    /** @return BelongsTo<Venue, $this> */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }
}
