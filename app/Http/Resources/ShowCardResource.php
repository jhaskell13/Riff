<?php

namespace App\Http\Resources;

use App\Models\LineupEntry;
use App\Models\Show;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A show as a line in a list (artist page, venue page, Artist Zone).
 * Times are formatted in the show's own timezone, never the viewer's.
 * Callers eager-load confirmedLineup.artist and venue.
 *
 * @mixin Show
 */
class ShowCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $start = $this->localStart();

        return [
            'id' => $this->id,
            'url' => route('shows.show', $this->resource),
            'title' => self::displayTitle($this->resource),
            'dateLabel' => $start->isoFormat('ddd, MMM D'),
            'timeLabel' => $start->isoFormat('h:mm A'),
            'startsAt' => $start->toIso8601String(),
            'place' => $this->venue?->name ?? $this->location_name,
            'area' => $this->area,
            'isCancelled' => $this->is_cancelled,
            'isImported' => $this->source === Show::SOURCE_IMPORTED,
        ];
    }

    /**
     * The title, or the confirmed bill when there is none ("Gutter Hymns,
     * Sewer Psalms and 2 more"). Pending acts are never named.
     */
    public static function displayTitle(Show $show): string
    {
        if (filled($show->title)) {
            return (string) $show->title;
        }

        $names = $show->confirmedLineup
            ->filter(fn (LineupEntry $entry) => $entry->artist_id === null || $entry->artist !== null)
            ->map(fn (LineupEntry $entry) => $entry->display_name)
            ->values();

        if ($names->isEmpty()) {
            return 'A show';
        }

        if ($names->count() <= 3) {
            return $names->join(', ', ' & ');
        }

        return $names->take(2)->join(', ').' and '.($names->count() - 2).' more';
    }
}
