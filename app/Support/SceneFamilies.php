<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Genre families ("punk" = Punk, Hardcore, Emo, Ska), from
 * config('riff.scenes.families'), resolved against the cached genre
 * catalogue. A family is the "what" half of a scene; a Place is the "where".
 */
final class SceneFamilies
{
    /**
     * @return array<string, array{label: string, genres: list<string>}>
     */
    public static function all(): array
    {
        return (array) config('riff.scenes.families');
    }

    public static function exists(string $slug): bool
    {
        return isset(self::all()[$slug]);
    }

    public static function label(string $slug): string
    {
        return self::all()[$slug]['label'] ?? Str::headline($slug);
    }

    /** @return list<string> */
    public static function genreSlugs(string $slug): array
    {
        return array_map(fn (string $name) => Str::slug($name), self::all()[$slug]['genres'] ?? []);
    }

    /** @return list<int> */
    public static function genreIds(string $slug): array
    {
        return GenreCatalog::idsForSlugs(self::genreSlugs($slug));
    }

    /**
     * genre id => the families it belongs to.
     *
     * @return array<int, list<string>>
     */
    public static function byGenreId(): array
    {
        $map = [];

        foreach (array_keys(self::all()) as $family) {
            foreach (self::genreIds($family) as $id) {
                $map[$id][] = $family;
            }
        }

        return $map;
    }
}
