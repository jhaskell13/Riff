<?php

namespace App\Notifications;

use App\Models\Artist;
use App\Models\Show;
use App\Support\NotificationType;

/**
 * An artist you follow listed a new date.
 *
 * The notification the roadmap cares most about: "notifications bring
 * people back to shows" is the success signal for this whole step.
 */
class ShowAnnounced extends RiffNotification
{
    public function __construct(
        private readonly Show $show,
        private readonly Artist $artist,
    ) {}

    public function type(): NotificationType
    {
        return NotificationType::ArtistShow;
    }

    public function title(): string
    {
        return "{$this->artist->name} has a new show";
    }

    public function body(): ?string
    {
        $where = $this->show->venue?->name ?? $this->show->location_name;

        return trim($this->show->localStart()->isoFormat('ddd, MMM D, h:mm A').($where === null ? '' : ' · '.$where));
    }

    public function url(): string
    {
        return route('shows.show', $this->show);
    }

    public function aboutArtist(): ?Artist
    {
        return $this->artist;
    }

    /**
     * Folded per show, not per artist: two different dates are two things
     * worth knowing, but the same date reaching you twice (because two
     * bands you follow are on it) is one.
     */
    public function collapseKey(): ?string
    {
        return 'artist_show:'.$this->show->id;
    }
}
