<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A model with a public, rename-safe URL slug. Implemented through the
 * App\Models\Concerns\HasHistoricalSlugs trait; the interface exists so
 * App\Support\Slugs can type what it is handed.
 */
interface HasPublicSlug
{
    /**
     * Slugs this record used before renames; each keeps an old URL working.
     * The related model's only fillable column is `slug`.
     */
    public function slugHistory(): HasMany;

    /**
     * What Slugs::generate() appends to an empty, all-digit, or reserved
     * slug.
     */
    public function slugFallback(): string;
}
