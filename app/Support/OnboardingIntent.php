<?php

namespace App\Support;

/**
 * Which door a visitor came in through. The map is public, so people arrive
 * with one of two goals: to find music made near them, or to be found making
 * it. The answer decides where registration lands them -- a listener back on
 * the map, an artist on the form that puts them on it -- and it has to
 * survive the email-verification hop in between, which is why it is stored
 * on the user rather than kept in the session (the verification link is
 * routinely opened in another browser).
 */
enum OnboardingIntent: string
{
    case Fan = 'fan';
    case Artist = 'artist';

    /**
     * Accepts anything, returns an intent only for a value we recognise.
     * Query strings and old links are not worth a validation error.
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
