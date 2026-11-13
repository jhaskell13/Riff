<?php

namespace App\Contracts;

use App\Models\Artist;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Something a conversation can hang off: an artist's wall post, or a show's
 * thread.
 *
 * Comments went polymorphic in Phase 3 step 3 so show threads would not need
 * a phantom post row per show. This contract is the other half of that: it
 * lets everything *around* a comment -- who may post, who moderates, whether
 * the thread is still open -- ask the subject instead of branching on its
 * class. Without it, `instanceof Post` checks would have spread through the
 * controllers, the policies and the reader, and every new commentable kind
 * would mean finding them all again.
 *
 * Both questions answer to the same idea: **a thread belongs to an artist.**
 * A wall post belongs to the artist whose page it is; a show thread belongs
 * to the host artist, whose members listed the night.
 */
interface Commentable
{
    /**
     * The thread itself.
     *
     * @return MorphMany<Comment, covariant \Illuminate\Database\Eloquent\Model>
     */
    public function comments(): MorphMany;

    /**
     * Whose members moderate this thread and may answer in its voice.
     *
     * Null means nobody does -- a wall-less post left over from the old
     * global feed, or a show whose host has been archived. Callers must
     * treat null as "no thread here" rather than "open to everyone".
     */
    public function moderatingArtist(): ?Artist;

    /**
     * Where to send someone to read this thread in context: the artist's
     * page for a wall post, the show's page for a show thread.
     *
     * Null when there is nowhere to send them (a wall-less legacy post, or
     * a subject that has been taken down).
     */
    public function threadUrl(): ?string;

    /**
     * Which artist this user may speak *as* here, or null if none.
     *
     * On a wall that is the page's artist, if they are a member. On a show
     * it is the host **or any confirmed act on the bill** they belong to:
     * the other bands playing have as much standing to answer "what time
     * are you on?" as the one that listed it. Moderation still belongs to
     * the host alone (moderatingArtist), so speaking and policing are
     * deliberately different questions.
     */
    public function voiceArtistFor(User $user): ?Artist;

    /**
     * The artists with standing here, for labelling who is who: the wall's
     * artist, or a show's host plus its confirmed bill.
     *
     * @return Collection<int, Artist>
     */
    public function commentVoiceArtists(): Collection;

    /**
     * Whether the owning artist's `wall_mode` decides who may comment.
     *
     * True for a wall, which is the artist's own page and theirs to open or
     * close. **False for a show**, which is a public event: anyone with an
     * account can ask about the door time, and making people follow the
     * band first would be a strange tax on a practical question. Bans and
     * blocks still apply either way.
     */
    public function commentsFollowWallMode(): bool;

    /**
     * Whether this subject is accepting new comments at all, before any
     * question of *who* is asking.
     *
     * A show's thread closes after the night (see Show::acceptsComments);
     * a hidden or removed post takes no new replies.
     */
    public function acceptsComments(): bool;

    /**
     * Why it is closed, in words a reader can act on -- or null when it is
     * open. Saying "this thread closed after the show" beats hiding the box
     * and letting someone conclude Riff is broken.
     */
    public function commentsClosedReason(): ?string;
}
