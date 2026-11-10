<?php

namespace App\Services\Map;

use App\DTO\MapFilters;
use App\Http\Resources\ArtistMarkerResource;
use App\Models\Artist;
use App\Support\GenreCatalog;
use App\Support\Geo;
use App\Support\MapCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Answers "which artists are here?" for the map and its list view.
 *
 * Cost is bounded by design, not by hoping areas stay sparse:
 *
 *  1. A probe for row cap+1 decides the response shape. It stops after cap
 *     rows instead of counting the whole area.
 *  2. Up to the marker cap, every matching artist is returned, nearest
 *     first.
 *  3. Over the cap, the map gets server-side grid clusters (one GROUP BY,
 *     which also yields the total) and the list gets only the nearest
 *     artists, found by an expanding circle rather than by sorting every
 *     row in the area. A zoomed-out view never hydrates hundreds of models.
 *
 * All of it runs off the covering artists_map_index, so counting and
 * clustering an area never read table rows. Measured on 50,000 artists in
 * southern New England (uncached, SQLite): neighbourhood ~10 ms, whole
 * region ~40 ms, constant payload in cluster mode.
 *
 * Non-personal results are cached under the normalised filter set and a
 * version token that any artist change rotates (MapCache).
 */
class ArtistMapSearch
{
    private const MARKER_COLUMNS = [
        'id', 'name', 'artist_type', 'hometown', 'stateOrProvince',
        'lat', 'lng', 'image_path', 'music_provider',
    ];

    /**
     * @return array{data: list<array<string, mixed>>, clusters: list<array<string, mixed>>|null, meta: array<string, mixed>}
     */
    public function search(MapFilters $filters): array
    {
        $key = $filters->cacheKey();
        $ttl = (int) config('riff.map.cache_ttl_seconds');

        if ($key === null || $ttl <= 0) {
            return $this->run($filters);
        }

        return Cache::remember('riff.map:'.MapCache::version().':'.$key, $ttl, fn () => $this->run($filters));
    }

    /**
     * @return array{data: list<array<string, mixed>>, clusters: list<array<string, mixed>>|null, meta: array<string, mixed>}
     */
    private function run(MapFilters $filters): array
    {
        $area = $this->area($filters);
        $searchOnly = $filters->isSearchOnly();

        // Bounds and radius that do not overlap describe nowhere.
        if ($area === false) {
            return $this->respond($filters, null, [], null, 0, 'markers');
        }

        $cap = $searchOnly
            ? min($filters->limit, (int) config('riff.map.search_limit'))
            : $filters->limit;

        $clusters = null;

        if ($searchOnly) {
            // A substring search cannot use an index, so every pass is a
            // scan. Fetching one row past the cap answers "is there more?"
            // in the same pass; only an overflowing search pays for a count.
            $artists = $this->rows($this->sorted($this->matching($filters, null), $filters), $cap + 1);
            $overflows = $artists->count() > $cap;
            $artists = $artists->take($cap);
            $total = $overflows ? $this->matching($filters, null)->count() : $artists->count();
        } elseif ($this->exceeds($filters, $area, $cap)) {
            $clusters = $this->clusters($filters, $area);
            $total = array_sum(array_column($clusters, 'count'));
            $artists = $this->nearest($filters, $area, min((int) config('riff.map.list_limit'), $cap));
        } else {
            $artists = $this->rows($this->sorted($this->matching($filters, $area), $filters), $cap);
            $total = $artists->count();
        }

        $clustered = $clusters !== null;

        $markers = ArtistMarkerResource::collection($artists)->resolve();

        foreach ($markers as &$marker) {
            $km = Geo::haversineKm($filters->origin->lat, $filters->origin->lng, $marker['lat'], $marker['lng']);
            $marker['distance'] = round(Geo::fromKm($km, $filters->unit), 1);
        }
        unset($marker);

        return $this->respond(
            $filters,
            $area,
            $markers,
            $clusters,
            $total,
            match (true) {
                $searchOnly => 'search',
                $clustered => 'clusters',
                default => 'markers',
            },
        );
    }

    /**
     * The rectangle the query is confined to: the viewport, the radius's
     * bounding box, or their overlap. Null means unconfined (search only);
     * false means the two do not overlap.
     *
     * @return array{south: float, west: float, north: float, east: float}|null|false
     */
    private function area(MapFilters $filters): array|null|false
    {
        $radiusBox = $filters->radiusKm !== null
            ? Geo::boundingBox($filters->origin->lat, $filters->origin->lng, $filters->radiusKm)
            : null;

        return match (true) {
            $filters->bounds !== null && $radiusBox !== null => Geo::intersect($filters->bounds, $radiusBox) ?? false,
            $filters->bounds !== null => $filters->bounds,
            default => $radiusBox,
        };
    }

    /**
     * Whether more than $cap artists match. Reads at most cap+1 index
     * entries, where COUNT(*) would read every one in the area.
     *
     * @param  array{south: float, west: float, north: float, east: float}|null  $area
     */
    private function exceeds(MapFilters $filters, ?array $area, int $cap): bool
    {
        return $this->matching($filters, $area)
            ->toBase()
            ->select('id')
            ->offset($cap)
            ->limit(1)
            ->exists();
    }

    /**
     * The $take artists nearest the origin within the area, without
     * sorting every row in it.
     *
     * Search a circle around the origin, doubling it until it holds at
     * least $take artists. That answer is exact, not approximate: anything
     * outside the circle is farther away than everything inside it. In a
     * dense area the first, small circle usually suffices; if the origin
     * sits in an empty stretch, a few cheap retries precede a full sort.
     *
     * @param  array{south: float, west: float, north: float, east: float}  $area
     * @return Collection<int, Artist>
     */
    private function nearest(MapFilters $filters, array $area, int $take)
    {
        $scale = Geo::longitudeScale($filters->origin->lat);
        // Area size in latitude degrees, comparable to the circle radius.
        $span = max($area['north'] - $area['south'], ($area['east'] - $area['west']) * $scale);
        $radius = $span / 16;

        for ($attempt = 0; $attempt < 5; $attempt++, $radius *= 2) {
            $box = Geo::intersect($area, Geo::boundingBox(
                $filters->origin->lat,
                $filters->origin->lng,
                $radius * Geo::KM_PER_DEGREE,
            ));

            if ($box === null) {
                continue;
            }

            $found = $this->rows($this->sorted($this->matching($filters, $box, $radius), $filters), $take);

            if ($found->count() >= $take) {
                return $found;
            }
        }

        return $this->rows($this->sorted($this->matching($filters, $area), $filters), $take);
    }

    /**
     * @param  Builder<Artist>  $query
     * @return Collection<int, Artist>
     */
    private function rows(Builder $query, int $limit)
    {
        return $query->select(self::MARKER_COLUMNS)->with('genres:id,name')->limit($limit)->get();
    }

    /**
     * Every filter, as a fresh query. Order and columns are left to callers
     * so the same filters serve the count, the rows, and the clusters.
     *
     * $withinDegrees adds a circle around the origin (radius in latitude
     * degrees), used by nearest().
     *
     * @param  array{south: float, west: float, north: float, east: float}|null  $area
     * @return Builder<Artist>
     */
    private function matching(MapFilters $filters, ?array $area, ?float $withinDegrees = null): Builder
    {
        $query = Artist::query()->geolocated()->visibleOnMap();

        if ($area !== null) {
            // Index-friendly rectangle first; the exact radius test below
            // then only examines rows inside it.
            $query->withinBounds($area['south'], $area['west'], $area['north'], $area['east']);
        }

        foreach ([$filters->radiusKm !== null ? $filters->radiusKm / Geo::KM_PER_DEGREE : null, $withinDegrees] as $degrees) {
            if ($degrees !== null) {
                [$sql, $bindings] = $this->distanceSquared($filters);
                $query->whereRaw("{$sql} <= ?", [...$bindings, $degrees * $degrees]);
            }
        }

        if ($filters->types !== []) {
            $query->whereIn('artist_type', $filters->types);
        }

        if ($filters->genreIds !== []) {
            // Any of the chosen genres. A subquery on the pivot's covering
            // index, rather than whereHas, keeps the genres table out of it.
            $query->whereIn('id', fn ($pivot) => $pivot
                ->select('artist_id')
                ->from('artist_genre')
                ->whereIn('genre_id', $filters->genreIds));
        }

        if ($filters->hasMusic) {
            $query->whereNotNull('music_provider');
        }

        if ($filters->hasPhoto) {
            $query->whereNotNull('image_path');
        }

        if ($filters->followedBy !== null) {
            $query->whereIn('id', fn ($follows) => $follows
                ->select('artist_id')
                ->from('artist_follows')
                ->where('user_id', $filters->followedBy));
        }

        if ($filters->hiddenFor !== null) {
            // Blocked artists leave every shape of the result -- markers,
            // nearest list, and cluster counts alike.
            $query->whereNotIn('id', fn ($blocks) => $blocks
                ->select('artist_id')
                ->from('artist_blocks')
                ->where('user_id', $filters->hiddenFor));
        }

        if ($filters->query !== null) {
            $this->applySearch($query, $filters->query);
        }

        return $query;
    }

    /**
     * Name, hometown, or genre. Wildcards in the term are escaped, so a
     * search for "%" matches a literal percent sign rather than everything.
     *
     * The text match and the genre match are combined with UNION, not OR.
     * Under OR the database re-checks the genre list for every row of the
     * substring scan; as a union, the genre half is a single index lookup
     * on the pivot. Measured: ~150 ms -> ~20 ms on 50,000 artists.
     * Visibility and soft-delete scopes stay on the outer query.
     */
    private function applySearch(Builder $query, string $term): void
    {
        $like = '%'.self::escapeLike($term).'%';
        $genreIds = GenreCatalog::idsMatching($term);

        $matches = DB::table('artists')
            ->select('id')
            ->where(fn ($text) => $text
                ->whereRaw("lower(name) like ? escape '!'", [$like])
                ->orWhereRaw("lower(hometown) like ? escape '!'", [$like]));

        if ($genreIds !== []) {
            $matches->union(DB::table('artist_genre')->select('artist_id')->whereIn('genre_id', $genreIds));
        }

        $query->whereIn('id', $matches);
    }

    private function sorted(Builder $query, MapFilters $filters): Builder
    {
        if ($filters->sort === 'name') {
            return $query->orderBy('name')->orderBy('id');
        }

        if ($filters->sort === 'newest') {
            return $query->orderByDesc('created_at')->orderByDesc('id');
        }

        // A search ranks names that start with the term first, then by
        // distance; "the moss" should put The Moss above Hollow Moss.
        if ($filters->query !== null) {
            $query->orderByRaw(
                "case when lower(name) like ? escape '!' then 0 else 1 end",
                [self::escapeLike($filters->query).'%'],
            );
        }

        [$sql, $bindings] = $this->distanceSquared($filters);

        return $query->orderByRaw($sql, $bindings)->orderBy('id');
    }

    /**
     * Squared equirectangular distance from the origin, in squared degrees
     * of latitude. Arithmetic only, so it runs anywhere and can be ordered
     * by; the longitude scale is computed once here rather than per row.
     *
     * @return array{0: string, 1: list<float>}
     */
    private function distanceSquared(MapFilters $filters): array
    {
        $lat = $filters->origin->lat;
        $lng = $filters->origin->lng;
        $scale = Geo::longitudeScale($lat);

        return [
            '((lat - ?) * (lat - ?) + ((lng - ?) * ?) * ((lng - ?) * ?))',
            [$lat, $lat, $lng, $scale, $lng, $scale],
        ];
    }

    /**
     * Aggregate matching artists into a grid over the area. Each cluster
     * carries its count, its centroid, and the extent of the artists in it,
     * so the client can zoom straight to a cluster when it is clicked.
     *
     * @param  array{south: float, west: float, north: float, east: float}  $area
     * @return list<array<string, mixed>>
     */
    private function clusters(MapFilters $filters, array $area): array
    {
        $grid = max(1, (int) config('riff.map.cluster_grid'));
        $cellLat = max(($area['north'] - $area['south']) / $grid, 1e-6);
        $cellLng = max(($area['east'] - $area['west']) / $grid, 1e-6);

        $rows = $this->matching($filters, $area)
            ->toBase()
            ->selectRaw(
                $this->cell('lat').' as cell_y, '.$this->cell('lng').' as cell_x, '
                .'count(*) as artist_count, avg(lat) as avg_lat, avg(lng) as avg_lng, '
                .'min(lat) as min_lat, max(lat) as max_lat, min(lng) as min_lng, max(lng) as max_lng',
                [$area['south'], $cellLat, $area['west'], $cellLng],
            )
            ->groupBy('cell_y', 'cell_x')
            ->get();

        return $rows->map(fn ($row) => [
            'lat' => round((float) $row->avg_lat, 5),
            'lng' => round((float) $row->avg_lng, 5),
            'count' => (int) $row->artist_count,
            'bounds' => [
                'south' => (float) $row->min_lat,
                'west' => (float) $row->min_lng,
                'north' => (float) $row->max_lat,
                'east' => (float) $row->max_lng,
            ],
        ])->values()->all();
    }

    /**
     * Grid cell index for a coordinate. SQLite's CAST truncates, which is
     * floor here because the area's south/west edge is subtracted first;
     * everywhere else a CAST rounds, so FLOOR is needed.
     */
    private function cell(string $column): string
    {
        $expression = "(({$column} - ?) / ?)";

        return DB::connection()->getDriverName() === 'sqlite'
            ? "cast({$expression} as integer)"
            : "floor({$expression})";
    }

    /**
     * @param  array{south: float, west: float, north: float, east: float}|null  $area
     * @param  list<array<string, mixed>>  $markers
     * @param  list<array<string, mixed>>|null  $clusters
     * @return array{data: list<array<string, mixed>>, clusters: list<array<string, mixed>>|null, meta: array<string, mixed>}
     */
    private function respond(MapFilters $filters, ?array $area, array $markers, ?array $clusters, int $total, string $mode): array
    {
        return [
            'data' => $markers,
            'clusters' => $clusters,
            'meta' => [
                'mode' => $mode,
                'total' => $total,
                'returned' => count($markers),
                'truncated' => $total > count($markers),
                'unit' => $filters->unit,
                'origin' => $filters->origin->toArray(),
                // The area actually searched, after snapping, so the client
                // knows which markers it may keep when the viewport moves.
                'bounds' => $area,
            ],
        ];
    }

    public static function escapeLike(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
    }
}
