<?php

namespace App\Http\Resources;

use App\DTO\ThreadBadges;
use App\DTO\WallViewer;
use App\Models\Comment;
use App\Models\WallModerationEvent;
use App\Support\AuthorContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A reply as a reader sees it.
 *
 * Same privacy rule as PostResource: on an artist-voice reply the member is
 * never named, and `isArtist` is what the client renders the "from the
 * band" badge from -- the roadmap asks for the artist's replies to be
 * marked, and a reader can only trust that if it comes from stored state
 * rather than from a name comparison.
 *
 * @mixin Comment
 */
class CommentResource extends JsonResource
{
    private ?WallViewer $wallViewer = null;

    private ?ThreadBadges $badges = null;

    /**
     * Who is who in this thread, worked out once per render. See
     * App\DTO\ThreadBadges.
     */
    public function withBadges(?ThreadBadges $badges): static
    {
        $this->badges = $badges;

        return $this;
    }

    /** See PostResource::forViewer(). */
    public function forViewer(WallViewer $viewer): static
    {
        $this->wallViewer = $viewer;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $wallViewer = $this->wallViewer ?? WallViewer::guest();
        $isArtistVoice = $this->author_context === AuthorContext::Artist;

        return [
            'id' => $this->id,
            'content' => $this->content,
            'isArtist' => $isArtistVoice,
            // "Host", "On the bill", "Member of X" -- the author's standing
            // in *this* thread, never a repeat of the name above it.
            'badge' => ($this->badges ?? ThreadBadges::none())
                ->labelFor($isArtistVoice, $this->artist_id, $this->user_id),
            'parentId' => $this->parent_id,
            'createdAt' => $this->created_at?->toIso8601String(),
            'user' => $isArtistVoice ? null : $this->whenLoaded('user', fn () => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'artist' => ! $isArtistVoice ? null : $this->whenLoaded('artist', fn () => $this->artist === null ? null : [
                'id' => $this->artist->id,
                'name' => $this->artist->name,
                'image_url' => $this->artist->image_url,
            ]),
            'likesCount' => (int) $this->likes,
            // Pre-computed, never queried per row -- see PostResource.
            'isLiked' => $viewer === null || ! array_key_exists('is_liked', $this->resource->getAttributes())
                ? null
                : (bool) $this->resource->getAttributes()['is_liked'],
            // Plain array, not a wrapped collection -- see PostResource.
            'replies' => $this->whenLoaded(
                'replies',
                fn () => $this->replies
                    ->map(fn (Comment $reply) => CommentResource::make($reply)
                        ->forViewer($wallViewer)
                        ->withBadges($this->badges)
                        ->resolve($request))
                    ->all(),
            ),
            'repliesCount' => $this->whenCounted('replies', fn () => (int) $this->replies_count),
            'canDelete' => $wallViewer->canDelete($isArtistVoice, $this->user_id),
            // Hidden rows only ever reach a member (WallReader::visible),
            // so these are meaningless to anyone else -- but they are
            // emitted plainly rather than conditionally, because a client
            // that renders a hidden row as normal would be the bug.
            'isHidden' => $this->status === Comment::HIDDEN,
            'canModerate' => $wallViewer->canModerate,
            // What a member did to this, newest first, so an accidental
            // hide is both visible and undoable where it happened -- and
            // so a row hidden, restored and hidden again reads as that
            // rather than as a single state. Loaded only for members.
            'moderation' => $this->whenLoaded(
                'wallEvents',
                fn () => $this->wallEvents->first() === null ? null : [
                    'action' => $this->wallEvents->first()->action->value,
                    'label' => $this->wallEvents->first()->action->label(),
                    'by' => $this->wallEvents->first()->actor?->name,
                    'at' => $this->wallEvents->first()->created_at?->toIso8601String(),
                    'note' => $this->wallEvents->first()->note,
                ],
            ),
            'moderationTrail' => $this->whenLoaded(
                'wallEvents',
                fn () => $this->wallEvents->map(fn (WallModerationEvent $event) => [
                    'id' => $event->id,
                    'action' => $event->action->value,
                    'label' => $event->action->label(),
                    'by' => $event->actor?->name,
                    'at' => $event->created_at?->toIso8601String(),
                    'note' => $event->note,
                ])->all(),
            ),
        ];
    }
}
