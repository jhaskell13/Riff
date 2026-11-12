<?php

namespace App\Http\Resources;

use App\DTO\WallViewer;
use App\Models\Comment;
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
                        ->resolve($request))
                    ->all(),
            ),
            'repliesCount' => $this->whenCounted('replies', fn () => (int) $this->replies_count),
            'canDelete' => $wallViewer->canDelete($isArtistVoice, $this->user_id),
        ];
    }
}
