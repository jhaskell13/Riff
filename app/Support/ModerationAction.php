<?php

namespace App\Support;

use App\Models\Artist;
use App\Models\Post;
use App\Models\Venue;

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
    case HideVenue = 'hide_venue';
    case ArchiveVenue = 'archive_venue';

    public function label(): string
    {
        return match ($this) {
            self::Dismiss => 'Dismiss',
            self::HideArtist => 'Hide from the map',
            self::ArchiveArtist => 'Archive the artist',
            self::RemovePost => 'Remove the post',
            // For a venue that closed or stopped hosting: its page (and,
            // from Step 3, its show history) stays.
            self::HideVenue => 'Hide the venue from the map',
            // For spam or a venue that never existed: the page 404s. A
            // soft delete, so still recoverable.
            self::ArchiveVenue => 'Take the venue down',
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
            self::HideVenue, self::ArchiveVenue => $subject instanceof Venue,
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
            self::HideVenue => $subject->forceFill(['show_on_map' => false])->save(),
            self::ArchiveVenue => $subject->delete(),
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
                [self::HideArtist, self::ArchiveArtist, self::RemovePost, self::HideVenue, self::ArchiveVenue, self::Dismiss],
                fn (self $action) => $action->appliesTo($subject),
            ),
        ));
    }
}
