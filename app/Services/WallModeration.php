<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\ArtistWallBan;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Support\WallAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Members keeping their own page tidy.
 *
 * **Three rules this class exists to hold in one place:**
 *
 *  1. *Artists hide; they never destroy.* Every method here is reversible
 *     and leaves the row where it was. Taking something off Riff is a
 *     platform moderator's call, reached by reporting it.
 *
 *  2. *A moderator's decision outranks an artist's.* Unhiding only ever
 *     restores something the artist themselves hid. If a moderator has
 *     since removed it, unhide is a no-op -- otherwise an artist could
 *     undo a platform decision from their own page.
 *
 *  3. *Every action is written to the trail*, as a new row, before the
 *     state changes. A hide and its undo are two events, so "who hid this,
 *     and who put it back" stays answerable.
 */
class WallModeration
{
    /**
     * Hide a post or comment from the public wall. Idempotent: hiding
     * something already hidden changes nothing and logs nothing.
     */
    public function hide(Artist $artist, User $actor, Post|Comment $subject, ?string $note = null): bool
    {
        if (! $this->isHideable($subject)) {
            return false;
        }

        return DB::transaction(function () use ($artist, $actor, $subject, $note) {
            $this->log($artist, $actor, WallAction::Hide, $subject, $note);
            $subject->forceFill(['status' => $subject::HIDDEN])->save();

            return true;
        });
    }

    /**
     * Put it back. Only something *hidden* comes back: a moderator's
     * `removed` is not an artist's to undo (rule 2).
     */
    public function unhide(Artist $artist, User $actor, Post|Comment $subject, ?string $note = null): bool
    {
        if ($subject->status !== $subject::HIDDEN) {
            return false;
        }

        return DB::transaction(function () use ($artist, $actor, $subject, $note) {
            $this->log($artist, $actor, WallAction::Unhide, $subject, $note);
            $subject->forceFill(['status' => $subject::PUBLISHED])->save();

            return true;
        });
    }

    /**
     * Stop someone posting on this wall. Their existing posts stay up --
     * hiding those is a separate decision, deliberately, so "I don't want
     * you posting here" and "I want what you said gone" stay distinct.
     */
    public function ban(Artist $artist, User $actor, User $listener, ?string $reason = null): bool
    {
        if ($artist->wallBans()->where('user_id', $listener->id)->exists()) {
            return false;
        }

        // A member can't be shut out of their own page.
        if ($listener->isMemberOf($artist)) {
            return false;
        }

        return DB::transaction(function () use ($artist, $actor, $listener, $reason) {
            $this->log($artist, $actor, WallAction::Ban, $listener, $reason);

            $artist->wallBans()->create([
                'user_id' => $listener->id,
                'banned_by' => $actor->id,
                'reason' => $reason,
            ]);

            return true;
        });
    }

    public function unban(Artist $artist, User $actor, User $listener, ?string $note = null): bool
    {
        $ban = $artist->wallBans()->where('user_id', $listener->id)->first();

        if ($ban === null) {
            return false;
        }

        return DB::transaction(function () use ($artist, $actor, $listener, $note, $ban) {
            $this->log($artist, $actor, WallAction::Unban, $listener, $note);
            $ban->delete();

            return true;
        });
    }

    /**
     * Something is hideable while it is on the wall. A moderator-removed
     * item is already gone and must not be re-labelled as the artist's
     * doing -- that would let an artist take the blame, or the credit, for
     * a platform decision.
     */
    private function isHideable(Post|Comment $subject): bool
    {
        return $subject->status === $subject::PUBLISHED;
    }

    private function log(
        Artist $artist,
        User $actor,
        WallAction $action,
        Model $subject,
        ?string $note,
    ): void {
        $event = $artist->wallModerationEvents()->make([
            'actor_id' => $actor->id,
            'action' => $action,
            'note' => $note === null || trim($note) === '' ? null : trim($note),
        ]);

        $event->subject()->associate($subject);
        $event->save();
    }

    /**
     * Who is banned from this wall, for the members' own list.
     *
     * @return Collection<int, ArtistWallBan>
     */
    public function bans(Artist $artist)
    {
        return $artist->wallBans()->with('user:id,name')->latest()->get();
    }
}
