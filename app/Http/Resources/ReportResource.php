<?php

namespace App\Http\Resources;

use App\Models\Artist;
use App\Models\Listing;
use App\Models\Post;
use App\Models\Report;
use App\Models\Show;
use App\Models\Venue;
use App\Support\ModerationAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * One row of the report queue: enough to judge the complaint without
 * leaving the page. The subject is summarised, never serialised whole.
 *
 * @mixin Report
 */
class ReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subject = $this->reportable;

        return [
            'id' => $this->id,
            'reason' => $this->reason,
            'reasonLabel' => $this->reasonLabel(),
            'details' => $this->details,
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'reporter' => $this->whenLoaded('reporter', fn () => $this->reporter?->name),
            'subject' => $this->subject($subject),
            'actions' => $subject === null ? [] : ModerationAction::optionsFor($subject),
            'resolution' => $this->resolution?->value,
            'resolutionLabel' => $this->resolution?->label(),
            'resolvedBy' => $this->whenLoaded('resolvedBy', fn () => $this->resolvedBy?->name),
            'resolvedAt' => $this->resolved_at?->toIso8601String(),
            'note' => $this->moderator_note,
        ];
    }

    /**
     * The subject in the few fields that identify it; the fallback covers
     * one deleted outright, whose report still has to be closeable.
     *
     * @return array<string, mixed>
     */
    private function subject(?object $subject): array
    {
        if ($subject instanceof Artist) {
            return [
                'kind' => 'artist',
                'id' => $subject->id,
                'title' => $subject->name,
                'detail' => $subject->address,
                'body' => $subject->biography ? Str::limit($subject->biography, 400, '…') : null,
                'imageUrl' => $subject->image_url,
                'url' => route('artists.show', $subject),
                'state' => $subject->trashed()
                    ? 'Archived'
                    : ($subject->show_on_map ? null : 'Hidden from the map'),
            ];
        }

        if ($subject instanceof Post) {
            return [
                'kind' => 'post',
                'id' => $subject->id,
                'title' => $subject->artist?->name ?? $subject->user?->name ?? 'Deleted account',
                'detail' => $subject->published_at?->toIso8601String(),
                'body' => Str::limit($subject->content, 400, '…'),
                'imageUrl' => null,
                'url' => null,
                'state' => $subject->status === 'published' ? null : 'Removed',
            ];
        }

        if ($subject instanceof Venue) {
            return [
                'kind' => 'venue',
                'id' => $subject->id,
                'title' => $subject->name,
                // public_address, never the street column: a moderator
                // queue is no reason to surface a private venue's door.
                'detail' => $subject->kind_label.' · '.$subject->public_address,
                'body' => $subject->description ? Str::limit($subject->description, 400, '…') : null,
                'imageUrl' => null,
                'url' => $subject->trashed() ? null : route('venues.show', $subject),
                'state' => $subject->trashed()
                    ? 'Taken down'
                    : ($subject->show_on_map ? null : 'Hidden from the map'),
            ];
        }

        if ($subject instanceof Listing) {
            return [
                'kind' => 'listing',
                'id' => $subject->id,
                'title' => $subject->title,
                'detail' => $subject->type_label.' · '.$subject->area.' · posted by '
                    .($subject->artist?->name ?? $subject->user?->name ?? 'a deleted account'),
                'body' => Str::limit($subject->body, 400, '…'),
                'imageUrl' => null,
                'url' => $subject->trashed() ? null : route('listings.show', $subject),
                'state' => $subject->trashed()
                    ? 'Taken down'
                    : ($subject->closed_at !== null ? 'Closed by its poster' : ($subject->is_active ? null : 'Expired')),
            ];
        }

        if ($subject instanceof Show) {
            return [
                'kind' => 'show',
                'id' => $subject->id,
                'title' => ShowCardResource::displayTitle($subject),
                'detail' => $subject->localStart()->isoFormat('ddd, MMM D, h:mm A')
                    .' · '.($subject->venue?->name ?? $subject->location_name)
                    .($subject->hostArtist ? ' · listed by '.$subject->hostArtist->name : ''),
                'body' => $subject->description ? Str::limit($subject->description, 400, '…') : null,
                'imageUrl' => null,
                'url' => $subject->trashed() ? null : route('shows.show', $subject),
                'state' => $subject->trashed()
                    ? 'Taken down'
                    : ($subject->is_cancelled ? 'Cancelled by its hosts' : null),
            ];
        }

        return [
            'kind' => $this->reportable_type,
            'id' => $this->reportable_id,
            'title' => 'No longer on Riff',
            'detail' => null,
            'body' => null,
            'imageUrl' => null,
            'url' => null,
            'state' => 'Gone',
        ];
    }
}
