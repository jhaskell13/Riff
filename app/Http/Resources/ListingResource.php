<?php

namespace App\Http\Resources;

use App\Models\Listing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public listing page. Who posted it shows as the artist when posted
 * as one; a person's listing shows their name — never their email or
 * anything beyond the town they chose.
 *
 * @mixin Listing
 */
class ListingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'typeLabel' => $this->type_label,
            'icon' => $this->icon,
            'title' => $this->title,
            'body' => $this->body,
            'instruments' => $this->instrument_labels,
            'genres' => $this->whenLoaded('genres', fn () => $this->genres->pluck('name'), []),
            'area' => $this->area,
            // An artist's listing never falls back to the member's own name,
            // even once the artist is archived.
            'postedBy' => match (true) {
                $this->artist !== null => ['name' => $this->artist->name, 'url' => route('artists.show', $this->artist), 'isArtist' => true],
                $this->artist_id !== null => ['name' => 'An archived artist', 'url' => null, 'isArtist' => true],
                default => ['name' => $this->user?->name ?? 'Someone on Riff', 'url' => null, 'isArtist' => false],
            },
            'postedLabel' => $this->created_at?->diffForHumans(),
            'expiresLabel' => $this->expires_at->isFuture()
                ? 'Open until '.$this->expires_at->isoFormat('MMM D')
                : 'Ended '.$this->expires_at->diffForHumans(),
            'isActive' => $this->is_active,
            'isClosed' => $this->closed_at !== null,
            'url' => route('listings.show', $this->resource),
        ];
    }
}
