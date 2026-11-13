<?php

namespace App\Support;

/**
 * What Riff will tell you about. Every kind is anchored to an artist, a
 * thread or a show you have a relationship with — an unanchored stream is
 * the algorithmic feed the roadmap rules out, arriving by another door.
 *
 * Values are persisted in users.notification_preferences, so renaming a
 * case needs a migration. Labels and defaults live in
 * config('riff.notifications.types').
 */
enum NotificationType: string
{
    case Reply = 'reply';
    case NewFollower = 'new_follower';
    case WallPost = 'wall_post';
    case ArtistPost = 'artist_post';
    case ArtistShow = 'artist_show';

    /** Gates another band's listing: unanswered, their show stays unlisted. */
    case LineupInvite = 'lineup_invite';

    /** @return array<string, mixed> */
    public function config(): array
    {
        $config = config('riff.notifications.types.'.$this->value);

        return is_array($config) ? $config : [];
    }

    public function label(): string
    {
        return (string) ($this->config()['label'] ?? $this->value);
    }

    public function description(): string
    {
        return (string) ($this->config()['description'] ?? '');
    }

    public function icon(): string
    {
        return (string) ($this->config()['icon'] ?? 'notifications');
    }

    /** Whether a new account receives this kind. */
    public function onByDefault(): bool
    {
        return (bool) ($this->config()['default'] ?? true);
    }

    /**
     * Only kinds that arrive *because* you follow someone. A reply to your
     * own words is not a subscription.
     */
    public function respectsArtistMute(): bool
    {
        return match ($this) {
            self::ArtistPost, self::ArtistShow => true,
            default => false,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
