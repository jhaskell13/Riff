<?php

namespace App\Support;

use App\Models\Artist;
use App\Models\ArtistSlug;
use Illuminate\Support\Str;

/**
 * Builds the public URL slug for an artist. Links are permanent but slugs
 * follow the name: a rename generates a fresh slug and parks the old one in
 * artist_slugs, where it keeps redirecting (see Artist::resolveRouteBinding).
 *
 * Two shapes are never produced: the empty slug (a name that romanises to
 * nothing falls back to the artist type) and the all-digit slug, which the
 * numeric /artists/{id} redirect route would shadow.
 */
class ArtistSlugs
{
    public static function generate(string $name, ?Artist $for = null): string
    {
        $base = Str::slug($name);

        if ($base === '' || ctype_digit($base)) {
            $suffix = Str::slug((string) $for?->artist_type) ?: 'artist';
            $base = trim($base.'-'.$suffix, '-');
        }

        $slug = $base;
        $n = 2;

        while (self::taken($slug, $for)) {
            $slug = $base.'-'.$n;
            $n++;
        }

        return $slug;
    }

    /**
     * A slug is taken when another artist holds it now (archived included —
     * restoring must not find its URL given away) or held it in the past
     * and still owns the redirect.
     */
    private static function taken(string $slug, ?Artist $for): bool
    {
        $current = Artist::withTrashed()
            ->where('slug', $slug)
            ->when($for?->exists, fn ($query) => $query->whereKeyNot($for->getKey()))
            ->exists();

        if ($current) {
            return true;
        }

        return ArtistSlug::query()
            ->where('slug', $slug)
            ->when($for?->exists, fn ($query) => $query->where('artist_id', '!=', $for->getKey()))
            ->exists();
    }
}
