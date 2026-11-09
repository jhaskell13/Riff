<?php

namespace App\Policies;

use App\Models\Artist;
use App\Models\User;

class ArtistPolicy
{
    /**
     * Any authenticated user may view an artist profile.
     */
    public function view(User $user, Artist $artist): bool
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
