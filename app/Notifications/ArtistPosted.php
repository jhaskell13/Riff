<?php

namespace App\Notifications;

use App\Models\Artist;
use App\Models\Post;
use App\Support\NotificationType;
use Illuminate\Support\Str;

/**
 * An artist you follow posted an update.
 *
 * Folded per artist: this is the kind that would otherwise turn the bell
 * into the feed this product does not have.
 */
class ArtistPosted extends RiffNotification
{
    public function __construct(
        private readonly Post $post,
        private readonly Artist $artist,
    ) {}

    public function type(): NotificationType
    {
        return NotificationType::ArtistPost;
    }

    public function title(): string
    {
        return "{$this->artist->name} posted an update";
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

    public function collapseKey(): ?string
    {
        return 'artist_post:'.$this->artist->id;
    }
}
