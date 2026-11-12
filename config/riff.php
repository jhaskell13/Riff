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
        // Shows and venues per response. Shows are bounded by their date
        // window and venues are few, so these are browser-clustered from a
        // capped list rather than grid-clustered on the server.
        'max_scene_markers' => (int) env('RIFF_MAP_MAX_SCENE_MARKERS', 300),
        // Used for the shows' "when" window when the browser sends no
        // timezone. The pilot is in Connecticut.
        'default_timezone' => env('RIFF_MAP_DEFAULT_TIMEZONE', 'America/New_York'),
        // A venue with no show in this many days (and not newly added)
        // drops off the map; its page stays.
        'venue_dormant_days' => (int) env('RIFF_MAP_VENUE_DORMANT_DAYS', 180),
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
    | Venues
    |--------------------------------------------------------------------------
    |
    | A venue is an established place that hosts music *regularly*: a club,
    | a bar with live music, a small hall, a record store with in-stores, a
    | named DIY space with a running calendar. One-off locations (a backyard,
    | a park, a house party) are not venues — they belong to the show itself,
    | so nothing lingers on the map after the night is over.
    |
    | Address visibility is two states, not three: a "public" venue gives a
    | street address and is pinned at it; a "private" venue gives no street
    | at all and is pinned at its town. Riff cannot leak an address it never
    | stored. DIY spaces default to private.
    |
    */

    'venues' => [
        'kinds' => [
            'club' => 'Club / music venue',
            'bar' => 'Bar with live music',
            'hall' => 'Hall / small theater',
            'record_store' => 'Record store',
            'cafe' => 'Café / coffee house',
            'diy' => 'DIY space',
            'other' => 'Other',
        ],
        'private_by_default' => ['diy'],
        // Community-submitted, so a spam target: a few per minute and a
        // daily ceiling per account.
        'rate_limit_per_minute' => (int) env('RIFF_VENUE_RATE_LIMIT', 3),
        'rate_limit_per_day' => (int) env('RIFF_VENUE_DAILY_LIMIT', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Age policies
    |--------------------------------------------------------------------------
    |
    | Shared by venues and shows: a show inherits its venue's policy unless
    | it states its own. All-ages is first-class information in a scene app.
    |
    */

    'age_policies' => [
        'all_ages' => 'All ages',
        '18_plus' => '18+',
        '21_plus' => '21+',
        'varies' => 'Varies by show',
    ],

    /*
    |--------------------------------------------------------------------------
    | Shows
    |--------------------------------------------------------------------------
    |
    | A show is listed by the members of its host artist. Other Riff artists
    | on the bill must confirm before they appear (see LineupEntry). A show
    | counts as "upcoming" until still_on_hours after it starts, so tonight's
    | show doesn't vanish from listings the moment the doors open.
    |
    */

    'shows' => [
        'max_lineup' => (int) env('RIFF_SHOW_MAX_LINEUP', 12),
        'max_days_ahead' => (int) env('RIFF_SHOW_MAX_DAYS_AHEAD', 365),
        'still_on_hours' => (int) env('RIFF_SHOW_STILL_ON_HOURS', 4),
        'rate_limit_per_minute' => (int) env('RIFF_SHOW_RATE_LIMIT', 5),
        'rate_limit_per_day' => (int) env('RIFF_SHOW_DAILY_LIMIT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Listings ("looking for")
    |--------------------------------------------------------------------------
    |
    | Structured, expiring posts: a band seeking a drummer, a bassist
    | seeking a band, an act after an opener or a gig, a practice room.
    | `as_artist` says whether a type is posted as one of the poster's
    | artists: required, never (a person, not a band), or optional.
    |
    | Replies are relayed by email for now (ListingReplyMail): the poster's
    | address is never shown, and the replier shares only the contact they
    | type. An in-app messaging system will replace the relay (Phase 4);
    | replies are stored so threads can move over.
    |
    */

    'listings' => [
        'types' => [
            'members_wanted' => ['label' => 'Band seeking members', 'as_artist' => 'required', 'icon' => 'group_add'],
            'band_wanted' => ['label' => 'Musician seeking a band', 'as_artist' => 'never', 'icon' => 'person_search'],
            'bill_wanted' => ['label' => 'Looking for gigs or openers', 'as_artist' => 'required', 'icon' => 'event_available'],
            'practice_space' => ['label' => 'Practice space', 'as_artist' => 'optional', 'icon' => 'meeting_room'],
        ],
        'instruments' => [
            'vocals' => 'Vocals', 'guitar' => 'Guitar', 'bass' => 'Bass', 'drums' => 'Drums',
            'keys' => 'Keys', 'synth' => 'Synth', 'horns' => 'Horns', 'strings' => 'Strings',
            'dj' => 'DJ / turntables', 'production' => 'Production', 'other' => 'Other',
        ],
        // A listing runs this long, and can be renewed (for the same again)
        // once it is within renew_window_days of ending, or after it has.
        'days' => (int) env('RIFF_LISTING_DAYS', 30),
        'renew_window_days' => 7,
        'max_body' => 1000,
        'rate_limit_per_minute' => (int) env('RIFF_LISTING_RATE_LIMIT', 3),
        'rate_limit_per_day' => (int) env('RIFF_LISTING_DAILY_LIMIT', 10),
        // Replies are mail to a real person: the tightest limits after
        // reports.
        'reply_rate_limit_per_minute' => (int) env('RIFF_LISTING_REPLY_RATE_LIMIT', 3),
        'reply_rate_limit_per_day' => (int) env('RIFF_LISTING_REPLY_DAILY_LIMIT', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scenes
    |--------------------------------------------------------------------------
    |
    | A scene is a place plus a genre family: /scenes/hartford-ct/punk. Scenes
    | aren't stored; a place is (see Place and riff:sync-places) and a family
    | is this list. The radius is a circle around the place's centre rather
    | than its town line, because scenes cross town lines.
    |
    | Families are named by curated genre *names* (config riff.genres). A
    | genre may sit in more than one family; every curated genre should sit
    | in at least one (a test enforces it).
    |
    */

    'scenes' => [
        'radius_km' => (float) env('RIFF_SCENE_RADIUS_KM', 40),
        'families' => [
            'punk' => ['label' => 'Punk', 'genres' => ['Punk', 'Hardcore', 'Emo', 'Ska']],
            'indie' => ['label' => 'Indie & alternative', 'genres' => ['Indie', 'Alternative', 'Shoegaze', 'Post-Rock']],
            'rock' => ['label' => 'Rock & metal', 'genres' => ['Rock', 'Metal']],
            'hip-hop' => ['label' => 'Hip-hop & R&B', 'genres' => ['Hip-Hop', 'R&B']],
            'electronic' => ['label' => 'Electronic', 'genres' => ['Electronic', 'House', 'Techno', 'Ambient', 'Experimental']],
            'folk' => ['label' => 'Folk & roots', 'genres' => ['Folk', 'Americana', 'Country', 'Bluegrass', 'Singer-Songwriter']],
            'jazz' => ['label' => 'Jazz, soul & funk', 'genres' => ['Jazz', 'Blues', 'Soul', 'Funk', 'Gospel']],
            'global' => ['label' => 'Latin, reggae & global', 'genres' => ['Latin', 'Reggae', 'Afrobeat', 'Ska']],
            'pop' => ['label' => 'Pop', 'genres' => ['Pop']],
            'classical' => ['label' => 'Classical', 'genres' => ['Classical']],
        ],
        // What a scene page lists, at most.
        'max_shows' => 20,
        'max_artists' => 24,
        'max_venues' => 12,
        'max_listings' => 12,
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
    | Support links
    |--------------------------------------------------------------------------
    |
    | Where an artist already gets paid. Riff points at these and handles no
    | money itself (ROADMAP principle 4), so every one of them is a link-out:
    | nothing here is ever embedded, and SupportLink refuses anything that is
    | not a plain http(s) link to a real host.
    |
    | `hosts` is an allowlist for the types where carrying somewhere
    | unrelated would be a scam rather than a mistake: a "Bandcamp" button
    | that goes anywhere else, or a tip jar pointed at a stranger's wallet.
    | An empty list means any valid link, because a band's own shop lives on
    | its own domain and no list could anticipate it.
    |
    | The keys are persisted in artist_support_links.type, so renaming one
    | needs a migration.
    |
    */

    'support' => [
        'types' => [
            'bandcamp' => [
                'label' => 'Bandcamp',
                'icon' => 'album',
                'hosts' => ['bandcamp.com'],
                'hint' => 'Your Bandcamp page, album, or track.',
            ],
            'store' => [
                'label' => 'Your store',
                'icon' => 'storefront',
                'hosts' => [],
                'hint' => 'Where you sell your music directly.',
            ],
            'merch' => [
                'label' => 'Merch',
                'icon' => 'checkroom',
                'hosts' => [],
                'hint' => 'Shirts, records, tapes.',
            ],
            'tickets' => [
                'label' => 'Tickets',
                'icon' => 'confirmation_number',
                'hosts' => [],
                'hint' => 'A tour page or ticket seller. Individual shows have their own ticket links.',
            ],
            'tip_jar' => [
                'label' => 'Tip jar',
                'icon' => 'volunteer_activism',
                'hosts' => [
                    'ko-fi.com', 'patreon.com', 'buymeacoffee.com', 'liberapay.com',
                    'paypal.com', 'paypal.me', 'venmo.com', 'cash.app', 'gofundme.com',
                ],
                'hint' => 'Ko-fi, Patreon, PayPal, Venmo, Cash App, and the like.',
            ],
            'other' => [
                'label' => 'Something else',
                'icon' => 'link',
                'hosts' => [],
                'hint' => 'Anywhere else people can support you.',
            ],
        ],
        // Enough for a store, a Bandcamp, merch, tickets and a tip jar
        // without the section becoming a link farm.
        'max_links' => (int) env('RIFF_SUPPORT_MAX_LINKS', 6),
        'max_label' => 40,
        // Outbound clicks are a bare counter, no per-user rows: the
        // question is "do support links get clicked" (the Phase 4 payments
        // decision gate), never who clicked.
        'click_rate_limit_per_minute' => (int) env('RIFF_SUPPORT_CLICK_RATE_LIMIT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fans
    |--------------------------------------------------------------------------
    |
    | Following is the fan relationship (artist_follows). Artist pages show
    | how many fans an artist has and how many are local -- as counts only.
    | No individual fan is ever named, placed, or listed.
    |
    | A local fan is one whose own stored town centre is within local_radius_km
    | of the artist. Below local_floor the local count renders as "< 5": an
    | exact small number next to a small town is close to naming someone.
    |
    */

    'fans' => [
        'local_radius_km' => (float) env('RIFF_FAN_LOCAL_RADIUS_KM', 40),
        'local_floor' => (int) env('RIFF_FAN_LOCAL_FLOOR', 5),
        'count_cache_seconds' => (int) env('RIFF_FAN_COUNT_CACHE_TTL', 300),
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
