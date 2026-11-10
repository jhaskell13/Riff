<?php

namespace App\Support;

/**
 * Which door a visitor came in through: finding music, or being found.
 * Stored on the user, not the session -- the artist path has to survive the
 * email-verification hop, whose link is often opened in another browser.
 */
enum OnboardingIntent: string
{
    case Fan = 'fan';
    case Artist = 'artist';

    /**
     * An intent for a recognised value, null for anything else -- a stray
     * query string is not worth a validation error.
     *
     * @param  mixed  $value
     */
    public static function parse($value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
