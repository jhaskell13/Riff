<?php

namespace App\Notifications;

use App\Models\Artist;
use App\Models\User;
use App\Support\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * The shape every Riff notification shares.
 *
 * One payload, so the bell can render a kind it has never heard of: a
 * title, a line of body, somewhere to go, and an icon. Adding a kind is a
 * subclass plus a config entry -- nothing in the client changes. That is
 * the point, because in-app messaging (Phase 4) will add one.
 *
 * Queued: nothing a notification does should hold up the request that
 * caused it. A reply must post whether or not the bell updates.
 */
abstract class RiffNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Who caused this. The Notifier drops them from the recipients, so no
     * trigger has to remember to -- and one of them would not.
     */
    private ?int $actorId = null;

    public function causedBy(?User $actor): static
    {
        $this->actorId = $actor?->id;

        return $this;
    }

    public function actorId(): ?int
    {
        return $this->actorId;
    }

    /** Database only for now; the email digest is step 8. */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    abstract public function type(): NotificationType;

    /** One line, the subject of the thing. */
    abstract public function title(): string;

    /** Where this takes you. */
    abstract public function url(): string;

    /** An excerpt or a second line. Optional. */
    public function body(): ?string
    {
        return null;
    }

    /**
     * The artist this is *about*, when there is one.
     *
     * Two jobs: it is how a per-artist mute is applied (see
     * App\Services\Notifier), and how blocking an artist suppresses
     * notifications about them.
     */
    public function aboutArtist(): ?Artist
    {
        return null;
    }

    /**
     * What makes two notifications "the same thing".
     *
     * Two with the same key fold together rather than stacking up, so five
     * posts from one band in an afternoon is one line in the bell. Null
     * means never fold -- correct for anything that names a specific
     * person's words, where a count would lose the point.
     */
    public function collapseKey(): ?string
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => $this->type()->value,
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->url(),
            'icon' => $this->type()->icon(),
            'artist' => $this->aboutArtist() === null ? null : [
                'id' => $this->aboutArtist()->id,
                'name' => $this->aboutArtist()->name,
            ],
            // Stored so the Notifier can recognise "the same thing" on a
            // later send without re-deriving it from a payload that may
            // have come from an older version of this class.
            'collapseKey' => $this->collapseKey(),
            // Folding increments this; one means "once".
            'count' => 1,
        ];
    }
}
