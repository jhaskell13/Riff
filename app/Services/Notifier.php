<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\RiffNotification;
use App\Support\NotificationType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Sending a notification, with the three questions asked in one place:
 * does this person want the kind, have they blocked the artist it is
 * about, and is it already in their bell.
 *
 * Every trigger goes through here — `$user->notify()` skips all three.
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
            // Here rather than at each trigger: one of them would forget.
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

    /** Whether this person asked for this kind, from this artist. */
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

        // Blocking outranks any preference.
        if ($user->blocks($artist)) {
            return false;
        }

        // Mutes only apply to follow-driven kinds.
        if ($type->respectsArtistMute() && ! $user->wantsNotificationsFrom($artist)) {
            return false;
        }

        return true;
    }

    /**
     * Fold into an unread notification about the same thing. True means no
     * new row should be written.
     *
     * Against the table rather than the channel on purpose: the point is
     * to *not* create a second row, and creating one is a channel's job.
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

        // Newest wording wins; the count says how many folded in.
        $fresh = $notification->toDatabase($user);
        $fresh['collapseKey'] = $key;
        $fresh['count'] = (int) ($data['count'] ?? 1) + 1;

        DB::table('notifications')
            ->where('id', $existing->id)
            ->update([
                'data' => json_encode($fresh),
                // Sorts to the top again: something new happened.
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);

        return true;
    }

    /** @return list<array<string, mixed>> */
    public function preferencesFor(User $user): array
    {
        return array_map(fn (NotificationType $type) => [
            'value' => $type->value,
            'label' => $type->label(),
            'description' => $type->description(),
            'icon' => $type->icon(),
            'enabled' => $user->wantsNotification($type),
            // Switching this off would break other people's listings.
            'required' => (bool) ($type->config()['required'] ?? false),
        ], NotificationType::cases());
    }
}
