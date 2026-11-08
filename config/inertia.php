<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Page Components
    |--------------------------------------------------------------------------
    |
    | Inertia v3 defaults to resources/js/pages (lowercase). Riff predates that
    | convention and keeps its components in resources/js/Pages, which matters
    | on case-sensitive filesystems (Linux CI) when the testing helpers verify
    | that a rendered page component actually exists.
    |
    */

    'pages' => [

        'ensure_pages_exist' => false,

        'paths' => [

            resource_path('js/Pages'),

        ],

        'extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],

    ],

];
