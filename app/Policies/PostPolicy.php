<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;
use App\Support\AuthorContext;

class PostPolicy
{
    /**
     * Deleting your own words.
     *
     * For an artist-voice post, "yours" means the artist's: any member may
     * delete it, because the band said it, not the member who typed it.
     * For a fan post, only its author -- an artist taking a fan's post off
     * their wall is *hiding*, which is artist-level moderation (step 4),
     * not deletion, and stays recoverable.
     */
    public function delete(User $user, Post $post): bool
    {
        if ($post->author_context === AuthorContext::Artist) {
            return $post->artist !== null && $user->isMemberOf($post->artist);
        }

        return $post->user_id === $user->id;
    }

    /**
     * Commenting on a post means posting on the wall it belongs to, so it
     * answers to the same rule (ArtistPolicy::postOnWall). A post with no
     * wall is a leftover from the old global feed and takes no comments.
     */
    public function comment(User $user, Post $post): bool
    {
        return $post->artist !== null
            && $post->status === 'published'
            && $user->can('postOnWall', $post->artist);
    }

    /**
     * Reactions are for signed-in listeners and never rank anything.
     */
    public function like(User $user, Post $post): bool
    {
        return $post->status === 'published';
    }
}
