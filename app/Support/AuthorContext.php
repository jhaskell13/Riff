<?php

namespace App\Support;

/**
 * The voice a post or comment speaks in.
 *
 * Never taken from a request: set from an authorization result, so there
 * is no endpoint that lets someone choose to be the band.
 */
enum AuthorContext: string
{
    case Artist = 'artist';
    case Fan = 'fan';

    public function isArtist(): bool
    {
        return $this === self::Artist;
    }

    /** A band speaks as the band; which member typed it stays internal. */
    public function namesTheUser(): bool
    {
        return $this === self::Fan;
    }
}
