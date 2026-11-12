<?php

namespace App\Http\Controllers;

use App\Http\Requests\Wall\CommentRequest;
use App\Models\Comment;
use App\Models\Post;
use App\Services\Wall;
use Illuminate\Http\RedirectResponse;

/**
 * Replies on a wall post: fans talking to each other, and the band
 * answering in its own voice.
 */
class CommentController extends Controller
{
    public function __construct(private Wall $wall) {}

    public function store(CommentRequest $request, Post $post): RedirectResponse
    {
        $asArtist = (bool) $request->validated('as_artist', false);

        if ($asArtist) {
            // Replying as the band needs membership of the band whose wall
            // this is, not of any band.
            abort_if($post->artist === null, 404);
            $this->authorize('postAsArtist', $post->artist);
        } else {
            $this->authorize('comment', $post);
        }

        $parentId = $request->validated('parent_id');

        $this->wall->comment(
            $request->user(),
            $post,
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
