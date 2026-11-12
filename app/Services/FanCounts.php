<?php

namespace App\Services;

use App\Models\Artist;
use App\Support\Geo;
use Illuminate\Support\Facades\Cache;

/**
 * How many fans an artist has, and how many of them are local.
 *
 * Counts only, by design (ROADMAP principle 5): no fan is ever named,
 * placed, or listed, and nothing here can be asked "who". A listener's
 * location is not public, so the only thing it is ever used for is
 * incrementing a number.
 *
 * The local count is also *floored* for display -- see displayLocal(). An
 * exact "1 local fan" beside a small town, read next to the artist's own
 * town, narrows a listener down further than a count should.
 */
class FanCounts
{
    /**
     * Fans within the local radius of the artist's own point. Null when the
     * artist has no coordinates: with nothing to measure from, "local"
     * has no meaning, and 0 would read as "nobody nearby".
     */
    public function local(Artist $artist): ?int
    {
        if ($artist->lat === null || $artist->lng === null) {
            return null;
        }

        $ttl = (int) config('riff.fans.count_cache_seconds');

        $compute = fn () => $this->queryLocal($artist);

        return $ttl > 0
            ? Cache::remember($this->cacheKey($artist), $ttl, $compute)
            : $compute();
    }

    /**
     * What the page prints: an exact count at or above the floor, the
     * string "< 5" below it, and null when there is nothing to say.
     */
    public function displayLocal(Artist $artist): ?string
    {
        $count = $this->local($artist);

        if ($count === null || $count === 0) {
            return null;
        }

        $floor = (int) config('riff.fans.local_floor');

        return $count >= $floor ? (string) $count : '< '.$floor;
    }

    /** Drop the cached count; following and unfollowing change it. */
    public function forget(Artist $artist): void
    {
        Cache::forget($this->cacheKey($artist));
    }

    /**
     * Keyed by the artist's own point as well as its id: moving town
     * changes who is local, and the geocoder writes coordinates with
     * saveQuietly(), so no model event would tell us to drop this.
     */
    private function cacheKey(Artist $artist): string
    {
        $point = round((float) $artist->lat, 3).','.round((float) $artist->lng, 3);

        return "riff:fans:local:{$artist->id}:{$point}";
    }

    private function queryLocal(Artist $artist): int
    {
        $radiusKm = (float) config('riff.fans.local_radius_km');
        $lat = (float) $artist->lat;
        $lng = (float) $artist->lng;

        $box = Geo::boundingBox($lat, $lng, $radiusKm);
        $scale = Geo::longitudeScale($lat);

        return $artist->followers()
            // Index-friendly prefilter first: the exact circle below reads
            // an expression, which no index can serve.
            ->whereNotNull('users.lat')
            ->whereNotNull('users.lng')
            ->whereBetween('users.lat', [$box['south'], $box['north']])
            ->whereBetween('users.lng', [$box['west'], $box['east']])
            // The same arithmetic-only equirectangular distance the map
            // uses: no trig, so it runs identically on SQLite, MySQL and
            // Postgres. Compared as squared kilometres to avoid a sqrt.
            //
            // Every bound value here is multiplied by 1.0 on purpose. PDO
            // binds PHP floats as strings, and SQLite does not convert a
            // string compared against an *expression* (only against a
            // plain column) -- so without it the comparison silently
            // matches nothing. This cost a debugging session once already;
            // see CLAUDE.md.
            ->whereRaw(
                '(((users.lat - (? * 1.0)) * (? * 1.0)) * ((users.lat - (? * 1.0)) * (? * 1.0)))'
                .' + (((users.lng - (? * 1.0)) * (? * 1.0)) * ((users.lng - (? * 1.0)) * (? * 1.0)))'
                .' <= (? * 1.0)',
                [
                    $lat, Geo::KM_PER_DEGREE,
                    $lat, Geo::KM_PER_DEGREE,
                    $lng, Geo::KM_PER_DEGREE * $scale,
                    $lng, Geo::KM_PER_DEGREE * $scale,
                    $radiusKm ** 2,
                ]
            )
            ->count();
    }
}
