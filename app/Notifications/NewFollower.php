<?php

namespace App\Notifications;

use App\Models\Artist;
use App\Support\NotificationType;

/**
 * Someone followed an artist you are in.
 *
 * Folded per artist: a band that picks up twenty fans after a good night
 * wants to know that, not to scroll past it twenty times.
 */
class NewFollower extends RiffNotification
{
    public function __construct(
        private readonly Artist $artist,
        private readonly string $followerName,
    ) {}

    public function type(): NotificationType
    {
        return NotificationType::NewFollower;
    }

    public function title(): string
    {
        return "{$this->followerName} is now a fan of {$this->artist->name}";
    }

    public function url(): string
    {
        return route('artists.show', $this->artist);
    }

    public function aboutArtist(): ?Artist
    {
        return $this->artist;
    }

    public function collapseKey(): ?string
    {
        return 'new_follower:'.$this->artist->id;
    }
}
