<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Support\AuthorContext;

/**
 * Writing to an artist's wall.
 *
 * **The voice is resolved in exactly one place: voiceFor().** A request may
 * *ask* to speak as the artist; only membership grants it. Nothing else in
 * the app may set author_context, for the same reason nothing but
 * Lineup::sync() may write lineup_entries.status -- the moment two places
 * can decide who is speaking, one of them will be wrong.
 */
class Wall
{
    /**
     * The voice this user actually gets. Asking to be the band is not
     * being the band: a non-member who asks is quietly a fan, because the
     * fallback is harmless and an error here would be a dead end for
     * someone who simply isn't in the band.
     */
    public function voiceFor(User $user, Artist $artist, bool $asArtist): AuthorContext
    {
        return $asArtist && $user->can('postAsArtist', $artist)
            ? AuthorContext::Artist
            : AuthorContext::Fan;
    }

    /**
     * Post on an artist's wall. Created through the user's own relation so
     * the author cannot be mass-assigned, per the project's rule.
     */
    public function post(User $user, Artist $artist, string $content, bool $asArtist): Post
    {
        $voice = $this->voiceFor($user, $artist, $asArtist);

        return $user->posts()->create([
            'artist_id' => $artist->id,
            'author_context' => $voice,
            'content' => $content,
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    /**
     * Reply to a post. artist_id records *which* artist is speaking, and is
     * set only for the artist voice -- on a fan's comment it stays null, so
     * a fan's reply can never render as the band by accident.
     */
    public function comment(
        User $user,
        Post $post,
        string $content,
        bool $asArtist,
        ?int $parentId = null,
    ): Comment {
        $artist = $post->artist;
        $voice = $artist !== null
            ? $this->voiceFor($user, $artist, $asArtist)
            : AuthorContext::Fan;

        return $post->comments()->create([
            'user_id' => $user->id,
            'author_context' => $voice,
            'artist_id' => $voice->isArtist() ? $artist?->id : null,
            'parent_id' => $parentId,
            'content' => $content,
            'status' => Comment::PUBLISHED,
        ]);
    }
}
