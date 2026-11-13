<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\RiffNotification;
use App\Support\NotificationType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Sending a notification, with the three questions that have to be asked
 * every time asked in exactly one place.
 *
 *  1. **Does this person want it?** Their own preference for the kind, and
 *     — for the kinds that arrive because of a follow — their mute on that
 *     particular artist.
 *
 *  2. **Have they hidden the artist it is about?** Blocking means "I do not
 *     want to see this artist". A notification about them would walk
 *     straight through that, which is worse than the feed entry it already
 *     suppresses.
 *
 *  3. **Is this already in their bell?** Five posts from one band in an
 *     afternoon is one line, not five. Folding keeps the bell from becoming
 *     the stream this product deliberately does not have.
 *
 * Every trigger goes through here. A caller that reaches for
 * `$user->notify()` directly skips all three.
 */
class Notifier
{
    /**
     * @param  Collection<int, User>|array<int, User>|User  $recipients
     * @param  bool  $immediately  Bypass the queue. For seeders and
     *                             commands, where no worker is running and
     *                             a queued notification would simply sit
     *                             in the jobs table.
     */
    public function send(
        Collection|array|User $recipients,
        RiffNotification $notification,
        bool $immediately = false,
    ): void {
        $recipients = $recipients instanceof User
            ? collect([$recipients])
            : collect($recipients);

        $wanted = $recipients
            ->filter(fn (User $user) => $user->exists)
            // Never tell someone about their own action. Doing it here
            // rather than at each trigger is the point: every trigger
            // could remember, and one of them would not.
            ->reject(fn (User $user) => $notification->actorId() !== null
                && $user->id === $notification->actorId())
            ->unique('id')
            ->filter(fn (User $user) => $this->wants($user, $notification));

        foreach ($wanted as $user) {
            if ($this->folded($user, $notification)) {
                continue;
            }

            $immediately
                ? Notification::sendNow($user, $notification)
                : Notification::send($user, $notification);
        }
    }

    /**
     * Whether this person has asked for this kind, from this artist.
     */
    public function wants(User $user, RiffNotification $notification): bool
    {
        $type = $notification->type();

        if (! $user->wantsNotification($type)) {
            return false;
        }

        $artist = $notification->aboutArtist();

        if ($artist === null) {
            return true;
        }

        // Hidden means hidden, whatever the preference says.
        if ($user->blocks($artist)) {
            return false;
        }

        // A per-artist mute only applies to the kinds that arrive because
        // you follow someone; a reply to your own words is not a
        // subscription.
        if ($type->respectsArtistMute() && ! $user->wantsNotificationsFrom($artist)) {
            return false;
        }

        return true;
    }

    /**
     * Fold into an unread notification about the same thing, if there is
     * one inside the window. Returns true when it folded, meaning no new
     * row should be written.
     *
     * Written against the table rather than through the notification
     * channel on purpose: the whole point is to *not* create a second row,
     * and a channel's job is to create one.
     */
    private function folded(User $user, RiffNotification $notification): bool
    {
        $key = $notification->collapseKey();
        $minutes = (int) config('riff.notifications.collapse_window_minutes');

        if ($key === null || $minutes <= 0) {
            return false;
        }

        $existing = DB::table('notifications')
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->id)
            ->whereNull('read_at')
            ->where('created_at', '>=', now()->subMinutes($minutes)->toDateTimeString())
            ->where('type', $notification::class)
            ->orderByDesc('created_at')
            ->first();

        if ($existing === null) {
            return false;
        }

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $existing->data, true) ?: [];

        if (($data['collapseKey'] ?? null) !== $key) {
            return false;
        }

        // The newest wording wins -- "Ana replied" is more use than the
        // reply from this morning -- and the count says how many folded in.
        $fresh = $notification->toDatabase($user);
        $fresh['collapseKey'] = $key;
        $fresh['count'] = (int) ($data['count'] ?? 1) + 1;

        DB::table('notifications')
            ->where('id', $existing->id)
            ->update([
                'data' => json_encode($fresh),
                // Bumped so it sorts to the top again: something new
                // happened, even if it folded.
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);

        return true;
    }

    /**
     * The preference map a form should render: every kind, with whether
     * this account currently receives it.
     *
     * @return list<array<string, mixed>>
     */
    public function preferencesFor(User $user): array
    {
        return array_map(fn (NotificationType $type) => [
            'value' => $type->value,
            'label' => $type->label(),
            'description' => $type->description(),
            'icon' => $type->icon(),
            'enabled' => $user->wantsNotification($type),
            // The lineup invitation cannot be turned off: switching it off
            // would quietly break other people's listings.
            'required' => (bool) ($type->config()['required'] ?? false),
        ], NotificationType::cases());
    }
}
