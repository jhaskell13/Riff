<?php

namespace App\Http\Controllers;

use App\Contracts\Commentable;
use App\Models\Artist;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Show;
use App\Services\WallReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * More of a thread, on demand.
 *
 * A wall ships each post with only its first couple of replies, and each
 * reply with its first couple of answers, so a page of a busy wall stays a
 * page. These endpoints are what "show more comments" and "see all
 * replies" fetch.
 *
 * Public, like the wall itself: a guest reading a shared artist link must
 * be able to read the whole conversation. Rate-limited with the `map`
 * limiter, which is the same public-read class.
 */
class WallThreadController extends Controller
{
    public function __construct(private WallReader $reader) {}

    public function comments(Request $request, Post $post): JsonResponse
    {
        return $this->serve($request, $post, fn (Artist $artist) => $this->reader
            ->comments($request, $post, $this->page($request)));
    }

    public function showComments(Request $request, Show $show): JsonResponse
    {
        return $this->serve($request, $show, fn (Artist $artist) => $this->reader
            ->comments($request, $show, $this->page($request)));
    }

    public function replies(Request $request, Comment $comment): JsonResponse
    {
        $subject = $comment->commentable;

        abort_unless($subject instanceof Commentable, 404);

        return $this->serve($request, $subject, fn (Artist $artist) => $this->reader
            ->replies($request, $comment, $this->page($request)));
    }

    /**
     * Authorize against the thread's owning artist, then serve.
     *
     * No owner means no thread: a wall-less post from the old global feed,
     * or a show whose host has been archived.
     *
     * @param  \Closure(Artist): array<string, mixed>  $payload
     */
    private function serve(Request $request, Commentable $subject, \Closure $payload): JsonResponse
    {
        $artist = $subject->moderatingArtist();

        abort_if($artist === null, 404);

        $this->authorize('view', $artist);

        return response()->json($payload($artist));
    }

    /** Bounded so a crafted page number cannot ask for an absurd offset. */
    private function page(Request $request): int
    {
        $page = (int) $request->integer('page', 1);

        return max(1, min($page, 1000));
    }
}
