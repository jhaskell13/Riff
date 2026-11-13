<?php

namespace App\Support;

/**
 * The kinds of thing Riff will tell you about.
 *
 * Deliberately short and deliberately *anchored*: every one of these is
 * about an artist, a thread, or a show you have a relationship with. There
 * is no "activity" type and no "trending" type, because a notification
 * stream that is not anchored is the algorithmic feed the roadmap rules
 * out, arriving by a different door.
 *
 * The value is persisted in users.notification_preferences and in each
 * notification's payload, so renaming a case needs a migration. Labels and
 * defaults live in config('riff.notifications.types') so the preferences
 * form always offers exactly what validation accepts.
 *
 * In-app messaging (Phase 4) is expected to add a case here and nothing
 * else: that is the point of putting the vocabulary in one enum.
 */
enum NotificationType: string
{
    /** Someone replied to your post, or to your comment. */
    case Reply = 'reply';

    /** Someone followed an artist you are a member of. */
    case NewFollower = 'new_follower';

    /** Someone posted on your artist's wall. */
    case WallPost = 'wall_post';

    /** An artist you follow posted an update. */
    case ArtistPost = 'artist_post';

    /** An artist you follow announced a show. */
    case ArtistShow = 'artist_show';

    /**
     * Your artist was added to a bill, or asked again after the date or
     * place moved. The one notification that gates something: an
     * unanswered invitation keeps a show off every public surface.
     */
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

    /** Whether a brand-new account receives this kind. */
    public function onByDefault(): bool
    {
        return (bool) ($this->config()['default'] ?? true);
    }

    /**
     * Whether this kind answers to a per-artist mute.
     *
     * The ones that arrive *because* you follow someone do. A reply to
     * your own words does not: you were talking to a person, not
     * subscribing to a band.
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
