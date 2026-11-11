<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Map
    |--------------------------------------------------------------------------
    |
    | default_center is used when the viewer has no geocoded location yet.
    |
    | max_markers is the most individual artists one response may carry. When
    | an area holds more, the API switches to server-side clusters (a grid of
    | cluster_grid x cluster_grid cells) plus the list_limit nearest artists,
    | so a zoomed-out view costs one aggregate query instead of hundreds of
    | hydrated rows. search_limit bounds a name search with no area.
    |
    | cache_ttl_seconds caches non-personal results; any artist change
    | invalidates them immediately regardless. 0 disables caching.
    |
    */

    'map' => [
        'default_center' => [
            'lat' => (float) env('RIFF_MAP_DEFAULT_LAT', 41.7637111),
            'lng' => (float) env('RIFF_MAP_DEFAULT_LNG', -72.6850932),
        ],
        'max_markers' => (int) env('RIFF_MAP_MAX_MARKERS', 500),
        'list_limit' => (int) env('RIFF_MAP_LIST_LIMIT', 100),
        'search_limit' => (int) env('RIFF_MAP_SEARCH_LIMIT', 50),
        'cluster_grid' => (int) env('RIFF_MAP_CLUSTER_GRID', 16),
        'max_radius_km' => (float) env('RIFF_MAP_MAX_RADIUS_KM', 400),
        // Offered in the viewer's own unit.
        'radius_options' => [5, 10, 25, 50, 100],
        'cache_ttl_seconds' => (int) env('RIFF_MAP_CACHE_TTL', 60),
        'rate_limit_per_minute' => (int) env('RIFF_MAP_RATE_LIMIT', 180),
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
            'max_kilobytes' => (int) env('RIFF_ARTIST_PHOTO_MAX_KB', 8192),
            // Largest stored edge; uploads are scaled down to fit.
            'max_edge_pixels' => (int) env('RIFF_ARTIST_PHOTO_MAX_EDGE', 1200),
            // Largest accepted source edge. Decoding costs ~4 bytes per
            // pixel, so this bounds memory before any decode happens.
            'max_source_edge_pixels' => (int) env('RIFF_ARTIST_PHOTO_MAX_SOURCE_EDGE', 6000),
            'jpeg_quality' => 85,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Moderation
    |--------------------------------------------------------------------------
    |
    | reasons are what a report may claim. The keys are persisted in
    | reports.reason, so renaming one needs a migration; the labels are what
    | the report form offers, and the order here is the order shown.
    |
    | Reporting is deliberately cheap for the reporter and bounded by the
    | "reports" limiter, since a flood of reports is itself an abuse vector.
    |
    */

    'moderation' => [
        'reasons' => [
            'spam' => 'Spam or a scam',
            'impersonation' => 'Pretending to be someone else',
            'hate' => 'Hateful or abusive content',
            'sexual' => 'Sexual or explicit content',
            'violence' => 'Violence or threats',
            'infringement' => "Someone else's music, photos, or name",
            'not_music' => 'Not a music artist',
            'wrong_location' => 'In the wrong place on the map',
            'other' => 'Something else',
        ],
        'max_details' => (int) env('RIFF_REPORT_MAX_DETAILS', 1000),
        'rate_limit_per_minute' => (int) env('RIFF_REPORT_RATE_LIMIT', 5),
        'queue_page_size' => (int) env('RIFF_MODERATION_PAGE_SIZE', 20),
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
