<?php

namespace App\Services;

use App\Contracts\Commentable;
use App\Models\Artist;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Notifications\ArtistPosted;
use App\Notifications\ReplyReceived;
use App\Notifications\WallPostReceived;
use App\Support\AuthorContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Writing to an artist's wall.
 *
 * **The voice is resolved only here.** A request may *ask* to speak as the
 * artist; only membership grants it. The moment two places can decide who
 * is speaking, one of them will be wrong.
 */
class Wall
{
    public function __construct(private Notifier $notifier) {}

    /** Asking to be the band is not being the band; non-members fall back to fan. */
    public function voiceFor(User $user, Artist $artist, bool $asArtist): AuthorContext
    {
        return $asArtist && $user->can('postAsArtist', $artist)
            ? AuthorContext::Artist
            : AuthorContext::Fan;
    }

    /** Created through the user's own relation so the author can't be mass-assigned. */
    public function post(User $user, Artist $artist, string $content, bool $asArtist): Post
    {
        $voice = $this->voiceFor($user, $artist, $asArtist);

        $post = $user->posts()->create([
            'artist_id' => $artist->id,
            'author_context' => $voice,
            'content' => $content,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->announce($post, $artist, $user, $voice);

        return $post;
    }

    /** The band speaking reaches its fans; a fan speaking reaches the band. */
    private function announce(Post $post, Artist $artist, User $author, AuthorContext $voice): void
    {
        if ($voice->isArtist()) {
            $this->notifier->send(
                $artist->followers()->get(),
                (new ArtistPosted($post, $artist))->causedBy($author),
            );

            return;
        }

        $this->notifier->send(
            $artist->members()->get(),
            (new WallPostReceived($post, $artist, $author->name))->causedBy($author),
        );
    }

    /**
     * Takes the contract, not a model: a wall post and a show answer the
     * same way. artist_id is set only for the artist voice, so a fan's
     * reply can never render as the band by accident.
     */
    public function comment(
        User $user,
        Commentable&Model $subject,
        string $content,
        bool $asArtist,
        ?int $parentId = null,
    ): Comment {
        // Null means no standing to be anyone but themselves.
        $artist = $subject->voiceArtistFor($user);
        $voice = $artist !== null && $asArtist
            ? AuthorContext::Artist
            : AuthorContext::Fan;

        $comment = $subject->comments()->create([
            'user_id' => $user->id,
            'author_context' => $voice,
            'artist_id' => $voice->isArtist() ? $artist?->id : null,
            'parent_id' => $parentId,
            'content' => $content,
            'status' => Comment::PUBLISHED,
        ]);

        $this->notifyRepliedTo($comment, $subject, $user, $voice, $artist);

        return $comment;
    }

    /**
     * A reply answers its comment's author; a top-level comment answers
     * the post's. A show has no author, so its thread reaches the host's
     * members — the people expected to field questions about the night.
     */
    private function notifyRepliedTo(
        Comment $comment,
        Commentable&Model $subject,
        User $author,
        AuthorContext $voice,
        ?Artist $artist,
    ): void {
        $name = $voice->isArtist() && $artist !== null ? $artist->name : $author->name;

        if ($comment->parent_id !== null) {
            $parent = Comment::find($comment->parent_id);
            $recipients = $parent === null ? [] : $this->peopleBehind($parent, $parent->user_id);

            $this->notifier->send(
                $recipients,
                (new ReplyReceived($comment, $name, 'comment'))->causedBy($author),
            );

            return;
        }

        if ($subject instanceof Post) {
            $this->notifier->send(
                $this->peopleBehind($subject, $subject->user_id),
                (new ReplyReceived($comment, $name, 'post'))->causedBy($author),
            );

            return;
        }

        // A show thread: the host's members field it.
        //
        // The thread's *owner*, not the speaker's voice artist -- a
        // stranger asking about a show has no voice artist at all, and
        // using one meant their question reached nobody.
        $owner = $subject->moderatingArtist();

        if ($owner !== null) {
            $this->notifier->send(
                $owner->members()->get(),
                (new ReplyReceived($comment, $name, 'show'))->causedBy($author),
            );
        }
    }

    /**
     * Answering an artist-voice post answers *the band* — every member,
     * not just whoever typed it.
     *
     * @return Collection<int, User>|list<User>
     */
    private function peopleBehind(Post|Comment $subject, ?int $authorId): Collection|array
    {
        if ($subject->author_context === AuthorContext::Artist && $subject->artist !== null) {
            return $subject->artist->members()->get();
        }

        $author = $authorId === null ? null : User::find($authorId);

        return $author === null ? [] : [$author];
    }
}
