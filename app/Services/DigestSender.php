<?php

namespace App\Services;

use App\Mail\WeeklyDigestMail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Deciding who gets a digest, and writing it.
 *
 * Three rules, held here so a scheduler, a command and a test cannot
 * disagree about them:
 *
 *  1. **Only people who asked.** `digest_frequency` is `off` or `weekly`;
 *     off means never, whatever else is true.
 *  2. **Never twice inside the window.** `digest_sent_at` is the cap, so a
 *     scheduler that fires twice, or someone running the command by hand,
 *     cannot write to the same person again this week.
 *  3. **Never an empty one.** An email with nothing in it is the fastest
 *     way to teach somebody to unsubscribe, so a person with no news is
 *     skipped *and their digest_sent_at is left alone* -- their window
 *     keeps growing until there is something worth sending.
 */
class DigestSender
{
    public function __construct(private WeeklyDigest $digest) {}

    /**
     * Send to everyone due. Returns how many were written to.
     */
    public function run(?int $limit = null): int
    {
        $limit ??= (int) config('riff.digest.batch_limit');
        $sent = 0;

        // get(), not each()/chunk(): sending updates digest_sent_at,
        // which is the very column this query filters on, so chunking
        // would re-query a shifting set and silently skip people. The
        // batch limit is what keeps this bounded instead.
        $due = User::query()
            ->where('digest_frequency', '!=', 'off')
            ->whereNotNull('email_verified_at')
            ->where(fn ($query) => $query
                ->whereNull('digest_sent_at')
                ->orWhere('digest_sent_at', '<=', $this->cutoff()->toDateTimeString()))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($due as $user) {
            if ($this->sendTo($user)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Write to one person if they are due and have news. Returns whether
     * anything was sent.
     */
    public function sendTo(User $user, bool $force = false): bool
    {
        if (! $force && ! $this->isDue($user)) {
            return false;
        }

        $digest = $this->digest->build($user);

        if (! $this->digest->hasAnything($digest)) {
            // Deliberately does not stamp digest_sent_at: their window
            // keeps growing until there is something to say.
            return false;
        }

        // Claim the slot before writing, with the same condition the
        // query used. Two overlapping runs -- a scheduler that fires
        // twice, a command run by hand while the cron is going -- both
        // reach here with the same person; only the one whose UPDATE
        // matched a row sends. Reading digest_sent_at and then writing it
        // would let both through.
        if (! $force && ! $this->claim($user)) {
            return false;
        }

        if ($force) {
            $user->forceFill(['digest_sent_at' => now()])->save();
        }

        Mail::to($user)->send(new WeeklyDigestMail($user, $digest, $this->unsubscribeUrl($user)));

        return true;
    }

    /**
     * Atomically take this person's slot for the week. Returns false when
     * another run already had it.
     */
    private function claim(User $user): bool
    {
        $claimed = User::query()
            ->whereKey($user->id)
            ->where(fn ($query) => $query
                ->whereNull('digest_sent_at')
                ->orWhere('digest_sent_at', '<=', $this->cutoff()->toDateTimeString()))
            ->update(['digest_sent_at' => now()]);

        if ($claimed > 0) {
            $user->forceFill(['digest_sent_at' => now()])->syncOriginal();
        }

        return $claimed > 0;
    }

    public function isDue(User $user): bool
    {
        if ($user->digest_frequency === 'off' || $user->email_verified_at === null) {
            return false;
        }

        return $user->digest_sent_at === null
            || $user->digest_sent_at->lte($this->cutoff());
    }

    /**
     * A signed link that works **without signing in**: requiring someone
     * to get back into an account before they can stop email is how
     * complaints become spam reports.
     */
    public function unsubscribeUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'digest.unsubscribe',
            now()->addDays((int) config('riff.digest.unsubscribe_days')),
            ['user' => $user->id],
        );
    }

    private function cutoff(): Carbon
    {
        return now()->subDays((int) config('riff.digest.min_days_between'));
    }
}
