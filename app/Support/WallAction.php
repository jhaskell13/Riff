<?php

namespace App\Support;

/**
 * What a member may do to keep their own page tidy.
 *
 * Deliberately narrower than App\Support\ModerationAction, which is the
 * *platform* moderator's vocabulary and includes taking things down. An
 * artist can only ever hide and unhide: the row stays, the fan keeps their
 * words, and every action here has an inverse. Removing something from Riff
 * altogether is reached by reporting it, so a second person decides.
 */
enum WallAction: string
{
    case Hide = 'hide';
    case Unhide = 'unhide';
    case Ban = 'ban';
    case Unban = 'unban';

    public function label(): string
    {
        return match ($this) {
            self::Hide => 'Hidden',
            self::Unhide => 'Unhidden',
            self::Ban => 'Removed from the wall',
            self::Unban => 'Allowed back',
        };
    }

    /** The action that undoes this one. */
    public function inverse(): self
    {
        return match ($this) {
            self::Hide => self::Unhide,
            self::Unhide => self::Hide,
            self::Ban => self::Unban,
            self::Unban => self::Ban,
        };
    }
}
