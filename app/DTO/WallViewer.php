<?php

namespace App\DTO;

use App\Models\Artist;
use App\Models\User;

/**
 * What one viewer may do on one artist's wall, worked out once.
 *
 * Every post and every reply on a page asks the same two questions -- "can
 * I delete this?", "can I reply here?" -- and on a single wall the answers
 * turn on exactly two facts: whether the viewer is a member of *this*
 * artist, and whether they may post here at all. Asking the policy per row
 * meant three `exists()` queries per post; a busy wall cost 24 extra
 * queries for a page of buttons.
 *
 * Deliberately not a memo on the User model: that was the first attempt,
 * and it went stale the moment a follow was written outside the follow
 * controller (a test caught it reusing one User instance across two
 * requests). This object is built per render and cannot outlive it.
 */
final class WallViewer
{
    public function __construct(
        public readonly ?int $userId = null,
        public readonly bool $isMember = false,
        public readonly bool $canPost = false,
        public readonly bool $canModerate = false,
    ) {}

    /** Nobody: a guest, or a caller that did not ask for viewer state. */
    public static function guest(): self
    {
        return new self;
    }

    public static function for(?User $user, ?Artist $artist): self
    {
        if ($user === null || $artist === null) {
            return self::guest();
        }

        return new self(
            userId: $user->id,
            isMember: $user->can('postAsArtist', $artist),
            canPost: $user->can('postOnWall', $artist),
            canModerate: $user->can('moderateWall', $artist),
        );
    }

    /**
     * Whether this viewer may delete something written in the given voice.
     *
     * An artist-voice post belongs to the band, so any member may take it
     * back. A fan's post belongs to the fan. Mirrors PostPolicy::delete and
     * CommentPolicy::delete, which remain the authority for the actual
     * delete request -- this only decides whether to draw the control.
     */
    public function canDelete(bool $isArtistVoice, ?int $authorId): bool
    {
        if ($this->userId === null) {
            return false;
        }

        return $isArtistVoice ? $this->isMember : $authorId === $this->userId;
    }
}
