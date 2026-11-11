<?php

namespace App\Support;

use App\Models\Genre;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The genre vocabulary, held in cache. Every map request validates genre
 * slugs and resolves them to ids; doing that against a cached list keeps
 * the genres table out of the hot path entirely. The Genre model flushes
 * this on any change, and a short TTL covers writes that bypass model
 * events (migrations, raw inserts).
 */
final class GenreCatalog
{
    private const KEY = 'riff.genres.catalog';

    private const TTL_SECONDS = 600;

    /**
     * @return list<array{id: int, slug: string, name: string, is_curated: bool}>
     */
    public static function all(): array
    {
        return Cache::remember(self::KEY, self::TTL_SECONDS, fn () => Genre::query()
            ->orderBy('name')
            ->get(['id', 'slug', 'name', 'is_curated'])
            ->map(fn (Genre $genre) => [
                'id' => $genre->id,
                'slug' => $genre->slug,
                'name' => $genre->name,
                'is_curated' => $genre->is_curated,
            ])
            ->all());
    }

    /**
     * @return list<array{slug: string, name: string}>
     */
    public static function curatedOptions(): array
    {
        return array_values(array_map(
            fn (array $genre) => ['slug' => $genre['slug'], 'name' => $genre['name']],
            array_filter(self::all(), fn (array $genre) => $genre['is_curated']),
        ));
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_column(self::all(), 'slug');
    }

    /**
     * @param  list<string>  $slugs
     * @return list<int>
     */
    public static function idsForSlugs(array $slugs): array
    {
        $bySlug = array_column(self::all(), 'id', 'slug');

        return array_values(array_map(fn (string $slug) => $bySlug[$slug], array_filter($slugs, fn ($slug) => isset($bySlug[$slug]))));
    }

    /**
     * Genres whose name contains a search term, so searching "punk" finds
     * punk artists whose names say nothing about it.
     *
     * @return list<int>
     */
    public static function idsMatching(string $term): array
    {
        $term = Str::lower($term);

        return array_values(array_map(
            fn (array $genre) => $genre['id'],
            array_filter(self::all(), fn (array $genre) => str_contains(Str::lower($genre['name']), $term)),
        ));
    }

    public static function flush(): void
    {
        Cache::forget(self::KEY);
    }
}
