<?php

namespace App\Policies;

use App\Models\Artist;
use App\Models\User;

class ArtistPolicy
{
    /**
     * Anyone may view an artist, including guests: profiles and map cards
     * are public. (The profile page itself is still behind auth middleware
     * until the guest experience ships.)
     */
    public function view(?User $user, Artist $artist): bool
    {
        return true;
    }

    /**
     * Any authenticated user may create an artist identity.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Any authenticated user may follow a visible artist. Archived artists
     * never reach here -- route binding resolves only untrashed records.
     */
    public function follow(User $user, Artist $artist): bool
    {
        return true;
    }

    /**
     * Members cannot block their own identity -- it would vanish from their
     * own map like a bug, and show_on_map already exists for that.
     */
    public function block(User $user, Artist $artist): bool
    {
        return ! $user->isMemberOf($artist);
    }

    /**
     * Whether this user may speak *as* the artist -- post updates in its
     * voice, reply as the band. Membership is the authority; following is
     * not, and never becomes it.
     */
    public function postAsArtist(User $user, Artist $artist): bool
    {
        return $user->isMemberOf($artist);
    }

    /**
     * Whether this user may post on the artist's wall *as themselves*.
     *
     * The artist chooses who can, through wall_mode:
     *   fans         -- listeners who follow them (the default)
     *   everyone     -- any verified user, if the artist opts in
     *   members_only -- nobody; the wall is closed
     *
     * Members always can, whatever the mode: a member shut out of their own
     * page by their own setting would read as a bug. A listener who has
     * blocked the artist cannot -- they have said they do not want to see
     * this artist at all, and posting there contradicts that.
     */
    public function postOnWall(User $user, Artist $artist): bool
    {
        if ($user->isMemberOf($artist)) {
            return true;
        }

        if ($user->blocks($artist)) {
            return false;
        }

        return match ($artist->wall_mode) {
            'everyone' => true,
            'fans' => $user->follows($artist),
            default => false,
        };
    }

    /**
     * Only members may edit the artist.
     */
    public function update(User $user, Artist $artist): bool
    {
        return $user->isMemberOf($artist);
    }

    /**
     * Only members may archive the artist.
     */
    public function delete(User $user, Artist $artist): bool
    {
        return $user->isMemberOf($artist);
    }

    /**
     * Only members may restore an archived artist. Queried with
     * trashed artists included, since the subject is soft-deleted.
     */
    public function restore(User $user, Artist $artist): bool
    {
        return $user->artists()->withTrashed()->whereKey($artist->id)->exists();
    }
}
