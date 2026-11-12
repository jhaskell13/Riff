<?php

namespace App\Http\Resources;

use App\Models\Show;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A show as a map pin and list row. The point is the venue's (or, for a
 * one-off, the show's own town-level point), selected by SceneMapSearch as
 * map_lat / map_lng. Times are in the show's own timezone.
 *
 * @mixin Show
 */
class ShowMarkerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $start = $this->localStart();

        return [
            'kind' => 'show',
            'id' => $this->id,
            'title' => ShowCardResource::displayTitle($this->resource),
            'url' => route('shows.show', $this->resource),
            'lat' => (float) $this->getAttribute('map_lat'),
            'lng' => (float) $this->getAttribute('map_lng'),
            'startsAt' => $start->toIso8601String(),
            'dateLabel' => $start->isoFormat('ddd, MMM D'),
            'timeLabel' => $start->isoFormat('h:mm A'),
            // For the pin itself: the day of the month, like a calendar.
            'day' => $start->format('j'),
            'month' => $start->isoFormat('MMM'),
            'place' => $this->venue?->name ?? $this->location_name,
            'area' => $this->area,
            'isOneOff' => $this->is_one_off,
            'locationNote' => $this->is_one_off ? $this->location_note : null,
            'price' => $this->price,
            'isImported' => $this->source === Show::SOURCE_IMPORTED,
        ];
    }
}
