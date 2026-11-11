<?php

namespace App\Support;

use App\Contracts\HasPublicSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Builds the public URL slug for any HasPublicSlug model.
 *
 * Three shapes are never produced:
 * - the empty slug: a name that romanises to nothing gets the model's
 *   fallback suffix ("artist", the artist type, "venue"…);
 * - the all-digit slug, which a numeric /{id} redirect route would shadow;
 * - a reserved word that a sibling route already owns (/artists/create).
 */
class Slugs
{
    /**
     * Path segments that resource routes claim next to {model:slug}.
     */
    public const RESERVED = ['create', 'edit', 'new'];

    public static function generate(string $name, Model&HasPublicSlug $for): string
    {
        $base = Str::slug($name);

        if ($base === '' || ctype_digit($base) || in_array($base, self::RESERVED, true)) {
            $suffix = Str::slug($for->slugFallback()) ?: 'riff';
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
     * Taken when another record holds the slug now (soft-deleted included,
     * so restoring never finds its URL given away) or held it in the past
     * and still owns the redirect.
     */
    private static function taken(string $slug, Model&HasPublicSlug $for): bool
    {
        $current = $for->newQueryWithoutScopes()
            ->where('slug', $slug)
            ->when($for->exists, fn ($query) => $query->whereKeyNot($for->getKey()))
            ->exists();

        if ($current) {
            return true;
        }

        $history = $for->slugHistory();

        return $history->getRelated()->newQuery()
            ->where('slug', $slug)
            ->when($for->exists, fn ($query) => $query->where($history->getForeignKeyName(), '!=', $for->getKey()))
            ->exists();
    }
}
