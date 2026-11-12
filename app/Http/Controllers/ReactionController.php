<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Post;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Likes on posts and comments -- the long-dormant post_likes/comment_likes
 * schema finally in use.
 *
 * **Reactions stay small and rank nothing.** Walls and threads are ordered
 * by time, never by this count (ROADMAP principle 2: no ranking by
 * popularity). The counter exists so a reader can see that others agreed,
 * not so Riff can decide what matters.
 *
 * Both actions are idempotent, like following: the pivot's unique index is
 * the source of truth, and the integer column is kept in step with it
 * inside the same transaction.
 */
class ReactionController extends Controller
{
    public function likePost(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('like', $post);

        $this->like($post, $request->user()->id);

        return back();
    }

    public function unlikePost(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('like', $post);

        $this->unlike($post, $request->user()->id);

        return back();
    }

    public function likeComment(Request $request, Comment $comment): RedirectResponse
    {
        $this->authorize('like', $comment);

        $this->like($comment, $request->user()->id);

        return back();
    }

    public function unlikeComment(Request $request, Comment $comment): RedirectResponse
    {
        $this->authorize('like', $comment);

        $this->unlike($comment, $request->user()->id);

        return back();
    }

    /**
     * attach() only when the pivot row is genuinely new, so a double-tap
     * cannot inflate the counter.
     *
     * @param  Post|Comment  $subject
     */
    private function like(Model $subject, int $userId): void
    {
        DB::transaction(function () use ($subject, $userId) {
            if ($subject->likers()->whereKey($userId)->exists()) {
                return;
            }

            $subject->likers()->attach($userId);
            $subject->increment('likes');
        });
    }

    /**
     * @param  Post|Comment  $subject
     */
    private function unlike(Model $subject, int $userId): void
    {
        DB::transaction(function () use ($subject, $userId) {
            if ($subject->likers()->detach($userId) === 0) {
                return;
            }

            // Guarded so a counter that has drifted cannot go negative:
            // the column is unsigned, and a negative write is a hard error
            // on MySQL in strict mode.
            $subject->newQuery()
                ->whereKey($subject->getKey())
                ->where('likes', '>', 0)
                ->decrement('likes');
        });
    }
}
