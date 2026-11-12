<?php

namespace App\Http\Requests\Map;

use App\DTO\SceneFilters;
use App\Http\Requests\Map\Concerns\ParsesMapArea;
use App\Support\GenreCatalog;
use App\Support\ShowWindow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Query parameters for GET /map/shows, /map/venues and /map/listings. Always an area
 * (viewport or radius) — unlike artists there is no global name search;
 * these pins follow the map. `when` and `tz` matter only for shows; `genres`
 * narrows shows to bills with a matching artist, and venues to places
 * hosting such a show.
 */
class MapSceneRequest extends FormRequest
{
    use ParsesMapArea;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->query('genres'))) {
            $this->merge([
                'genres' => array_values(array_filter(array_map('trim', explode(',', $this->query('genres'))), fn ($item) => $item !== '')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            ...$this->areaRules(),
            'genres' => ['nullable', 'array', 'max:10'],
            'genres.*' => ['string', 'distinct', Rule::in(GenreCatalog::slugs())],
            'when' => ['nullable', Rule::in(array_keys(ShowWindow::OPTIONS))],
            'tz' => ['nullable', 'string', 'timezone:all'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! $this->filled('south') && ! $this->filled('radius')) {
                    $validator->errors()->add('south', 'Give a map area or a radius.');
                }

                $this->checkRadius($validator);
            },
        ];
    }

    public function filters(): SceneFilters
    {
        $bounds = $this->snappedBounds();
        $genres = $this->input('genres', []);
        sort($genres);

        return new SceneFilters(
            bounds: $bounds,
            origin: $this->origin($bounds),
            radiusKm: $this->radiusKm(),
            unit: $this->unit(),
            window: ShowWindow::make(
                $this->input('when', ShowWindow::DEFAULT),
                $this->input('tz', config('riff.map.default_timezone')),
            ),
            genreIds: GenreCatalog::idsForSlugs($genres),
            limit: (int) config('riff.map.max_scene_markers'),
            hiddenFor: $this->user()?->hasBlocks() ? $this->user()->id : null,
        );
    }
}
