<?php

namespace App\Policies;

use App\Models\Report;
use App\Models\User;

/**
 * Reporting is open to anyone with an account; acting on reports is not.
 * This is the only reader of users.is_moderator.
 */
class ReportPolicy
{
    /** Anyone signed in may report; flooding is the limiter's job. */
    public function create(User $user): bool
    {
        return true;
    }

    public function viewAny(User $user): bool
    {
        return $this->isModerator($user);
    }

    public function view(User $user, Report $report): bool
    {
        return $this->isModerator($user);
    }

    /**
     * Deliberately not excluding a moderator's own artists: refusing would
     * let anyone shield an identity by joining it, and the audit trail
     * records who decided what.
     */
    public function resolve(User $user, Report $report): bool
    {
        return $this->isModerator($user);
    }

    /** Cast: a never-persisted User has no value for the column at all. */
    private function isModerator(User $user): bool
    {
        return (bool) $user->is_moderator;
    }
}
