<?php

namespace App\Http\Requests\Map;

use App\DTO\MapFilters;
use App\Http\Requests\Map\Concerns\ParsesMapArea;
use App\Support\GenreCatalog;
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
    use ParsesMapArea;

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
        return [
            ...$this->areaRules(),

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

                $this->checkRadius($validator);

                if ($this->boolean('followed') && $this->user() === null) {
                    $validator->errors()->add('followed', 'Sign in to see the artists you follow.');
                }
            },
        ];
    }

    public function filters(): MapFilters
    {
        $bounds = $this->snappedBounds();

        $genres = $this->input('genres', []);
        $types = $this->input('types', []);
        sort($genres);
        sort($types);

        return new MapFilters(
            bounds: $bounds,
            origin: $this->origin($bounds),
            radiusKm: $this->radiusKm(),
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
}
