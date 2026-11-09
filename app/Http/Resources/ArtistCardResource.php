<?php

namespace App\Http\Resources;

use App\DTO\MapPoint;
use App\Models\Artist;
use App\Support\Geo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * What the map card shows once a listener picks an artist: enough to decide
 * whether to listen, follow, or open the profile. Loaded per card rather
 * than per marker, so the embed and follow state never inflate a viewport
 * response.
 *
 * @mixin Artist
 */
class ArtistCardResource extends JsonResource
{
    private ?MapPoint $origin = null;

    private string $unit = 'mi';

    public function withDistanceFrom(?MapPoint $origin, string $unit): static
    {
        $this->origin = $origin;
        $this->unit = $unit;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $distance = null;

        if ($this->origin !== null) {
            $km = Geo::haversineKm($this->origin->lat, $this->origin->lng, $this->lat, $this->lng);
            $distance = round(Geo::fromKm($km, $this->unit), 1);
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->artist_type,
            'typeLabel' => $this->type_label,
            'genres' => $this->genres->pluck('name'),
            'location' => $this->hometown.', '.$this->stateOrProvince,
            'imageUrl' => $this->image_url,
            // 279 plus a one-character ellipsis: the card never exceeds 280.
            'bio' => $this->biography ? Str::limit($this->biography, 279, '…') : null,
            'music' => $this->music_embed,
            'followersCount' => (int) $this->followers_count,
            // Null for guests: there is no follow state to show, and the
            // client offers "sign in to follow" instead.
            'isFollowing' => $request->user()?->follows($this->resource),
            'distance' => $distance,
            'unit' => $this->unit,
            'url' => route('artists.show', $this->resource),
        ];
    }
}
