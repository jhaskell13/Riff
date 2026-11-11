<?php

namespace App\Http\Resources;

use App\Models\Venue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public shape of a venue. The address goes out only as
 * public_address — street for a public venue, town for a private one — and
 * the raw street column is never serialised here, so no page built on this
 * resource can show a private venue's location by accident.
 *
 * @mixin Venue
 */
class VenueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'kind' => $this->kind,
            'kindLabel' => $this->kind_label,
            'isPrivate' => $this->is_private,
            'address' => $this->public_address,
            'area' => $this->area,
            'agePolicy' => $this->age_policy,
            'agePolicyLabel' => $this->age_policy_label,
            'website' => $this->website,
            'description' => $this->description,
            'showOnMap' => $this->show_on_map,
            // A town-level point for a private venue: it was only ever
            // geocoded from the town.
            'lat' => $this->lat,
            'lng' => $this->lng,
            'url' => route('venues.show', $this->resource),
        ];
    }
}
