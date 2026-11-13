<?php

namespace App\Policies;

use App\Contracts\Commentable;
use App\Models\Comment;
use App\Models\User;
use App\Support\AuthorContext;

class CommentPolicy
{
    /**
     * Whether this user may add to a thread -- on a wall post or on a show.
     *
     * Is the thread open at all (the subject's own answer), and may *this*
     * person post here?
     *
     * The second question differs by subject, which is why the subject
     * decides it. **A wall follows the artist's `wall_mode`** -- it is their
     * page. **A show does not**: it is a public event, so anyone with an
     * account may ask about the door time, and requiring a follow first
     * would be a strange tax on a practical question.
     *
     * Either way the artist's own exclusions still hold: someone barred
     * from a band's wall, or who has blocked that band, is not let in
     * through the side door of its show threads.
     */
    public function createOn(User $user, Commentable $subject): bool
    {
        $artist = $subject->moderatingArtist();

        if ($artist === null || ! $subject->acceptsComments()) {
            return false;
        }

        if ($subject->commentsFollowWallMode()) {
            return $user->can('postOnWall', $artist);
        }

        return $user->isMemberOf($artist)
            || (! $user->blocks($artist) && ! $user->isBannedFromWall($artist));
    }

    /**
     * Whether this user may answer in an artist's own voice -- the band
     * replying "we're on at nine" under its own show.
     *
     * Which artist that is belongs to the subject: on a wall it is the
     * page's artist, on a show the host *or any confirmed act on the bill*
     * the user belongs to.
     */
    public function createAsArtistOn(User $user, Commentable $subject): bool
    {
        return $subject->acceptsComments()
            && $subject->voiceArtistFor($user) !== null;
    }

    /**
     * As with posts: an artist-voice reply belongs to the band, so any
     * member may delete it; a fan's reply belongs to the fan.
     */
    public function delete(User $user, Comment $comment): bool
    {
        if ($comment->author_context === AuthorContext::Artist) {
            return $comment->artist !== null && $user->isMemberOf($comment->artist);
        }

        return $comment->user_id === $user->id;
    }

    public function like(User $user, Comment $comment): bool
    {
        return $comment->status === Comment::PUBLISHED;
    }
}
