<?php

namespace App\Http\Resources;

use App\Models\Listing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * A listing as a map pin, list row, and card. Placed at its town's centre.
 *
 * @mixin Listing
 */
class ListingMarkerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'kind' => 'listing',
            'id' => $this->id,
            'title' => $this->title,
            'typeLabel' => $this->type_label,
            'icon' => $this->icon,
            'excerpt' => Str::limit($this->body, 160, '…'),
            'instruments' => $this->instrument_labels,
            'postedBy' => $this->artist?->name,
            'area' => $this->area,
            'postedLabel' => $this->created_at?->diffForHumans(),
            'lat' => $this->lat,
            'lng' => $this->lng,
            'url' => route('listings.show', $this->resource),
        ];
    }
}
