<?php

namespace App\Policies;

use App\Models\Report;
use App\Models\User;

/**
 * Reporting is open to anyone with an account; acting on reports is not.
 * Moderators are flagged on the users table and granted only by the
 * riff:moderator command, so this is the one place that reads the flag.
 */
class ReportPolicy
{
    /**
     * Anyone signed in and verified may report something. Flooding is held
     * back by the "reports" limiter, not by authorization.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * The report queue, including its settled history.
     */
    public function viewAny(User $user): bool
    {
        return $this->isModerator($user);
    }

    public function view(User $user, Report $report): bool
    {
        return $this->isModerator($user);
    }

    /**
     * Acting on a report. A moderator may act on one about an artist they
     * are a member of: refusing would let anyone shield their own identity
     * by joining it, and the audit trail records who decided what.
     */
    public function resolve(User $user, Report $report): bool
    {
        return $this->isModerator($user);
    }

    /**
     * Cast rather than read: a User instance that has not been round-tripped
     * through the database (a freshly made one, in tests) has no value for
     * the column at all, and null is not an answer to "may they?".
     */
    private function isModerator(User $user): bool
    {
        return (bool) $user->is_moderator;
    }
}
