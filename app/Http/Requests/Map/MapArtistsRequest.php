<?php

namespace App\Http\Requests\Map;

use App\DTO\MapFilters;
use App\DTO\MapPoint;
use App\Support\GenreCatalog;
use App\Support\Geo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Query parameters for GET /map/artists. Every parameter is optional except
 * that a request must say *where*: a viewport (south/north/west/east), a
 * radius around a point (lat/lng/radius), or a search term (q). Any
 * combination narrows further.
 *
 * List filters accept either repeated keys (genres[]=punk&genres[]=folk) or
 * a comma list (genres=punk,folk); the latter keeps shareable map URLs
 * short.
 */
class MapArtistsRequest extends FormRequest
{
    private const LIST_FIELDS = ['genres', 'types'];

    /**
     * The map is public; nothing here requires an account except the
     * "followed" filter, enforced in after().
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $lists = [];

        foreach (self::LIST_FIELDS as $field) {
            $value = $this->query($field);

            if (is_string($value)) {
                $lists[$field] = array_values(array_filter(array_map('trim', explode(',', $value)), fn ($item) => $item !== ''));
            }
        }

        if (is_string($this->query('q'))) {
            $lists['q'] = trim($this->query('q'));
        }

        $this->merge($lists);
    }

    public function rules(): array
    {
        $bounds = ['south', 'north', 'west', 'east'];

        return [
            'south' => ['nullable', 'numeric', 'between:-90,90', 'required_with:'.implode(',', $bounds)],
            'north' => ['nullable', 'numeric', 'between:-90,90', 'required_with:'.implode(',', $bounds), 'gte:south'],
            'west' => ['nullable', 'numeric', 'between:-180,180', 'required_with:'.implode(',', $bounds)],
            'east' => ['nullable', 'numeric', 'between:-180,180', 'required_with:'.implode(',', $bounds), 'gte:west'],

            // Distance origin; also the radius center.
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng,radius'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat,radius'],
            'radius' => ['nullable', 'numeric', 'gt:0'],
            'unit' => ['nullable', Rule::in(['mi', 'km'])],

            'q' => ['nullable', 'string', 'min:2', 'max:100'],
            'genres' => ['nullable', 'array', 'max:10'],
            'genres.*' => ['string', 'distinct', Rule::in(GenreCatalog::slugs())],
            'types' => ['nullable', 'array', 'max:10'],
            'types.*' => ['string', 'distinct', Rule::in(array_keys(config('riff.artist.types')))],
            'has_music' => ['nullable', 'boolean'],
            'has_photo' => ['nullable', 'boolean'],
            'followed' => ['nullable', 'boolean'],

            'sort' => ['nullable', Rule::in(MapFilters::SORTS)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.config('riff.map.max_markers')],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! $this->filled('south') && ! $this->filled('radius') && ! $this->filled('q')) {
                    $validator->errors()->add('south', 'Give a map area, a radius, or a search term.');
                }

                if ($this->filled('radius')) {
                    $max = Geo::fromKm(config('riff.map.max_radius_km'), $this->unit());

                    if ((float) $this->input('radius') > $max) {
                        $validator->errors()->add('radius', 'The radius may not be more than '.floor($max).' '.$this->unit().'.');
                    }
                }

                if ($this->boolean('followed') && $this->user() === null) {
                    $validator->errors()->add('followed', 'Sign in to see the artists you follow.');
                }
            },
        ];
    }

    public function filters(): MapFilters
    {
        $bounds = null;

        if ($this->filled('south')) {
            $bounds = Geo::snapBounds([
                'south' => (float) $this->input('south'),
                'west' => (float) $this->input('west'),
                'north' => (float) $this->input('north'),
                'east' => (float) $this->input('east'),
            ]);
        }

        $genres = $this->input('genres', []);
        $types = $this->input('types', []);
        sort($genres);
        sort($types);

        return new MapFilters(
            bounds: $bounds,
            origin: $this->origin($bounds),
            radiusKm: $this->filled('radius') ? Geo::toKm((float) $this->input('radius'), $this->unit()) : null,
            unit: $this->unit(),
            genreIds: GenreCatalog::idsForSlugs($genres),
            types: $types,
            query: $this->filled('q') ? mb_strtolower($this->input('q')) : null,
            hasMusic: $this->boolean('has_music'),
            hasPhoto: $this->boolean('has_photo'),
            followedBy: $this->boolean('followed') ? $this->user()?->id : null,
            sort: $this->input('sort', 'distance'),
            limit: (int) $this->input('limit', config('riff.map.max_markers')),
            // Only a viewer who actually blocks someone loses the shared
            // cache; that is nearly nobody.
            hiddenFor: $this->user()?->hasBlocks() ? $this->user()->id : null,
        );
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
    private function origin(?array $bounds): MapPoint
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
