<?php

namespace App\Support;

/**
 * The voice a post or comment speaks in.
 *
 * This is the thing Phase 3 exists to make explicit. The old schema paired
 * a user_id with a nullable artist_id, so "the band announced this" and "a
 * fan wrote this" were the same shape and had to be inferred. They are
 * different statements about who is speaking, and a wall is unreadable if
 * a reader cannot tell them apart.
 *
 * The value is never taken from a request: it is set from the result of an
 * authorization check (membership means you may speak as the artist), so
 * there is no endpoint that lets someone choose to be the band.
 */
enum AuthorContext: string
{
    /** The artist speaking as itself, written by one of its members. */
    case Artist = 'artist';

    /** A listener speaking as themselves. */
    case Fan = 'fan';

    public function isArtist(): bool
    {
        return $this === self::Artist;
    }

    /**
     * Whether the person who typed it may be named publicly. A band speaks
     * as the band -- which member pressed post is internal.
     */
    public function namesTheUser(): bool
    {
        return $this === self::Fan;
    }
}
