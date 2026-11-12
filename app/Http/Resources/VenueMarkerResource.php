<?php

namespace App\Http\Resources;

use App\Models\Venue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A venue as a map pin and list row. A private venue's point is its town
 * (it was only ever geocoded from the town), and its street never leaves
 * the server: only `area` is sent.
 *
 * @mixin Venue
 */
class VenueMarkerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'kind' => 'venue',
            'id' => $this->id,
            'name' => $this->name,
            'url' => route('venues.show', $this->resource),
            'lat' => $this->lat,
            'lng' => $this->lng,
            'venueKind' => $this->kind,
            'kindLabel' => $this->kind_label,
            'area' => $this->area,
            'isPrivate' => $this->is_private,
            'agePolicyLabel' => $this->age_policy_label,
            'upcomingCount' => (int) ($this->getAttribute('upcoming_count') ?? 0),
        ];
    }
}
