<?php

namespace App\Http\Controllers;

use App\Contracts\Commentable;
use App\Models\Artist;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Services\WallModeration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Artist-level moderation: a band keeping its own page tidy.
 *
 * Everything here is reversible and nothing here destroys anything. Hiding
 * takes something off the public wall and leaves the row intact; the undo
 * is a second action, recorded next to the first. Taking content off Riff
 * altogether stays a platform moderator's decision, which members reach by
 * *reporting* it through the same `POST /reports` endpoint everyone uses.
 */
class WallModerationController extends Controller
{
    public function __construct(private WallModeration $moderation) {}

    public function hidePost(Request $request, Post $post): RedirectResponse
    {
        $artist = $this->wallOf($post);
        $this->authorize('moderateWall', $artist);

        $this->moderation->hide($artist, $request->user(), $post, $this->note($request));

        return back();
    }

    public function unhidePost(Request $request, Post $post): RedirectResponse
    {
        $artist = $this->wallOf($post);
        $this->authorize('moderateWall', $artist);

        $this->moderation->unhide($artist, $request->user(), $post, $this->note($request));

        return back();
    }

    public function hideComment(Request $request, Comment $comment): RedirectResponse
    {
        $artist = $this->wallOf($comment);
        $this->authorize('moderateWall', $artist);

        $this->moderation->hide($artist, $request->user(), $comment, $this->note($request));

        return back();
    }

    public function unhideComment(Request $request, Comment $comment): RedirectResponse
    {
        $artist = $this->wallOf($comment);
        $this->authorize('moderateWall', $artist);

        $this->moderation->unhide($artist, $request->user(), $comment, $this->note($request));

        return back();
    }

    public function ban(Request $request, Artist $artist): RedirectResponse
    {
        $this->authorize('moderateWall', $artist);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->moderation->ban(
            $artist,
            $request->user(),
            User::findOrFail($validated['user_id']),
            $validated['reason'] ?? null,
        );

        return back();
    }

    public function unban(Request $request, Artist $artist, User $user): RedirectResponse
    {
        $this->authorize('moderateWall', $artist);

        $this->moderation->unban($artist, $request->user(), $user, $this->note($request));

        return back();
    }

    /**
     * Whose space this lives in: the wall's artist for a post, the thread's
     * owner for a comment -- which for a show thread is the host artist,
     * whose members listed the night.
     *
     * Asked through App\Contracts\Commentable rather than by branching on
     * the class, so a new commentable kind inherits moderation instead of
     * needing this method found and edited.
     */
    private function wallOf(Post|Comment $subject): Artist
    {
        $owner = $subject instanceof Comment ? $subject->commentable : $subject;

        $artist = $owner instanceof Commentable ? $owner->moderatingArtist() : null;

        // No owner means nothing to moderate: a wall-less post from the old
        // global feed, or a show whose host has been archived.
        abort_if($artist === null, 404);

        return $artist;
    }

    private function note(Request $request): ?string
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $validated['note'] ?? null;
    }
}
