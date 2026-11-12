<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Venue;

/**
 * Venues are community-submitted and published immediately, then
 * moderated after the fact through reports. Only the submitter edits for
 * now; claiming a venue (an owner or booker taking it over) is Phase 4.
 */
class VenuePolicy
{
    /** Venue pages are public; the route is outside the auth group. */
    public function view(?User $user, Venue $venue): bool
    {
        return true;
    }

    /** Any verified account; flooding is the venues limiter's job. */
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Venue $venue): bool
    {
        return $venue->submitted_by !== null && $venue->submitted_by === $user->id;
    }
}
