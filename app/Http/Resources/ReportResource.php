<?php

namespace App\Http\Resources;

use App\Models\Artist;
use App\Models\Post;
use App\Models\Report;
use App\Support\ModerationAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * One row of the report queue: enough for a moderator to judge the
 * complaint without leaving the page, and nothing more. The subject is
 * summarised rather than serialised whole -- the queue has no business
 * carrying an artist's whole record, and a post's author is shown because
 * repeat offenders are the point of a queue.
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
     * What was reported, in the few fields that let a moderator recognise
     * it. `gone` covers a subject deleted outright rather than archived:
     * the complaint still has to be closeable.
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
                'url' => route('artists.show', $subject->id),
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
