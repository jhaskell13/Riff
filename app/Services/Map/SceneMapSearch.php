<?php

namespace App\Services\Map;

use App\DTO\SceneFilters;
use App\Http\Resources\ListingMarkerResource;
use App\Http\Resources\ShowMarkerResource;
use App\Http\Resources\VenueMarkerResource;
use App\Models\LineupEntry;
use App\Models\Listing;
use App\Models\Show;
use App\Models\Venue;
use App\Support\Geo;
use App\Support\MapCache;
use App\Support\ShowWindow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Answers "what's on, and where?" for the scene map: shows and venues in an
 * area, alongside ArtistMapSearch's artists.
 *
 * Neither needs the artists' server-side grid clustering. Shows are bounded
 * by their date window (a month of shows is a small set even for a busy
 * region) and venues are few, so each returns a capped, nearest-first list
 * that the browser clusters. The `truncated` flag says when the cap bit.
 *
 * - Venues run off venues_map_index (show_on_map, deleted_at, lat, lng),
 *   the same covering shape as artists.
 * - Shows are driven by the starts_at index — the window is the selective
 *   part — and placed at their venue (a venue show) or at their own
 *   town-level point (a one-off), via coalesce over a left join. A show at
 *   a taken-down venue has no point and leaves the map.
 *
 * Cached under MapCache::version() like artists; Show, Venue, and
 * LineupEntry changes bump it, as does GeocodeAddress.
 */
class SceneMapSearch
{
    /*
     * PDO binds PHP floats as strings. Against a plain column SQLite
     * converts them (the column has numeric affinity); against an
     * expression like these it does not, and a number never equals or
     * exceeds text — so every comparison with an expression multiplies
     * the bound value by 1.0, which is numeric on all three drivers.
     */
    private const SHOW_LAT = 'coalesce(venues.lat, shows.lat)';

    private const SHOW_LNG = 'coalesce(venues.lng, shows.lng)';

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function shows(SceneFilters $filters): array
    {
        return $this->cached($filters, 'shows', fn () => $this->runShows($filters));
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function venues(SceneFilters $filters): array
    {
        return $this->cached($filters, 'venues', fn () => $this->runVenues($filters));
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listings(SceneFilters $filters): array
    {
        return $this->cached($filters, 'listings', fn () => $this->runListings($filters));
    }

    /**
     * @param  \Closure(): array{data: list<array<string, mixed>>, meta: array<string, mixed>}  $run
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function cached(SceneFilters $filters, string $kind, \Closure $run): array
    {
        $key = $filters->cacheKey($kind);
        $ttl = (int) config('riff.map.cache_ttl_seconds');

        if ($key === null || $ttl <= 0) {
            return $run();
        }

        return Cache::remember('riff.map:'.MapCache::version().':'.$key, $ttl, $run);
    }

    // ---- Shows ------------------------------------------------------------------

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function runShows(SceneFilters $filters): array
    {
        $area = Geo::confine($filters->bounds, $filters->origin->lat, $filters->origin->lng, $filters->radiusKm);

        if ($area === false || $area === null) {
            return $this->respond($filters, [], false, null);
        }

        [$distance, $bindings] = $this->distanceSquared($filters, self::SHOW_LAT, self::SHOW_LNG);

        $query = Show::query()
            ->listed()
            ->leftJoin('venues', fn ($join) => $join
                ->on('venues.id', '=', 'shows.venue_id')
                ->whereNull('venues.deleted_at'))
            // Plain strings: an immutable Carbon binds as ISO-8601 here, which
            // SQLite compares as text against "Y-m-d H:i:s" and never matches.
            ->whereBetween('shows.starts_at', [$filters->window->from->toDateTimeString(), $filters->window->to->toDateTimeString()])
            ->whereRaw(self::SHOW_LAT.' between (? * 1.0) and (? * 1.0)', [$area['south'], $area['north']])
            ->whereRaw(self::SHOW_LNG.' between (? * 1.0) and (? * 1.0)', [$area['west'], $area['east']]);

        if ($filters->radiusKm !== null) {
            $degrees = $filters->radiusKm / Geo::KM_PER_DEGREE;
            $query->whereRaw("{$distance} <= (? * 1.0)", [...$bindings, $degrees * $degrees]);
        }

        if ($filters->genreIds !== []) {
            // A bill with at least one confirmed act in the chosen genres.
            $query->whereExists(fn (QueryBuilder $entries) => $this->confirmedEntries($entries)
                ->whereIn('lineup_entries.artist_id', fn (QueryBuilder $pivot) => $pivot
                    ->select('artist_id')
                    ->from('artist_genre')
                    ->whereIn('genre_id', $filters->genreIds)));
        }

        if ($filters->hiddenFor !== null) {
            // Hidden only when *every* Riff artist on the bill is blocked:
            // keep the show if any confirmed Riff act is not. A shared bill
            // is the other bands' show too.
            // (A bill with no Riff acts at all -- an imported listing --
            // has nobody to block, so it stays.)
            $query->where(fn (Builder $keep) => $keep
                ->whereExists(fn (QueryBuilder $entries) => $this->confirmedEntries($entries)
                    ->whereNotNull('lineup_entries.artist_id')
                    ->whereNotIn('lineup_entries.artist_id', fn (QueryBuilder $blocks) => $blocks
                        ->select('artist_id')
                        ->from('artist_blocks')
                        ->where('user_id', $filters->hiddenFor)))
                ->orWhereNotExists(fn (QueryBuilder $entries) => $this->confirmedEntries($entries)
                    ->whereNotNull('lineup_entries.artist_id')));
        }

        $rows = $query
            ->select('shows.*')
            ->selectRaw(self::SHOW_LAT.' as map_lat, '.self::SHOW_LNG.' as map_lng')
            ->with(['confirmedLineup.artist:id,name', 'venue:id,name,slug,city,state'])
            ->orderBy('shows.starts_at')
            ->orderByRaw($distance, $bindings)
            ->orderBy('shows.id')
            ->limit($filters->limit + 1)
            ->get();

        $truncated = $rows->count() > $filters->limit;

        return $this->respond(
            $filters,
            $this->withDistances(ShowMarkerResource::collection($rows->take($filters->limit))->resolve(), $filters),
            $truncated,
            $area,
        );
    }

    /**
     * Confirmed lineup entries of the outer show row.
     */
    private function confirmedEntries(QueryBuilder $entries): QueryBuilder
    {
        return $entries
            ->select(DB::raw(1))
            ->from('lineup_entries')
            ->whereColumn('lineup_entries.show_id', 'shows.id')
            ->where('lineup_entries.status', LineupEntry::CONFIRMED);
    }

    // ---- Venues -----------------------------------------------------------------

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function runVenues(SceneFilters $filters): array
    {
        $area = Geo::confine($filters->bounds, $filters->origin->lat, $filters->origin->lng, $filters->radiusKm);

        if ($area === false || $area === null) {
            return $this->respond($filters, [], false, null);
        }

        [$distance, $bindings] = $this->distanceSquared($filters, 'lat', 'lng');
        $stillOn = now()->subHours((int) config('riff.shows.still_on_hours'));

        $query = Venue::query()
            ->where('show_on_map', true)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->whereBetween('lat', [$area['south'], $area['north']])
            ->whereBetween('lng', [$area['west'], $area['east']]);

        if ($filters->radiusKm !== null) {
            $degrees = $filters->radiusKm / Geo::KM_PER_DEGREE;
            $query->whereRaw("{$distance} <= (? * 1.0)", [...$bindings, $degrees * $degrees]);
        }

        if ($filters->genreIds !== []) {
            // "Punk venues" means places with a punk bill coming up — the
            // genre lives on the acts, not the room.
            $query->whereHas('shows', fn (Builder $shows) => $shows
                ->listed()
                ->notOver()
                ->whereHas('confirmedLineup', fn (Builder $entries) => $entries
                    ->whereIn('artist_id', fn (QueryBuilder $pivot) => $pivot
                        ->select('artist_id')
                        ->from('artist_genre')
                        ->whereIn('genre_id', $filters->genreIds))));
        } else {
            // Dormant venues fade: nothing listed in the last six months
            // and nothing coming up. A newly added venue gets the same
            // grace period, so it isn't invisible before its first show.
            $since = now()->subDays((int) config('riff.map.venue_dormant_days'));

            $query->where(fn (Builder $active) => $active
                ->where('created_at', '>=', $since)
                ->orWhereHas('shows', fn (Builder $shows) => $shows
                    ->whereNull('cancelled_at')
                    ->where('starts_at', '>=', $since)));
        }

        $rows = $query
            ->withCount(['shows as upcoming_count' => fn (Builder $shows) => $shows
                // scopes(): larastan can't see a local scope through withCount's
                // untyped builder.
                ->scopes('listed')
                ->where('starts_at', '>=', $stillOn)])
            ->orderByRaw($distance, $bindings)
            ->orderBy('id')
            ->limit($filters->limit + 1)
            ->get();

        $truncated = $rows->count() > $filters->limit;

        return $this->respond(
            $filters,
            $this->withDistances(VenueMarkerResource::collection($rows->take($filters->limit))->resolve(), $filters),
            $truncated,
            $area,
        );
    }

    // ---- Listings ---------------------------------------------------------------

    /**
     * Active listings in the area, nearest first (then newest), like
     * venues. All town-level points.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function runListings(SceneFilters $filters): array
    {
        $area = Geo::confine($filters->bounds, $filters->origin->lat, $filters->origin->lng, $filters->radiusKm);

        if ($area === false || $area === null) {
            return $this->respond($filters, [], false, null);
        }

        [$distance, $bindings] = $this->distanceSquared($filters, 'lat', 'lng');

        $query = Listing::query()
            ->active()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->whereBetween('lat', [$area['south'], $area['north']])
            ->whereBetween('lng', [$area['west'], $area['east']]);

        if ($filters->radiusKm !== null) {
            $degrees = $filters->radiusKm / Geo::KM_PER_DEGREE;
            $query->whereRaw("{$distance} <= (? * 1.0)", [...$bindings, $degrees * $degrees]);
        }

        if ($filters->genreIds !== []) {
            $query->whereIn('id', fn (QueryBuilder $pivot) => $pivot
                ->select('listing_id')
                ->from('listing_genre')
                ->whereIn('genre_id', $filters->genreIds));
        }

        if ($filters->hiddenFor !== null) {
            // A blocked artist's listings leave the blocker's map; a
            // person's listing has no artist to block.
            $query->where(fn (Builder $q) => $q
                ->whereNull('artist_id')
                ->orWhereNotIn('artist_id', fn (QueryBuilder $blocks) => $blocks
                    ->select('artist_id')
                    ->from('artist_blocks')
                    ->where('user_id', $filters->hiddenFor)));
        }

        $rows = $query
            ->with('artist:id,name')
            ->orderByRaw($distance, $bindings)
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->limit($filters->limit + 1)
            ->get();

        $truncated = $rows->count() > $filters->limit;

        return $this->respond(
            $filters,
            $this->withDistances(ListingMarkerResource::collection($rows->take($filters->limit))->resolve(), $filters),
            $truncated,
            $area,
        );
    }

    // ---- Shared -----------------------------------------------------------------

    /**
     * Squared equirectangular distance from the origin over the given
     * coordinate expressions; see ArtistMapSearch::distanceSquared().
     *
     * @return array{0: string, 1: list<float>}
     */
    private function distanceSquared(SceneFilters $filters, string $lat, string $lng): array
    {
        $oLat = $filters->origin->lat;
        $oLng = $filters->origin->lng;
        $scale = Geo::longitudeScale($oLat);

        return [
            "(({$lat} - ?) * ({$lat} - ?) + (({$lng} - ?) * ?) * (({$lng} - ?) * ?))",
            [$oLat, $oLat, $oLng, $scale, $oLng, $scale],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $markers
     * @return list<array<string, mixed>>
     */
    private function withDistances(array $markers, SceneFilters $filters): array
    {
        foreach ($markers as &$marker) {
            $km = Geo::haversineKm($filters->origin->lat, $filters->origin->lng, $marker['lat'], $marker['lng']);
            $marker['distance'] = round(Geo::fromKm($km, $filters->unit), 1);
        }

        return $markers;
    }

    /**
     * @param  list<array<string, mixed>>  $markers
     * @param  array{south: float, west: float, north: float, east: float}|null  $area
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function respond(SceneFilters $filters, array $markers, bool $truncated, ?array $area): array
    {
        return [
            'data' => $markers,
            'meta' => [
                'returned' => count($markers),
                'truncated' => $truncated,
                'unit' => $filters->unit,
                'origin' => $filters->origin->toArray(),
                'bounds' => $area,
                'window' => [
                    'name' => $filters->window->name,
                    'label' => ShowWindow::OPTIONS[$filters->window->name],
                ],
            ],
        ];
    }
}
