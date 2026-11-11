<?php

namespace App\Policies;

use App\Models\LineupEntry;
use App\Models\User;

/**
 * Lineup consent: only members of the artist named on an entry may confirm
 * or decline it. The host's own entry can't be declined — hosting a show
 * means playing it; to drop out, the host cancels the show.
 */
class LineupEntryPolicy
{
    public function respond(User $user, LineupEntry $entry): bool
    {
        $show = $entry->show;

        return $entry->artist !== null
            && $show !== null
            && ! $show->is_cancelled
            && $entry->artist_id !== $show->host_artist_id
            && $user->isMemberOf($entry->artist);
    }
}
