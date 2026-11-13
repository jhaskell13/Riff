<?php

namespace App\Notifications;

use App\Contracts\Commentable;
use App\Models\Artist;
use App\Models\Comment;
use App\Support\NotificationType;
use Illuminate\Support\Str;

/**
 * Someone answered your post, or your comment.
 *
 * Never folded: a reply names a person and what they said, and "3 replies"
 * would throw away the only part worth reading.
 */
class ReplyReceived extends RiffNotification
{
    public function __construct(
        private readonly Comment $reply,
        private readonly string $authorName,
        private readonly string $target,
    ) {}

    public function type(): NotificationType
    {
        return NotificationType::Reply;
    }

    public function title(): string
    {
        return "{$this->authorName} replied to your {$this->target}";
    }

    public function body(): ?string
    {
        return Str::limit($this->reply->content, 140, '…');
    }

    /**
     * Straight to the reply, not just the page it is on: the thread may be
     * long, and "someone answered you" is useless if you then have to go
     * looking. The client scrolls to the anchor and flashes it.
     */
    public function url(): string
    {
        $thread = $this->reply->threadUrl();

        return $thread === null
            ? route('scene.home')
            : $thread.'#comment-'.$this->reply->id;
    }

    public function aboutArtist(): ?Artist
    {
        return $this->reply->commentable instanceof Commentable
            ? $this->reply->commentable->moderatingArtist()
            : null;
    }
}
