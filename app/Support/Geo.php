<?php

namespace App\Support;

/**
 * Distance and area math for the map.
 *
 * Two distance models are used on purpose:
 *
 *  - haversineKm() for the distance a listener reads, where accuracy is
 *    what matters and it runs on at most a few hundred rows in PHP.
 *
 *  - an equirectangular approximation (see ArtistMapSearch) inside SQL for
 *    radius filtering and nearest-first ordering. It needs only arithmetic,
 *    so it runs on SQLite, MySQL and Postgres alike without trig support,
 *    and at local-discovery radii it is accurate to well under 1%.
 *
 * Viewports crossing the antimeridian are not supported, matching
 * Artist::scopeWithinBounds().
 */
final class Geo
{
    public const EARTH_RADIUS_KM = 6371.0088;

    /** Kilometres per degree of latitude (and of longitude at the equator). */
    public const KM_PER_DEGREE = 111.32;

    public const KM_PER_MILE = 1.609344;

    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }

    public static function toKm(float $value, string $unit): float
    {
        return $unit === 'mi' ? $value * self::KM_PER_MILE : $value;
    }

    public static function fromKm(float $km, string $unit): float
    {
        return $unit === 'mi' ? $km / self::KM_PER_MILE : $km;
    }

    /**
     * Longitude scale at a latitude: how much shorter a degree of longitude
     * is than a degree of latitude there. Floored so the poles cannot
     * divide by zero.
     */
    public static function longitudeScale(float $lat): float
    {
        return max(cos(deg2rad($lat)), 0.01);
    }

    /**
     * The smallest box containing a circle, used as an index-friendly
     * prefilter before the exact radius test.
     *
     * @return array{south: float, west: float, north: float, east: float}
     */
    public static function boundingBox(float $lat, float $lng, float $radiusKm): array
    {
        $dLat = $radiusKm / self::KM_PER_DEGREE;
        $dLng = $radiusKm / (self::KM_PER_DEGREE * self::longitudeScale($lat));

        return [
            'south' => max(-90.0, $lat - $dLat),
            'west' => max(-180.0, $lng - $dLng),
            'north' => min(90.0, $lat + $dLat),
            'east' => min(180.0, $lng + $dLng),
        ];
    }

    /**
     * Expand bounds outward to a grid scaled to their size, so nearby
     * viewports share a cache entry. A pan of a few pixels then reuses the
     * previous result instead of re-querying; the slightly larger area
     * also preloads markers just off screen.
     *
     * @param  array{south: float, west: float, north: float, east: float}  $bounds
     * @return array{south: float, west: float, north: float, east: float}
     */
    public static function snapBounds(array $bounds): array
    {
        $span = max($bounds['north'] - $bounds['south'], $bounds['east'] - $bounds['west'], 1e-6);
        // One "nice" step per quarter of the span: 0.1 for a 0.5 deg view,
        // 1 for a 5 deg view, and so on.
        $step = 10 ** floor(log10($span / 4));

        return [
            'south' => max(-90.0, round(floor($bounds['south'] / $step) * $step, 6)),
            'west' => max(-180.0, round(floor($bounds['west'] / $step) * $step, 6)),
            'north' => min(90.0, round(ceil($bounds['north'] / $step) * $step, 6)),
            'east' => min(180.0, round(ceil($bounds['east'] / $step) * $step, 6)),
        ];
    }

    /**
     * @param  array{south: float, west: float, north: float, east: float}  $a
     * @param  array{south: float, west: float, north: float, east: float}  $b
     * @return array{south: float, west: float, north: float, east: float}|null null when they do not overlap
     */
    public static function intersect(array $a, array $b): ?array
    {
        $result = [
            'south' => max($a['south'], $b['south']),
            'west' => max($a['west'], $b['west']),
            'north' => min($a['north'], $b['north']),
            'east' => min($a['east'], $b['east']),
        ];

        return ($result['south'] > $result['north'] || $result['west'] > $result['east']) ? null : $result;
    }
}
