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
     * The counter moves only when the pivot row genuinely changed, and the
     * database decides whether it did.
     *
     * An exists() check followed by attach() looks equivalent and is not:
     * two concurrent taps both pass the check, and the second attach hits
     * the unique index and 500s. insertOrIgnore is one atomic statement
     * whose affected-row count is the answer.
     *
     * @param  Post|Comment  $subject
     */
    private function like(Model $subject, int $userId): void
    {
        $pivot = $subject->likers();
        $now = now();

        $inserted = DB::table($pivot->getTable())->insertOrIgnore([
            $pivot->getForeignPivotKeyName() => $subject->getKey(),
            $pivot->getRelatedPivotKeyName() => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 1) {
            $subject->increment('likes');
        }
    }

    /**
     * detach() already reports how many rows it removed, so only one of
     * two concurrent un-taps decrements.
     *
     * @param  Post|Comment  $subject
     */
    private function unlike(Model $subject, int $userId): void
    {
        if ($subject->likers()->detach($userId) === 0) {
            return;
        }

        // Guarded: the column is unsigned, and a drifted counter going
        // negative is a hard error on MySQL in strict mode.
        $subject->newQuery()
            ->whereKey($subject->getKey())
            ->where('likes', '>', 0)
            ->decrement('likes');
    }
}
