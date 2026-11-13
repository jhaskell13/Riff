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
 * **The voice is resolved in exactly one place: voiceFor().** A request may
 * *ask* to speak as the artist; only membership grants it. Nothing else in
 * the app may set author_context, for the same reason nothing but
 * Lineup::sync() may write lineup_entries.status -- the moment two places
 * can decide who is speaking, one of them will be wrong.
 */
class Wall
{
    public function __construct(private Notifier $notifier) {}

    /**
     * The voice this user actually gets. Asking to be the band is not
     * being the band: a non-member who asks is quietly a fan, because the
     * fallback is harmless and an error here would be a dead end for
     * someone who simply isn't in the band.
     */
    public function voiceFor(User $user, Artist $artist, bool $asArtist): AuthorContext
    {
        return $asArtist && $user->can('postAsArtist', $artist)
            ? AuthorContext::Artist
            : AuthorContext::Fan;
    }

    /**
     * Post on an artist's wall. Created through the user's own relation so
     * the author cannot be mass-assigned, per the project's rule.
     */
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

    /**
     * Tell the people a post concerns.
     *
     * The band speaking reaches its fans; a fan speaking reaches the band.
     * Nobody is told about their own post -- the Notifier drops the actor,
     * so each trigger does not have to remember to.
     */
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
     * Reply on a wall post or a show thread -- whatever the subject, the
     * rule is the same, which is why this takes the contract rather than a
     * model. artist_id records *which* artist is speaking, and is set only
     * for the artist voice: on a fan's comment it stays null, so a fan's
     * reply can never render as the band by accident.
     */
    public function comment(
        User $user,
        Commentable&Model $subject,
        string $content,
        bool $asArtist,
        ?int $parentId = null,
    ): Comment {
        // Which artist this user may speak as here -- the wall's artist, or
        // on a show the host or any confirmed act on the bill they belong
        // to. Null means they have no standing to be anyone but themselves.
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
     * Tell whoever was answered.
     *
     * Who that is depends on what was answered: a reply under a comment
     * answers its author; a top-level comment answers whoever wrote the
     * post. On a show thread a top-level comment answers nobody in
     * particular -- a show has no author -- so it reaches the host's
     * members, who are the people expected to field questions about it.
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
     * Who to tell about something written in a given voice.
     *
     * An artist-voice post belongs to the band, so answering it answers
     * *the band* -- every member, not just whichever one happened to type
     * it. A fan's words belong to the fan.
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
