<?php

namespace App\Policies;

use App\Models\Artist;
use App\Models\LiveStream;
use App\Models\User;

class LiveStreamPolicy
{
    /**
     * Any authenticated user may watch a stream.
     */
    public function view(User $user, LiveStream $liveStream): bool
    {
        return true;
    }

    /**
     * Only members of the artist may start a stream for it.
     */
    public function create(User $user, Artist $artist): bool
    {
        return $user->isMemberOf($artist);
    }

    /**
     * Only members of the stream's artist may manage it
     * (edit, start, end, send broadcast data).
     */
    public function manage(User $user, LiveStream $liveStream): bool
    {
        $artist = $liveStream->artist;

        return $artist !== null && $user->isMemberOf($artist);
    }
}
