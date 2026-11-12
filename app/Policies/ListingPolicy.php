<?php

namespace App\Policies;

use App\Models\Listing;
use App\Models\User;

/**
 * Listings are public. The poster — and, for a listing posted as an
 * artist, that artist's members — manage it. Anyone else signed in may
 * reply once, while it is active.
 */
class ListingPolicy
{
    public function view(?User $user, Listing $listing): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Listing $listing): bool
    {
        return $listing->closed_at === null && $listing->isManagedBy($user);
    }

    public function close(User $user, Listing $listing): bool
    {
        return $this->update($user, $listing);
    }

    public function renew(User $user, Listing $listing): bool
    {
        return $listing->can_renew && $listing->isManagedBy($user);
    }

    /** Not your own listing, and only while it is open. */
    public function reply(User $user, Listing $listing): bool
    {
        return $listing->is_active && ! $listing->isManagedBy($user);
    }
}
