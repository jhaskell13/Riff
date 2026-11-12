<?php

namespace App\Mail;

use App\Models\ListingReply;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A reply to a listing, relayed to its poster. Deliberately carries no
 * Reply-To: the replier's email address is never shared — only the
 * contact they typed — and the poster's address never reaches the replier
 * at all. Interim until in-app messaging (Phase 4).
 */
class ListingReplyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public ListingReply $reply) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Re: '.$this->reply->listing?->title.' — a reply on Riff');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.listing-reply', text: 'mail.listing-reply-text', with: [
            'listing' => $this->reply->listing,
            'replierName' => $this->reply->user?->name ?? 'Someone on Riff',
            // Not "message": mail views reserve $message for the Message.
            'replyText' => $this->reply->message,
            'contact' => $this->reply->contact,
            'url' => $this->reply->listing ? route('listings.show', $this->reply->listing) : url('/'),
        ]);
    }
}
