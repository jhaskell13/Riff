<?php

namespace App\Http\Requests\Map\Concerns;

use App\DTO\MapPoint;
use App\Support\Geo;
use Illuminate\Validation\Validator;

/**
 * The "where" half of every map query, shared by the artists, shows and
 * venues endpoints so the three can never disagree about what a viewport
 * or a radius means: a viewport (south/north/west/east), a radius around a
 * point (lat/lng/radius), and the unit distances come back in.
 */
trait ParsesMapArea
{
    /**
     * @return array<string, mixed>
     */
    protected function areaRules(): array
    {
        $bounds = 'required_with:south,north,west,east';

        return [
            'south' => ['nullable', 'numeric', 'between:-90,90', $bounds],
            'north' => ['nullable', 'numeric', 'between:-90,90', $bounds, 'gte:south'],
            'west' => ['nullable', 'numeric', 'between:-180,180', $bounds],
            'east' => ['nullable', 'numeric', 'between:-180,180', $bounds, 'gte:west'],

            // Distance origin; also the radius center.
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng,radius'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat,radius'],
            'radius' => ['nullable', 'numeric', 'gt:0'],
            'unit' => ['nullable', 'in:mi,km'],
        ];
    }

    protected function checkRadius(Validator $validator): void
    {
        if (! $this->filled('radius')) {
            return;
        }

        $max = Geo::fromKm(config('riff.map.max_radius_km'), $this->unit());

        if ((float) $this->input('radius') > $max) {
            $validator->errors()->add('radius', 'The radius may not be more than '.floor($max).' '.$this->unit().'.');
        }
    }

    /**
     * The viewport, snapped outward so nearby requests share a cache key.
     *
     * @return array{south: float, west: float, north: float, east: float}|null
     */
    protected function snappedBounds(): ?array
    {
        if (! $this->filled('south')) {
            return null;
        }

        return Geo::snapBounds([
            'south' => (float) $this->input('south'),
            'west' => (float) $this->input('west'),
            'north' => (float) $this->input('north'),
            'east' => (float) $this->input('east'),
        ]);
    }

    protected function radiusKm(): ?float
    {
        return $this->filled('radius') ? Geo::toKm((float) $this->input('radius'), $this->unit()) : null;
    }

    public function unit(): string
    {
        return $this->input('unit')
            ?? $this->user()?->distance_unit
            ?? config('riff.distance.default_unit');
    }

    /**
     * Where distances are measured from: the explicit point, else the
     * viewport center, else the viewer's own area, else the default.
     *
     * Rounded to three decimals (~100 m). Riff only knows towns, so more
     * precision means nothing; it would also leak a browser's exact
     * geolocation into logs and make every request a distinct cache key.
     *
     * @param  array{south: float, west: float, north: float, east: float}|null  $bounds
     */
    protected function origin(?array $bounds): MapPoint
    {
        [$lat, $lng] = match (true) {
            $this->filled('lat') => [(float) $this->input('lat'), (float) $this->input('lng')],
            $bounds !== null => [($bounds['south'] + $bounds['north']) / 2, ($bounds['west'] + $bounds['east']) / 2],
            $this->user()?->lat !== null => [$this->user()->lat, $this->user()->lng],
            default => [config('riff.map.default_center.lat'), config('riff.map.default_center.lng')],
        };

        return new MapPoint(round($lat, 3), round($lng, 3));
    }
}
