<?php

namespace App\Http\Resources;

use App\Models\LineupEntry;
use App\Models\Show;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public show page. Only confirmed lineup entries are included — a
 * band that hasn't agreed to a bill isn't on it — and archived artists drop
 * out. Callers eager-load confirmedLineup.artist, venue and hostArtist.
 *
 * @mixin Show
 */
class ShowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $start = $this->localStart();
        $doors = $this->localDoors();
        $agePolicy = $this->effective_age_policy;

        return [
            'id' => $this->id,
            'title' => ShowCardResource::displayTitle($this->resource),
            'hasOwnTitle' => filled($this->title),
            'lineup' => $this->confirmedLineup
                ->filter(fn (LineupEntry $entry) => $entry->artist_id === null || $entry->artist !== null)
                ->map(fn (LineupEntry $entry) => [
                    'name' => $entry->display_name,
                    'url' => $entry->artist ? route('artists.show', $entry->artist) : null,
                    'imageUrl' => $entry->artist?->image_url,
                ])
                ->values(),
            'dateLabel' => $start->isoFormat('dddd, MMMM D, YYYY'),
            'timeLabel' => $start->isoFormat('h:mm A'),
            'doorsLabel' => $doors?->isoFormat('h:mm A'),
            'timezoneLabel' => $start->format('T'),
            'startsAt' => $start->toIso8601String(),
            // Exactly one of venue / location is set.
            'venue' => $this->venue === null ? null : [
                'name' => $this->venue->name,
                'address' => $this->venue->public_address,
                'isPrivate' => $this->venue->is_private,
                'url' => route('venues.show', $this->venue),
            ],
            'location' => $this->venue_id !== null ? null : [
                'name' => $this->location_name,
                'area' => $this->area,
                'note' => $this->location_note,
            ],
            'price' => $this->price,
            'ticketUrl' => $this->ticket_url,
            'agePolicyLabel' => $agePolicy === null ? null : (config('riff.age_policies')[$agePolicy] ?? null),
            'description' => $this->description,
            'host' => $this->hostArtist === null ? null : [
                'name' => $this->hostArtist->name,
                'url' => route('artists.show', $this->hostArtist),
            ],
            'isCancelled' => $this->is_cancelled,
            'hasPassed' => $this->has_passed,
            'isImported' => $this->source === Show::SOURCE_IMPORTED,
            'provider' => $this->provider,
        ];
    }
}
