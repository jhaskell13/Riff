<?php

namespace App\Notifications;

use App\Models\Artist;
use App\Models\Post;
use App\Support\NotificationType;
use Illuminate\Support\Str;

/** A fan posted on your artist's wall. */
class WallPostReceived extends RiffNotification
{
    public function __construct(
        private readonly Post $post,
        private readonly Artist $artist,
        private readonly string $authorName,
    ) {}

    public function type(): NotificationType
    {
        return NotificationType::WallPost;
    }

    public function title(): string
    {
        return "{$this->authorName} posted on {$this->artist->name}'s wall";
    }

    public function body(): ?string
    {
        return Str::limit($this->post->content, 140, '…');
    }

    /** Straight to the post itself; the client scrolls and flashes it. */
    public function url(): string
    {
        return route('artists.show', $this->artist).'#post-'.$this->post->id;
    }

    public function aboutArtist(): ?Artist
    {
        return $this->artist;
    }
}
