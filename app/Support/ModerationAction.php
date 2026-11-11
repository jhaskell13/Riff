<?php

namespace App\Support;

use App\Models\Artist;
use App\Models\Post;

/**
 * What a moderator can do about a report. Each case knows which subjects it
 * applies to, and none is irreversible: a pilot moderator should not be
 * able to destroy an artist's work with one click.
 */
enum ModerationAction: string
{
    case Dismiss = 'dismiss';
    case HideArtist = 'hide_artist';
    case ArchiveArtist = 'archive_artist';
    case RemovePost = 'remove_post';

    public function label(): string
    {
        return match ($this) {
            self::Dismiss => 'Dismiss',
            self::HideArtist => 'Hide from the map',
            self::ArchiveArtist => 'Archive the artist',
            self::RemovePost => 'Remove the post',
        };
    }

    public function status(): ReportStatus
    {
        return $this === self::Dismiss ? ReportStatus::Dismissed : ReportStatus::Resolved;
    }

    /** Whether this action can be taken on the given subject. */
    public function appliesTo(object $subject): bool
    {
        return match ($this) {
            self::Dismiss => true,
            self::HideArtist, self::ArchiveArtist => $subject instanceof Artist,
            self::RemovePost => $subject instanceof Post,
        };
    }

    /**
     * Carry out the action. The artist changes fire model events, which is
     * what rotates the map cache.
     */
    public function apply(object $subject): void
    {
        match ($this) {
            self::Dismiss => null,
            self::HideArtist => $subject->forceFill(['show_on_map' => false])->save(),
            self::ArchiveArtist => $subject->delete(),
            // The row stays for the audit trail; the feed filters on status.
            self::RemovePost => $subject->forceFill(['status' => 'removed'])->save(),
        };
    }

    /**
     * The actions worth offering for one subject, dismissal last.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function optionsFor(object $subject): array
    {
        return array_values(array_map(
            fn (self $action) => ['value' => $action->value, 'label' => $action->label()],
            array_filter(
                [self::HideArtist, self::ArchiveArtist, self::RemovePost, self::Dismiss],
                fn (self $action) => $action->appliesTo($subject),
            ),
        ));
    }
}
