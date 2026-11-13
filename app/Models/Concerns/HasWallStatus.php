<?php

namespace App\Models\Concerns;

/**
 * The three-word status vocabulary posts and comments share, and the one
 * place that decides who may see which.
 *
 * It is a plain static list rather than a query scope on purpose. Most of
 * these queries are built inside eager-load closures (`with(['comments' =>
 * fn ($q) => ...])`), where the closure receives a Relation and a scope
 * call cannot be resolved -- by the reader or by static analysis. A list of
 * statuses fed to whereIn() reads the same from every position.
 */
trait HasWallStatus
{
    /** On the wall, visible to everyone. */
    public const PUBLISHED = 'published';

    /** Hidden by the artist whose wall it is. Reversible by them. */
    public const HIDDEN = 'hidden';

    /** Removed by a platform moderator. Not the artist's to undo. */
    public const REMOVED = 'removed';

    /**
     * What a viewer may see.
     *
     * The public sees published rows. A member of the artist also sees what
     * the artist hid, flagged, so an accidental hide can be undone where it
     * happened. A moderator-removed row is invisible to both: it is not the
     * artist's to see back.
     *
     * @return list<string>
     */
    public static function visibleStatuses(bool $includeHidden): array
    {
        return $includeHidden
            ? [self::PUBLISHED, self::HIDDEN]
            : [self::PUBLISHED];
    }
}
