<?php

namespace App\Http\Resources;

use App\Models\Artist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The compact artist payload a map marker needs; the full profile
 * stays behind the profile route.
 *
 * @mixin Artist
 */
class ArtistMarkerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->artist_type,
            'typeLabel' => $this->type_label,
            'genres' => $this->whenLoaded('genres', fn () => $this->genres->pluck('name'), []),
            'location' => $this->hometown.', '.$this->stateOrProvince,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'imageUrl' => $this->image_url,
            // The embed itself is fetched per card; a marker only needs to
            // know whether there is anything to play.
            'hasMusic' => $this->music_provider !== null,
            'url' => route('artists.show', $this->resource),
        ];
    }
}
