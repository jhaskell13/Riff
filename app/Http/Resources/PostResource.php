<?php

namespace App\Http\Resources;

use App\DTO\ThreadBadges;
use App\DTO\WallViewer;
use App\Models\Comment;
use App\Models\Post;
use App\Models\WallModerationEvent;
use App\Support\AuthorContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A post as a reader sees it: pins exactly what the client needs so
 * internal columns (status, counters, meta_data) never leak by default.
 *
 * **The privacy rule this resource enforces:** on an artist-voice post, the
 * member who typed it is never named. The band said it; which member
 * pressed post is internal, kept for the audit trail and for artist-level
 * moderation. `user` is emitted only when the voice is the fan's.
 *
 * @mixin Post
 */
class PostResource extends JsonResource
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

    /**
     * What this viewer may do here, worked out once for the whole wall
     * rather than per post. Without it every row runs the policies, which
     * is three exists() queries each.
     */
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
            'authorContext' => $this->author_context->value,
            'badge' => ($this->badges ?? ThreadBadges::none())
                ->labelFor($isArtistVoice, $this->artist_id, $this->user_id),
            'publishedAt' => $this->published_at?->toIso8601String(),
            // Kept for the feed's existing payload shape.
            'published_at' => $this->published_at?->toIso8601String(),
            // Only ever the fan. See the class comment.
            'user' => $isArtistVoice ? null : $this->whenLoaded('user', fn () => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            // The wall this lives on, and -- for an artist-voice post --
            // also its author.
            'artist' => $this->whenLoaded('artist', fn () => $this->artist === null ? null : [
                'id' => $this->artist->id,
                'name' => $this->artist->name,
                'image_url' => $this->artist->image_url,
                'url' => route('artists.show', $this->artist),
            ]),
            'likesCount' => (int) ($this->likes ?? 0),
            // Read from a pre-computed `is_liked` (see the wall query's
            // withExists), never queried here: isLikedBy() in a resource
            // is one query per post, and a wall renders ten of them with
            // their replies. Null means "unknown" -- a guest, or a caller
            // that did not ask for reaction state -- and the client shows
            // no reaction control rather than a wrong one.
            'isLiked' => $viewer === null || ! array_key_exists('is_liked', $this->resource->getAttributes())
                ? null
                : (bool) $this->resource->getAttributes()['is_liked'],
            'commentsCount' => $this->whenCounted('comments', fn () => (int) $this->comments_count),
            // resolve()d to a plain array on purpose: a nested resource
            // collection serialises as {"data": [...]}, so the client
            // would be iterating an object, and every assertion path
            // would carry a .data segment.
            // Mapped by hand rather than ::collection(): each child needs
            // the same viewer, and a nested resource collection would also
            // serialise as {"data": [...]}, which the client would have to
            // reach through.
            'comments' => $this->whenLoaded(
                'comments',
                fn () => $this->comments
                    ->map(fn (Comment $comment) => CommentResource::make($comment)
                        ->forViewer($wallViewer)
                        ->withBadges($this->badges)
                        ->resolve($request))
                    ->all(),
            ),
            'canDelete' => $wallViewer->canDelete($isArtistVoice, $this->user_id),
            // Hidden rows only ever reach a member (WallReader::visible),
            // so these are meaningless to anyone else -- but they are
            // emitted plainly rather than conditionally, because a client
            // that renders a hidden row as normal would be the bug.
            'isHidden' => $this->status === Post::HIDDEN,
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
            // A published post on a wall this viewer may post on.
            'canComment' => $wallViewer->canPost && $this->status === 'published',
        ];
    }
}
