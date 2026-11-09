<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Map
    |--------------------------------------------------------------------------
    |
    | default_center is used when the viewer has no geocoded location yet.
    | max_markers bounds a single viewport query so a zoomed-out map cannot
    | pull the whole artists table.
    |
    */

    'map' => [
        'default_center' => [
            'lat' => (float) env('RIFF_MAP_DEFAULT_LAT', 41.7637111),
            'lng' => (float) env('RIFF_MAP_DEFAULT_LNG', -72.6850932),
        ],
        'max_markers' => (int) env('RIFF_MAP_MAX_MARKERS', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Geocoder
    |--------------------------------------------------------------------------
    */

    'geocoder' => [
        'timeout_seconds' => (int) env('RIFF_GEOCODER_TIMEOUT', 5),
        'cache_ttl_days' => (int) env('RIFF_GEOCODER_CACHE_TTL_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Artist profiles
    |--------------------------------------------------------------------------
    |
    | types are the kinds of music maker an identity can represent; the keys
    | are persisted in artists.artist_type, so renaming a key needs a
    | migration. max_genres bounds how many genres one artist may claim,
    | keeping the map's genre filter meaningful.
    |
    */

    'artist' => [
        'types' => [
            'band' => 'Band',
            'solo' => 'Solo artist',
            'rapper' => 'Rapper / MC',
            'producer' => 'Producer / Beatmaker',
            'dj' => 'DJ',
            'other' => 'Other',
        ],
        'max_genres' => (int) env('RIFF_ARTIST_MAX_GENRES', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Genres
    |--------------------------------------------------------------------------
    |
    | The curated genre vocabulary. GenreSeeder reconciles this list into the
    | genres table, so adding a name here and re-seeding is enough to offer
    | it. Removing a name leaves existing rows alone; retire them by hand so
    | artists never lose a genre silently.
    |
    */

    'genres' => [
        'Rock', 'Indie', 'Alternative', 'Punk', 'Hardcore', 'Metal',
        'Emo', 'Shoegaze', 'Post-Rock', 'Pop', 'Hip-Hop', 'R&B',
        'Soul', 'Funk', 'Jazz', 'Blues', 'Folk', 'Americana',
        'Country', 'Bluegrass', 'Singer-Songwriter', 'Electronic',
        'House', 'Techno', 'Ambient', 'Experimental', 'Reggae',
        'Latin', 'Afrobeat', 'Classical', 'Gospel', 'Ska',
    ],

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    |
    | Artist photos live on this disk. Local development uses the public disk;
    | set RIFF_MEDIA_DISK=s3 to move uploads to a bucket without code changes.
    | Uploads are re-encoded on the way in, so max_edge_pixels also bounds
    | what is ever written to disk.
    |
    */

    'media' => [
        'disk' => env('RIFF_MEDIA_DISK', 'public'),
        'artist_photo' => [
            'max_kilobytes' => (int) env('RIFF_ARTIST_PHOTO_MAX_KB', 4096),
            'max_edge_pixels' => (int) env('RIFF_ARTIST_PHOTO_MAX_EDGE', 1200),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Distance
    |--------------------------------------------------------------------------
    |
    | Listeners see distances in their own unit. Registration guesses from the
    | stated country -- only a handful of countries use miles -- and the
    | profile page lets anyone change it.
    |
    */

    'distance' => [
        'default_unit' => env('RIFF_DEFAULT_DISTANCE_UNIT', 'mi'),
        'imperial_countries' => [
            'us', 'usa', 'united states', 'united states of america',
            'uk', 'gb', 'united kingdom', 'great britain', 'england',
            'scotland', 'wales', 'northern ireland',
            'lr', 'liberia', 'mm', 'myanmar', 'burma',
        ],
    ],

];
