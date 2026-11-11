<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A slug an artist used before a rename. Rows are written only by the
 * Artist model's own rename handling — never from a request — and exist so
 * old public profile URLs keep redirecting to the current one.
 *
 * @property int $id
 * @property int $artist_id
 * @property string $slug
 * @property-read Artist|null $artist
 */
class ArtistSlug extends Model
{
    protected $fillable = ['slug'];

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }
}
