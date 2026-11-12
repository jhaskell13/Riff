<?php

namespace App\Services;

use App\DTO\MapFilters;
use App\DTO\MapPoint;
use App\DTO\SceneFilters;
use App\Models\Artist;
use App\Models\Place;
use App\Models\User;
use App\Services\Map\ArtistMapSearch;
use App\Services\Map\SceneMapSearch;
use App\Support\Geo;
use App\Support\MapCache;
use App\Support\SceneFamilies;
use App\Support\ShowWindow;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * One scene: a place plus (optionally) a genre family — who plays, what's
 * on, and where — within a radius of the place's centre.
 *
 * Composed from the map's own searches, so a scene page and the map can
 * never disagree about what "punk", "blocked", "upcoming" or "dormant"
 * mean. The page is cached for everyone but a viewer who blocks someone,
 * under the same MapCache version the map uses.
 */
class SceneView
{
    public function __construct(
        private ArtistMapSearch $artists,
        private SceneMapSearch $scene,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Place $place, ?string $family, ?User $viewer): array
    {
        $unit = $viewer?->distance_unit ?? config('riff.distance.default_unit');
        $hiddenFor = $viewer?->hasBlocks() ? $viewer->id : null;

        if ($hiddenFor !== null) {
            return $this->compute($place, $family, $unit, $hiddenFor);
        }

        return Cache::remember(
            'riff.scene:'.MapCache::version().":{$place->slug}:".($family ?? '*').":{$unit}",
            max(1, (int) config('riff.map.cache_ttl_seconds')),
            fn () => $this->compute($place, $family, $unit, null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(Place $place, ?string $family, string $unit, ?int $hiddenFor): array
    {
        $origin = new MapPoint(round($place->lat, 3), round($place->lng, 3));
        $radiusKm = $place->radiusKm();
        $genreIds = $family !== null ? SceneFamilies::genreIds($family) : [];

        $artists = $this->artists->search(new MapFilters(
            bounds: null,
            origin: $origin,
            radiusKm: $radiusKm,
            unit: $unit,
            genreIds: $genreIds,
            limit: (int) config('riff.scenes.max_artists'),
            hiddenFor: $hiddenFor,
        ));

        $sceneFilters = fn (int $limit) => new SceneFilters(
            bounds: null,
            origin: $origin,
            radiusKm: $radiusKm,
            unit: $unit,
            // A scene page is about what's coming up: the next month, in
            // the pilot's timezone (the page has no viewer timezone).
            window: ShowWindow::make('month', (string) config('riff.map.default_timezone')),
            genreIds: $genreIds,
            limit: $limit,
            hiddenFor: $hiddenFor,
        );

        $shows = $this->scene->shows($sceneFilters((int) config('riff.scenes.max_shows')));
        $venues = $this->scene->venues($sceneFilters((int) config('riff.scenes.max_venues')));

        return [
            'place' => ['slug' => $place->slug, 'name' => $place->name, 'label' => $place->label],
            'family' => $family === null ? null : ['slug' => $family, 'label' => SceneFamilies::label($family)],
            'radius' => round(Geo::fromKm($radiusKm, $unit)).' '.$unit,
            'artists' => $artists['data'],
            'artistTotal' => $artists['meta']['total'],
            'shows' => $shows['data'],
            'showsTruncated' => $shows['meta']['truncated'],
            'venues' => $venues['data'],
            'families' => $this->familiesHere($place, $origin, $radiusKm, $hiddenFor),
            'mapUrl' => route('map.index', array_filter([
                'lat' => round($place->lat, 4),
                'lng' => round($place->lng, 4),
                'zoom' => 11,
                'genres' => $family !== null ? implode(',', SceneFamilies::genreSlugs($family)) : null,
            ])),
        ];
    }

    /**
     * Which families have artists here, and how many, so the page can
     * offer "Hartford punk · 6 · Hartford folk · 4". An artist in two
     * genres of one family counts once.
     *
     * Reads the radius's bounding box off the covering map index, then
     * applies the exact circle in PHP; a town-sized box is a small set.
     *
     * @return list<array{slug: string, label: string, count: int, url: string}>
     */
    private function familiesHere(Place $place, MapPoint $origin, float $radiusKm, ?int $hiddenFor): array
    {
        $box = Geo::boundingBox($origin->lat, $origin->lng, $radiusKm);

        $rows = DB::table('artist_genre')
            ->join('artists', 'artists.id', '=', 'artist_genre.artist_id')
            ->whereIn('artists.id', Artist::query()
                ->geolocated()
                ->visibleOnMap()
                ->withinBounds($box['south'], $box['west'], $box['north'], $box['east'])
                ->when($hiddenFor !== null, fn ($q) => $q->whereNotIn('id', fn ($blocks) => $blocks
                    ->select('artist_id')->from('artist_blocks')->where('user_id', $hiddenFor)))
                ->select('id'))
            ->get(['artist_genre.artist_id', 'artist_genre.genre_id', 'artists.lat', 'artists.lng']);

        $byGenre = SceneFamilies::byGenreId();
        $members = [];

        foreach ($rows as $row) {
            if (Geo::haversineKm($origin->lat, $origin->lng, (float) $row->lat, (float) $row->lng) > $radiusKm) {
                continue;
            }

            foreach ($byGenre[$row->genre_id] ?? [] as $family) {
                $members[$family][$row->artist_id] = true;
            }
        }

        $families = [];

        foreach (array_keys(SceneFamilies::all()) as $family) {
            if (! empty($members[$family])) {
                $families[] = [
                    'slug' => $family,
                    'label' => SceneFamilies::label($family),
                    'count' => count($members[$family]),
                    'url' => route('scenes.show', [$place, $family]),
                ];
            }
        }

        usort($families, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $families;
    }
}
