<?php

namespace App\Support;

use App\Models\Artist;
use App\Models\Post;

/**
 * What a moderator can do about a report. Each case knows which kind of
 * subject it applies to, so the request can reject "archive this post"
 * before the controller has to think about it, and the moderation page can
 * offer only the buttons that mean something.
 *
 * Deliberately short of anything irreversible: hiding takes a pin off the
 * map, archiving is the same recoverable soft delete members already use,
 * and a removed post keeps its row. A pilot moderator should not be able to
 * destroy an artist's work with one click.
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

    /**
     * Whether this action can be taken on the given subject. Dismissing
     * applies to anything; everything else is specific to what it changes.
     */
    public function appliesTo(object $subject): bool
    {
        return match ($this) {
            self::Dismiss => true,
            self::HideArtist, self::ArchiveArtist => $subject instanceof Artist,
            self::RemovePost => $subject instanceof Post,
        };
    }

    /**
     * Carry out the action. Hiding and archiving both fire Artist model
     * events, which rotate the map cache; nothing here needs to do that by
     * hand.
     */
    public function apply(object $subject): void
    {
        match ($this) {
            self::Dismiss => null,
            self::HideArtist => $subject->forceFill(['show_on_map' => false])->save(),
            self::ArchiveArtist => $subject->delete(),
            // The row stays for the audit trail; scopePublished stops
            // showing it the moment the status changes.
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
