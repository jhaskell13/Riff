<?php

namespace App\Http\Controllers;

use App\Contracts\Commentable;
use App\Http\Requests\Wall\CommentRequest;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Show;
use App\Services\Wall;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;

/**
 * Replies on a wall post and on a show's thread: fans talking to each
 * other, and the band answering in its own voice.
 *
 * One pair of methods covers both, because a thread is a thread. What
 * differs -- who owns it, whether it is still open -- the subject answers
 * itself through App\Contracts\Commentable.
 */
class CommentController extends Controller
{
    public function __construct(private Wall $wall) {}

    public function storeOnPost(CommentRequest $request, Post $post): RedirectResponse
    {
        return $this->store($request, $post);
    }

    public function storeOnShow(CommentRequest $request, Show $show): RedirectResponse
    {
        return $this->store($request, $show);
    }

    private function store(CommentRequest $request, Commentable&Model $subject): RedirectResponse
    {
        $asArtist = (bool) $request->validated('as_artist', false);

        // Two different permissions, checked against what was asked for.
        // Asking for the artist voice without membership is refused rather
        // than quietly downgraded, as on the wall: someone who ticked
        // "reply as the band" and got a reply under their own name would be
        // surprised in public.
        $this->authorize(
            $asArtist ? 'createAsArtistOn' : 'createOn',
            [Comment::class, $subject],
        );

        $parentId = $request->validated('parent_id');

        $this->wall->comment(
            $request->user(),
            $subject,
            (string) $request->validated('content'),
            $asArtist,
            $parentId === null ? null : (int) $parentId,
        );

        return back();
    }

    public function destroy(Comment $comment): RedirectResponse
    {
        $this->authorize('delete', $comment);

        $comment->delete();

        return back();
    }
}
