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

];
