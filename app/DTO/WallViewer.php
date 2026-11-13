<?php

namespace App\DTO;

use App\Models\Artist;
use App\Models\User;

/**
 * What one viewer may do on one artist's wall, worked out once per render.
 *
 * Every post and reply asks "can I delete this, can I reply here", and on
 * one wall both reduce to two facts. Asking the policy per row was three
 * exists() queries per post.
 *
 * Deliberately not a memo on the User model: that was tried and went stale
 * when a follow was written outside the follow controller. This is built
 * per render and cannot outlive it.
 */
final class WallViewer
{
    public function __construct(
        public readonly ?int $userId = null,
        public readonly bool $isMember = false,
        public readonly bool $canPost = false,
        public readonly bool $canModerate = false,
    ) {}

    /** A guest, or a caller that did not ask for viewer state. */
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
     * An artist-voice post belongs to the band, so any member may take it
     * back; a fan's belongs to the fan. Only decides whether to draw the
     * control — PostPolicy/CommentPolicy still gate the request.
     */
    public function canDelete(bool $isArtistVoice, ?int $authorId): bool
    {
        if ($this->userId === null) {
            return false;
        }

        return $isArtistVoice ? $this->isMember : $authorId === $this->userId;
    }
}
