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
 * Members keeping their own page tidy. Three rules held in one place:
 *
 *  1. Artists hide, never destroy — every method is reversible and leaves
 *     the row where it was. Removal is a moderator's call, via a report.
 *  2. A moderator's decision outranks an artist's: unhide restores only
 *     what the artist hid, so a band cannot undo a platform decision.
 *  3. Every action is appended to the trail as a new row, so "who hid this
 *     and who put it back" stays answerable.
 */
class WallModeration
{
    /** Idempotent: hiding something already hidden logs nothing. */
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

    /** Only something *hidden* comes back; `removed` is not theirs to undo. */
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
     * Their existing posts stay up: "stop posting here" and "take down
     * what you said" are deliberately separate decisions.
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
     * A moderator-removed item is already gone, and must not be relabelled
     * as the artist's doing.
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

    /** @return Collection<int, ArtistWallBan> */
    public function bans(Artist $artist)
    {
        return $artist->wallBans()->with('user:id,name')->latest()->get();
    }
}
