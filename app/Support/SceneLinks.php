<?php

namespace App\Support;

use App\Models\Place;

/**
 * "Part of the scene" links for a town and some genres — how an artist or
 * venue page leads into its scene. Only for towns that have a scene page.
 */
final class SceneLinks
{
    /**
     * @param  list<int>  $genreIds
     * @return list<array{label: string, url: string}>
     */
    public static function for(string $city, ?string $region, array $genreIds = []): array
    {
        $place = Place::query()->where('slug', Place::slugFor($city, $region))->first();

        if ($place === null) {
            return [];
        }

        $byGenre = SceneFamilies::byGenreId();
        $families = array_values(array_unique(array_merge(...array_map(fn (int $id) => $byGenre[$id] ?? [], $genreIds) ?: [[]])));

        $links = array_map(fn (string $family) => [
            'label' => $place->name.' '.mb_strtolower(SceneFamilies::label($family)),
            'url' => route('scenes.show', [$place, $family]),
        ], $families);

        $links[] = ['label' => 'Everything in '.$place->name, 'url' => route('scenes.show', $place)];

        return $links;
    }
}
