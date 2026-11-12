<?php

namespace App\Policies;

use App\Models\Show;
use App\Models\User;

/**
 * Shows are public. They are listed by members of an artist (the host),
 * and only the host's members manage the listing; other artists on the
 * bill answer for their own slot (LineupEntryPolicy).
 */
class ShowPolicy
{
    public function view(?User $user, Show $show): bool
    {
        return true;
    }

    /**
     * Anyone in at least one artist. Which artist hosts is checked against
     * ArtistPolicy::update on the chosen host.
     */
    public function create(User $user): bool
    {
        return $user->artists()->exists();
    }

    /** Host members, until the show is cancelled. */
    public function update(User $user, Show $show): bool
    {
        return ! $show->is_cancelled && $this->isHostMember($user, $show);
    }

    public function cancel(User $user, Show $show): bool
    {
        return $this->update($user, $show);
    }

    /** Going/interested only means something before the night is over. */
    public function rsvp(User $user, Show $show): bool
    {
        return ! $show->is_cancelled && ! $show->has_passed;
    }

    private function isHostMember(User $user, Show $show): bool
    {
        // hostArtist resolves to null once archived: an archived artist's
        // shows can no longer be managed (nor are they listed).
        return $show->hostArtist !== null && $user->isMemberOf($show->hostArtist);
    }
}
