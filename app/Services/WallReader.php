<?php

namespace App\Services;

use App\Contracts\Commentable;
use App\DTO\ThreadBadges;
use App\DTO\WallViewer;
use App\Http\Resources\CommentResource;
use App\Http\Resources\PostResource;
use App\Models\Artist;
use App\Models\Comment;
use App\Models\Post;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Reading an artist's wall: the posts, their replies, and the next page of
 * either.
 *
 * It lives in one place because three callers need the rules to agree --
 * the artist page, the "show more comments" endpoint, and the "see all
 * replies" endpoint. **Who may see hidden content is one of those rules:** a
 * member sees what they hid, flagged, so they can put it back; everyone
 * else never learns it existed. The rules themselves are model scopes
 * (`visibleTo`, `withReactionState`), so they read the same from a query on
 * posts, on a post's comments, or on a comment's replies.
 */
class WallReader
{
    /**
     * One page of a wall, with each post's first few replies.
     *
     * @return array<string, mixed>
     */
    public function wall(Request $request, Artist $artist): array
    {
        $viewer = WallViewer::for($request->user(), $artist);
        $commentsPerPost = (int) config('riff.wall.comments_per_post');
        $seesHidden = $viewer->canModerate;

        $posts = Post::query()
            ->onWall($artist)
            ->whereIn('status', Post::visibleStatuses($seesHidden))
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->tap($this->reactionState($viewer))
            ->with([
                'user:id,name',
                'artist:id,name,slug,image_path',
                ...$this->trailFor($viewer),
                'comments' => fn ($query) => $query
                    ->whereNull('parent_id')
                    ->whereIn('status', Comment::visibleStatuses($seesHidden))
                    ->tap($this->reactionState($viewer))
                    ->with([
                        'user:id,name',
                        'artist:id,name,image_path',
                        ...$this->trailFor($viewer),
                        'replies' => fn ($replies) => $replies
                            ->whereIn('status', Comment::visibleStatuses($seesHidden))
                            ->tap($this->reactionState($viewer))
                            ->with(['user:id,name', 'artist:id,name,image_path', ...$this->trailFor($viewer)])
                            ->oldest()
                            ->limit((int) config('riff.wall.replies_per_comment')),
                    ])
                    ->withCount(['replies' => fn ($replies) => $replies->whereIn('status', Comment::visibleStatuses($seesHidden))])
                    // A conversation reads forwards, unlike a wall.
                    ->oldest()
                    ->limit($commentsPerPost),
            ])
            // Counted the same way it is listed, so "4 comments" never
            // promises a guest something only a member can open.
            ->withCount(['comments' => fn ($query) => $query->topLevel()->whereIn('status', Comment::visibleStatuses($seesHidden))])
            ->latest('published_at')
            ->latest('id')
            ->paginate((int) config('riff.wall.posts_per_page'))
            ->withQueryString();

        return [
            // Built once for the wall: every post on it has the same artist
            // behind it, and one set of badges per post was an N+1.
            'posts' => $this->presentPosts(
                $request,
                $posts->getCollection(),
                $viewer,
                ThreadBadges::forWall($artist),
            ),
            'total' => $posts->total(),
            'nextPage' => $posts->hasMorePages() ? $posts->currentPage() + 1 : null,
            'commentsPerPost' => $commentsPerPost,
            'repliesPerComment' => (int) config('riff.wall.replies_per_comment'),
            // Shared to the client so the composer's limits match what
            // validation will accept, as reportReasons does for reports.
            'maxPost' => (int) config('riff.wall.max_post'),
            'maxComment' => (int) config('riff.wall.max_comment'),
        ];
    }

    /**
     * A whole show thread, for the show page.
     *
     * Shows get one flat thread rather than a wall of posts, so this is the
     * comment list and nothing else -- the same shape the wall gives each
     * of its posts.
     *
     * @return array<string, mixed>
     */
    public function thread(Request $request, Commentable&Model $subject, int $page = 1): array
    {
        $viewer = WallViewer::for($request->user(), $subject->moderatingArtist());

        return [
            ...$this->comments($request, $subject, $page),
            'isOpen' => $subject->acceptsComments(),
            'closedReason' => $subject->commentsClosedReason(),
            'canComment' => $viewer->canPost && $subject->acceptsComments(),
            'canCommentAsArtist' => $viewer->isMember && $subject->acceptsComments(),
            'canModerate' => $viewer->canModerate,
            'maxComment' => (int) config('riff.wall.max_comment'),
            'repliesPerComment' => (int) config('riff.wall.replies_per_comment'),
        ];
    }

    /**
     * A page of one subject's top-level comments, for "show more comments"
     * and for a show thread's first page.
     *
     * @return array<string, mixed>
     */
    public function comments(Request $request, Commentable&Model $subject, int $page): array
    {
        $viewer = WallViewer::for($request->user(), $subject->moderatingArtist());
        $seesHidden = $viewer->canModerate;

        $comments = $subject->comments()
            ->topLevel()
            ->whereIn('status', Comment::visibleStatuses($seesHidden))
            ->tap($this->reactionState($viewer))
            ->with([
                'user:id,name',
                'artist:id,name,image_path',
                ...$this->trailFor($viewer),
                'replies' => fn ($replies) => $replies
                    ->whereIn('status', Comment::visibleStatuses($seesHidden))
                    ->tap($this->reactionState($viewer))
                    ->with(['user:id,name', 'artist:id,name,image_path', ...$this->trailFor($viewer)])
                    ->oldest()
                    ->limit((int) config('riff.wall.replies_per_comment')),
            ])
            ->withCount(['replies' => fn ($replies) => $replies->whereIn('status', Comment::visibleStatuses($seesHidden))])
            ->oldest()
            ->paginate((int) config('riff.wall.comments_per_page'), ['*'], 'page', $page);

        return [
            'comments' => $this->presentComments(
                $request,
                $comments->getCollection(),
                $viewer,
                ThreadBadges::for($subject),
            ),
            'total' => $comments->total(),
            'nextPage' => $comments->hasMorePages() ? $comments->currentPage() + 1 : null,
        ];
    }

    /**
     * A page of one comment's replies, for "see all replies".
     *
     * @return array<string, mixed>
     */
    public function replies(Request $request, Comment $comment, int $page): array
    {
        $subject = $comment->commentable;
        $viewer = WallViewer::for(
            $request->user(),
            $subject instanceof Commentable ? $subject->moderatingArtist() : null,
        );
        $seesHidden = $viewer->canModerate;

        $replies = $comment->replies()
            ->whereIn('status', Comment::visibleStatuses($seesHidden))
            ->tap($this->reactionState($viewer))
            ->with(['user:id,name', 'artist:id,name,image_path', ...$this->trailFor($viewer)])
            ->oldest()
            ->paginate((int) config('riff.wall.replies_per_page'), ['*'], 'page', $page);

        return [
            'replies' => $this->presentComments(
                $request,
                $replies->getCollection(),
                $viewer,
                ThreadBadges::for($subject instanceof Commentable ? $subject : null),
            ),
            'total' => $replies->total(),
            'nextPage' => $replies->hasMorePages() ? $replies->currentPage() + 1 : null,
        ];
    }

    /**
     * Whether this viewer has reacted, as one EXISTS per row in the same
     * query rather than a lookup each. See CLAUDE.md: done naively, a wall
     * of hearts was over a hundred queries.
     *
     * Returned as a closure for tap() so it reads the same whether it is
     * applied to a root query or inside an eager-load closure, where the
     * receiver is a Relation and a scope could not be resolved.
     */
    private function reactionState(WallViewer $viewer): \Closure
    {
        return function ($query) use ($viewer) {
            if ($viewer->userId === null) {
                return;
            }

            $query->withExists([
                'likers as is_liked' => fn ($likers) => $likers->whereKey($viewer->userId),
            ]);
        };
    }

    /**
     * The moderation trail is loaded only for members: they are the only
     * audience it means anything to, and a guest has no hidden rows to
     * explain.
     *
     * @return array<string, \Closure>
     */
    private function trailFor(WallViewer $viewer): array
    {
        return $viewer->canModerate
            ? ['wallEvents' => fn ($query) => $query->with('actor:id,name')->limit(10)]
            : [];
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @return list<array<string, mixed>>
     */
    private function presentPosts(Request $request, Collection $posts, WallViewer $viewer, ThreadBadges $badges): array
    {
        return $posts
            ->map(fn (Post $post) => PostResource::make($post)
                ->forViewer($viewer)
                // Each post is its own little thread, so each carries the
                // wall's badges down to its replies.
                ->withBadges($badges)
                ->resolve($request))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Comment>  $comments
     * @return list<array<string, mixed>>
     */
    private function presentComments(
        Request $request,
        Collection $comments,
        WallViewer $viewer,
        ?ThreadBadges $badges = null,
    ): array {
        return $comments
            ->map(fn (Comment $comment) => CommentResource::make($comment)
                ->forViewer($viewer)
                ->withBadges($badges)
                ->resolve($request))
            ->values()
            ->all();
    }
}
