<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The weekly digest.
 *
 * **A plain HTML view, never a markdown mailable** -- the same rule as
 * ListingReplyMail, for the same reason: markdown mail parses the whole
 * template, so a band's post could inject links into every subscriber's
 * inbox. Blade escapes by default and that is exactly what is wanted here.
 *
 * And never a variable named `$message`: Laravel reserves it for the
 * Message object in mail views, and it silently clobbered the reply text
 * once already.
 */
class WeeklyDigestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $digest
     */
    public function __construct(
        public User $user,
        public array $digest,
        public string $unsubscribeUrl,
    ) {}

    public function envelope(): Envelope
    {
        $where = trim((string) $this->user->city) ?: 'your scene';

        return new Envelope(subject: 'This week in '.$where);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.weekly-digest',
            text: 'mail.weekly-digest-text',
            with: [
                'name' => $this->user->name,
                'myShows' => $this->digest['myShows'],
                'followedShows' => $this->digest['followedShows'],
                'updates' => $this->digest['updates'],
                'nearbyShows' => $this->digest['nearby']['shows'],
                'listings' => $this->digest['nearby']['listings'],
                'sceneUrl' => route('scene.home'),
                'settingsUrl' => route('notifications.index'),
                'unsubscribeUrl' => $this->unsubscribeUrl,
            ],
        );
    }
}
