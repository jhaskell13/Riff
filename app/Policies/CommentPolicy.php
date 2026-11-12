<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;
use App\Support\AuthorContext;

class CommentPolicy
{
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
