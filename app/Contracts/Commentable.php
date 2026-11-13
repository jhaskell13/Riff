<?php

namespace App\Contracts;

use App\Models\Artist;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Something a conversation hangs off: a wall post, or a show.
 *
 * Comments are polymorphic so show threads need no phantom post row. This
 * is the other half of that: everything *around* a comment asks the
 * subject instead of branching on its class. Every answer reduces to one
 * idea — a thread belongs to an artist.
 */
interface Commentable
{
    /** @return MorphMany<Comment, covariant \Illuminate\Database\Eloquent\Model> */
    public function comments(): MorphMany;

    /**
     * Whose members moderate this thread. Null means there is no thread
     * here (a wall-less legacy post, an archived host) and callers must
     * 404 rather than treat it as open to everyone.
     */
    public function moderatingArtist(): ?Artist;

    /** Where to read this thread in context, or null if there is nowhere. */
    public function threadUrl(): ?string;

    /**
     * Which artist this user may speak *as* here. On a show that is the
     * host or any confirmed act on the bill they belong to — the other
     * bands playing have as much standing to answer "what time are you
     * on?" as the one that listed it.
     */
    public function voiceArtistFor(User $user): ?Artist;

    /**
     * The artists with standing here, for labelling who is who.
     *
     * @return Collection<int, Artist>
     */
    public function commentVoiceArtists(): Collection;

    /**
     * Whether the owning artist's `wall_mode` decides who may comment.
     * True for a wall (their page); false for a show, which is a public
     * event. Bans and blocks apply either way.
     */
    public function commentsFollowWallMode(): bool;

    public function acceptsComments(): bool;

    /**
     * Why it is closed, or null when open. Saying so beats hiding the box
     * and letting someone conclude Riff is broken.
     */
    public function commentsClosedReason(): ?string;
}
