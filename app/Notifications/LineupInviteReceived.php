<?php

namespace App\Notifications;

use App\Models\Artist;
use App\Models\Show;
use App\Support\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Your artist was added to a bill, or asked again after it moved.
 *
 * This is the debt Phase 2 left behind: before notifications existed, an
 * invitation sat in the Artist Zone until someone happened to look, and an
 * unanswered invitation keeps a show off every public surface. It is the
 * one notification here that gates somebody else's listing.
 *
 * Never folded, and never muted by a per-artist setting -- it is a
 * question addressed to you, not an update about a band.
 */
class LineupInviteReceived extends RiffNotification
{
    /**
     * **The only per-event email in Riff** (owner decision 5). Everything
     * else waits for the weekly digest, because an inbox is not a feed --
     * but an unanswered invitation keeps somebody else's show off every
     * public surface, which makes this both actionable and time-sensitive.
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting('Hi '.($notifiable->name ?? 'there').',')
            ->line($this->title().'.')
            ->line((string) $this->body())
            ->action('Confirm or decline', route('artists.index'))
            ->line('Until you answer, this show stays off the map and off every listing.');
    }

    public function __construct(
        private readonly Show $show,
        private readonly Artist $artist,
        private readonly bool $reAsked = false,
    ) {}

    public function type(): NotificationType
    {
        return NotificationType::LineupInvite;
    }

    public function title(): string
    {
        return $this->reAsked
            ? "A show {$this->artist->name} is on has moved — please confirm again"
            : "{$this->artist->name} has been added to a bill";
    }

    public function body(): ?string
    {
        $where = $this->show->venue?->name ?? $this->show->location_name;

        return trim($this->show->localStart()->isoFormat('ddd, MMM D, h:mm A').($where === null ? '' : ' · '.$where));
    }

    /**
     * The Artist Zone, where confirm and decline live -- not the show
     * page, which does not list a pending act at all.
     */
    public function url(): string
    {
        return route('artists.index');
    }

    public function aboutArtist(): ?Artist
    {
        return $this->artist;
    }
}
